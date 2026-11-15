<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;

/**
 * warmup/schemas/sqlite/sqlite.sql against warmup/schemas/mysql/mysql.sql: the same tables,
 * columns, primary keys, unique keys, secondary indexes and cascading foreign keys (no full-text
 * index), on an in-memory database.
 */
final class SqliteSchemaTest extends TestCase
{
    private const MYSQL_SCHEMA = __DIR__ . '/../../../warmup/schemas/mysql/mysql.sql';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteTestDatabase::withSchema();
    }

    /**
     * The MySQL schema, parsed: per table, its columns (in order), primary key, unique and other
     * keys (column lists, prefix lengths dropped) and foreign keys (column => parent table and
     * constraint name).
     *
     * @return array<string, array{columns: list<string>, primary: list<string>, unique: list<list<string>>, keys: list<list<string>>, foreign: array<string, array{parent: string, name: string}>}>
     */
    private static function mysqlTables(): array
    {
        $sql = (string) file_get_contents(self::MYSQL_SCHEMA);
        preg_match_all('/CREATE TABLE IF NOT EXISTS `(\w+)` \((.*?)\n\)/s', $sql, $matches, PREG_SET_ORDER);
        $columnList = static fn (string $list): array => array_map(static fn (string $c): string => trim(preg_replace('/\(\d+\)/', '', $c) ?? '', " `"), explode(',', $list));
        $tables = [];
        foreach ($matches as [, $table, $body]) {
            $definition = ['columns' => [], 'primary' => [], 'unique' => [], 'keys' => [], 'foreign' => []];
            foreach (preg_split('/,\s*\n/', trim($body)) ?: [] as $line) {
                $line = trim($line);
                if (preg_match('/^PRIMARY KEY \((.*)\)$/', $line, $m) === 1) {
                    $definition['primary'] = $columnList($m[1]);
                } elseif (preg_match('/^UNIQUE KEY `\w+` \((.*)\)$/', $line, $m) === 1) {
                    $definition['unique'][] = $columnList($m[1]);
                } elseif (preg_match('/^KEY (?:`\w+` )?\((.*)\)$/', $line, $m) === 1) {
                    $definition['keys'][] = $columnList($m[1]);
                } elseif (preg_match('/^CONSTRAINT `(\w+)` FOREIGN KEY \(`(\w+)`\) REFERENCES `(\w+)` \(`\2`\) ON DELETE CASCADE ON UPDATE CASCADE$/', $line, $m) === 1) {
                    $definition['foreign'][$m[2]] = ['parent' => $m[3], 'name' => $m[1]];
                } elseif (preg_match('/^`(\w+)` /', $line, $m) === 1) {
                    $definition['columns'][] = $m[1];
                } elseif (!str_starts_with($line, 'FULLTEXT KEY')) {
                    self::fail('Unparsed line in the MySQL schema: ' . $line);
                }
            }
            $tables[$table] = $definition;
        }

        return $tables;
    }

    /**
     * @return array<string, array{unique: bool, columns: list<string>, origin: string}> the indexes of an SQLite table, by name
     */
    private function sqliteIndexes(string $table): array
    {
        $indexes = [];
        foreach ($this->connection->fetchAllAssociative('select * from pragma_index_list(?)', [$table]) as $index) {
            $columns = $this->connection->fetchFirstColumn('select name from pragma_index_info(?) order by seqno', [$index['name']]);
            $indexes[$index['name']] = ['unique' => (bool) $index['unique'], 'columns' => $columns, 'origin' => $index['origin']];
        }

        return $indexes;
    }

    public function testLoadingTheSchemaTwiceIsHarmless(): void
    {
        $this->connection->executeStatement("insert into entities (uuid, _id, owner, entity_subtype, created, contents) values ('u', 'a', '', 'S', '2023-06-14 00:00:00', '{}')");
        $this->connection->executeStatement("insert into entities_search (_id, search) values ('a', 'text')");

        SqliteTestDatabase::loadSchema($this->connection);

        $this->assertSame('1', (string) $this->connection->fetchOne('select count(*) from entities'));
        $this->assertSame('1', (string) $this->connection->fetchOne('select count(*) from entities_search'));
        $this->assertSame([['schema', '2020120301']], $this->connection->fetchAllNumeric('select label, value from versions'));
    }

    public function testEveryTableColumnKeyAndIndexOfTheMysqlSchemaExists(): void
    {
        $mysql = self::mysqlTables();
        $this->assertSame(
            ['site', 'site_search', 'site_metadata', 'config', 'config_search', 'config_metadata', 'entities', 'entities_search', 'entities_metadata', 'reader', 'reader_search', 'reader_metadata', 'versions', 'session'],
            array_keys($mysql),
            'the MySQL schema was parsed',
        );
        $sqliteTables = $this->connection->fetchFirstColumn("select name from sqlite_master where type = 'table' order by name");
        $mysqlTables = array_keys($mysql);
        sort($mysqlTables);
        $this->assertSame($mysqlTables, $sqliteTables, 'the same tables, and no others (no full-text tables)');

        foreach ($mysql as $table => $definition) {
            $info = $this->connection->fetchAllAssociative('select name, pk from pragma_table_info(?) order by cid', [$table]);
            $this->assertSame($definition['columns'], array_column($info, 'name'), $table . ': columns');
            $primary = array_column(array_filter($info, static fn (array $c): bool => (int) $c['pk'] > 0), 'name');
            $this->assertSame($definition['primary'], $primary, $table . ': primary key');

            $indexes = $this->sqliteIndexes($table);
            $secondary = array_filter($indexes, static fn (array $i): bool => $i['origin'] === 'c');
            foreach ([true => $definition['unique'], false => $definition['keys']] as $unique => $keys) {
                foreach ($keys as $columns) {
                    $found = array_filter($secondary, static fn (array $i): bool => $i['columns'] === $columns && $i['unique'] === (bool) $unique);
                    $this->assertCount(1, $found, $table . ': ' . ($unique ? 'unique ' : '') . 'index on ' . implode(', ', $columns));
                }
            }
            $this->assertCount(count($definition['unique']) + count($definition['keys']), $secondary, $table . ': no other indexes');

            $declared = [];
            foreach ($this->connection->fetchAllAssociative('select * from pragma_foreign_key_list(?)', [$table]) as $key) {
                $this->assertSame([$key['from'], 'CASCADE', 'CASCADE'], [$key['to'], $key['on_delete'], $key['on_update']], $table);
                $declared[$key['from']] = $key['table'];
            }
            foreach ($definition['foreign'] as $column => ['parent' => $parent, 'name' => $name]) {
                if (in_array([$column], $mysql[$parent]['unique'], true)) {
                    $this->assertSame($parent, $declared[$column] ?? null, $table . ': foreign key on ' . $column);
                } else {
                    // SQLite cannot declare a foreign key to a column that is not unique: triggers do its work.
                    $this->assertArrayNotHasKey($column, $declared, $table);
                    $triggers = $this->connection->fetchFirstColumn("select name from sqlite_master where type = 'trigger' and tbl_name in (?, ?) order by name", [$table, $parent]);
                    foreach ([$name . '_insert', $name . '_update', $parent . '_delete_cascade', $parent . '_update_cascade'] as $trigger) {
                        $this->assertContains($trigger, $triggers, $table);
                    }
                }
            }
            $this->assertCount(count($definition['foreign']), array_unique(array_merge(array_keys($declared), array_keys($definition['foreign']))), $table . ': no other foreign keys');
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function collections(): array
    {
        return ['entities' => ['entities'], 'reader' => ['reader'], 'site' => ['site'], 'config' => ['config']];
    }

    private function insertRecord(string $collection, string $uuid, string $id): void
    {
        $this->connection->executeStatement('insert into ' . $collection . " (uuid, _id, owner, entity_subtype, created, contents) values (?, ?, '', 'S', '2023-06-14 00:00:00', '{}')", [$uuid, $id]);
    }

    private function insertIndexRows(string $collection, string $id): void
    {
        $this->connection->executeStatement('insert into ' . $collection . "_search (_id, search) values (?, 'text')", [$id]);
        $this->connection->executeStatement('insert into ' . $collection . "_metadata (_id, name, value) values (?, 'slug', 'x'), (?, 'title', 'y')", [$id, $id]);
    }

    private function indexRowCount(string $collection, string $id): int
    {
        return (int) $this->connection->fetchOne('select count(*) from ' . $collection . '_search where _id = ?', [$id])
            + (int) $this->connection->fetchOne('select count(*) from ' . $collection . '_metadata where _id = ?', [$id]);
    }

    /**
     * @dataProvider collections
     */
    public function testDeletingARowRemovesItsMetadataAndSearchRows(string $collection): void
    {
        $this->insertRecord($collection, 'u-a', 'a');
        $this->insertRecord($collection, 'u-b', 'b');
        $this->insertIndexRows($collection, 'a');
        $this->insertIndexRows($collection, 'b');
        $this->assertSame(3, $this->indexRowCount($collection, 'a'));

        $this->connection->executeStatement('delete from ' . $collection . ' where _id = ?', ['a']);

        $this->assertSame(0, $this->indexRowCount($collection, 'a'));
        $this->assertSame(3, $this->indexRowCount($collection, 'b'), 'the other record keeps its rows');
    }

    /**
     * @dataProvider collections
     */
    public function testIndexRowsForAMissingRecordAreRefused(string $collection): void
    {
        $this->insertRecord($collection, 'u-a', 'a');

        foreach (['_search (_id, search) values (?, \'t\')', '_metadata (_id, name, value) values (?, \'n\', \'v\')'] as $insert) {
            try {
                $this->connection->executeStatement('insert into ' . $collection . $insert, ['missing']);
                $this->fail($collection . $insert . ' should be refused');
            } catch (ForeignKeyConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }
        // The parent key ignores case, as MySQL's utf8 collation does.
        $this->insertIndexRows($collection, 'A');
        $this->assertSame(3, $this->indexRowCount($collection, 'a'));
    }

    /**
     * site and config: `_id` is not unique there. Deleting either of two rows that share it
     * deletes the shared index rows, as InnoDB does (observed on MySQL 8).
     *
     * @return array<string, array{string}>
     */
    public static function collectionsWithoutUniqueIds(): array
    {
        return ['site' => ['site'], 'config' => ['config']];
    }

    /**
     * @dataProvider collectionsWithoutUniqueIds
     */
    public function testIdsAreNotUniqueInSiteAndConfigAndADeleteCascadesAsInnodbDoes(string $collection): void
    {
        $this->insertRecord($collection, 'u-1', 'a');
        $this->insertRecord($collection, 'u-2', 'a');
        $this->insertIndexRows($collection, 'a');

        $this->connection->executeStatement('delete from ' . $collection . ' where uuid = ?', ['u-1']);

        $this->assertSame(0, $this->indexRowCount($collection, 'a'));
        $this->assertSame('1', (string) $this->connection->fetchOne('select count(*) from ' . $collection . ' where _id = ?', ['a']));
    }

    /**
     * @dataProvider collections
     */
    public function testChangingAnIdCascadesToTheIndexRows(string $collection): void
    {
        $this->insertRecord($collection, 'u-a', 'a');
        $this->insertIndexRows($collection, 'a');

        $this->connection->executeStatement('update ' . $collection . " set _id = 'z' where _id = 'a'");

        $this->assertSame(0, $this->indexRowCount($collection, 'a'));
        $this->assertSame(3, $this->indexRowCount($collection, 'z'));
    }

    public function testUniqueKeysIgnoreCaseAsMysqlDoes(): void
    {
        $this->insertRecord('entities', 'http://site.test/view/a', 'a');

        foreach ([['HTTP://SITE.TEST/VIEW/A', 'b'], ['u-c', 'A']] as [$uuid, $id]) {
            try {
                $this->insertRecord('entities', $uuid, $id);
                $this->fail($uuid . ' ' . $id . ' should be a duplicate');
            } catch (UniqueConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }
        // site and config: only the uuid is unique.
        $this->insertRecord('site', 'u', 'a');
        $this->insertRecord('site', 'v', 'A');
        $this->assertSame('2', (string) $this->connection->fetchOne("select count(*) from site where _id = 'a'"));
    }

    public function testTextColumnsCompareAsTextFoldedToUpperCase(): void
    {
        $this->insertRecord('entities', 'u-a', 'a');
        foreach (['999', '1000', 'a_', 'aZ', 'Red'] as $value) {
            $this->connection->executeStatement("insert into entities_metadata (_id, name, value) values ('a', 'n', ?)", [$value]);
        }
        $values = fn (string $condition, string $operand): array => $this->connection->fetchFirstColumn('select value from entities_metadata where value ' . $condition . ' ? order by value', [$operand]);

        // No numeric affinity: '999' is after '1000'.
        $this->assertSame(['1000'], $values('<', '5'));
        $this->assertSame([], $values('<', '1000'));
        // Upper-case folding: '_' sorts after every letter; equality ignores case.
        $this->assertSame(['a_', 'Red'], $values('>', 'aZ'));
        $this->assertSame(['1000', '999', 'aZ'], $values('<', 'a_'));
        $this->assertSame(['Red'], $values('=', 'RED'));
        $this->assertSame(['1000', '999', 'aZ', 'a_', 'Red'], $values('<>', ''));
        // The column of `name` too, and of the main table's columns.
        $this->assertSame('1', (string) $this->connection->fetchOne("select count(*) from entities where entity_subtype = 's' and _id = 'A'"));
    }
}
