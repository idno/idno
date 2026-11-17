<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\DriverManager;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use PHPUnit\Framework\TestCase;

/**
 * The schema migrations on the rig's MySQL server (MySQL or MariaDB), in a private database of
 * their own (never the rig site's): the baseline's tables, columns and collations, and the schema
 * catalog's single statement. Skipped on an SQLite or MongoDB site.
 */
final class SchemaMigratorMySqlTest extends TestCase
{
    private const TABLES = [
        'idno_entities',
        'idno_migrations',
        'idno_reader',
        'idno_sessions',
        'idno_settings',
        'idno_site',
        'idno_upgrade_progress',
        'idno_upgrade_rejects',
    ];

    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $this->connection = PrivateDatabase::mysql('schema');
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            PrivateDatabase::drop($this->connection);
            $this->connection = null;
        }
    }

    private function connection(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }

    /**
     * @return array<string, array{type: string, collation: ?string}> per column of the table, in order: its
     *                                                              data type (no display width, which MariaDB reports) and collation
     */
    private function columns(string $table): array
    {
        $columns = [];
        foreach ($this->connection()->fetchAllAssociative(
            'select column_name as name, data_type as type, collation_name as collation from information_schema.columns where table_schema = database() and table_name = ? order by ordinal_position',
            [$table],
        ) as $row) {
            $columns[(string) $row['name']] = ['type' => strtolower((string) $row['type']), 'collation' => $row['collation'] === null ? null : (string) $row['collation']];
        }

        return $columns;
    }

    public function testMigrateCreatesEveryTable(): void
    {
        $catalog = new SchemaCatalog($this->connection());
        foreach (self::TABLES as $table) {
            $this->assertTrue($catalog->hasTable($table), $table);
        }
        $migrator = new SchemaMigrator($this->connection());
        $this->assertSame([], $migrator->pending());
        $this->assertSame('20261115000001', $migrator->latest());
    }

    public function testMigrateTwiceIsANoOp(): void
    {
        $this->connection()->insert('idno_settings', ['name' => 'kept', 'value' => '"yes"', 'position' => 0]);
        $tables = static fn (Connection $c): array => $c->fetchFirstColumn('select table_name from information_schema.tables where table_schema = database() order by table_name');
        $before = $tables($this->connection());

        (new SchemaMigrator($this->connection()))->migrate();
        (new SchemaMigrator($this->connection()))->migrate();

        $this->assertSame($before, $tables($this->connection()));
        $this->assertSame('"yes"', $this->connection()->fetchOne('select value from idno_settings where name = ?', ['kept']));
        $this->assertSame(2, (int) $this->connection()->fetchOne('select count(*) from idno_migrations'));
    }

    /**
     * A first run interrupted after some of its statements (a killed request): some tables exist,
     * the version is not recorded. Running it again completes the schema.
     */
    public function testAnInterruptedBaselineCanBeRunAgain(): void
    {
        $this->connection()->executeStatement('drop table idno_settings');
        $this->connection()->executeStatement('drop table idno_migrations');
        $migrator = new SchemaMigrator($this->connection());
        $this->assertSame(['20261115000000', '20261115000001'], $migrator->pending());

        $migrator->migrate();

        $catalog = new SchemaCatalog($this->connection());
        foreach (self::TABLES as $table) {
            $this->assertTrue($catalog->hasTable($table), $table);
        }
        $this->assertSame([], $migrator->pending());
        $this->assertSame('20261115000001', $migrator->latest());
    }

    public function testColumnsAndCollations(): void
    {
        foreach (['idno_entities', 'idno_reader', 'idno_site'] as $table) {
            $columns = $this->columns($table);
            $this->assertSame(['id', 'uuid', 'type', 'owner', 'created', 'publish_status', 'data', 'search', 'ix_access'], array_keys($columns), $table);
            $this->assertSame(['type' => 'longtext', 'collation' => 'utf8mb4_bin'], $columns['data'], $table);
            foreach (['id', 'uuid', 'type', 'owner', 'publish_status', 'search', 'ix_access'] as $column) {
                $this->assertSame('utf8mb4_unicode_520_ci', $columns[$column]['collation'], $table . '.' . $column);
            }
            $this->assertSame('bigint', $columns['created']['type'], $table);
            $this->assertSame('FULLTEXT', $this->connection()->fetchOne(
                'select index_type from information_schema.statistics where table_schema = database() and table_name = ? and column_name = ?',
                [$table, 'search'],
            ), $table);
        }
        $settings = $this->columns('idno_settings');
        $this->assertSame(['name', 'value', 'position'], array_keys($settings));
        $this->assertSame('utf8mb4_bin', $settings['name']['collation']);
        $this->assertSame('utf8mb4_bin', $settings['value']['collation']);
        $this->assertSame('int', $settings['position']['type']);

        $sessions = $this->columns('idno_sessions');
        $this->assertSame(['session_id', 'session_value', 'session_lifetime', 'session_time'], array_keys($sessions));
        $this->assertSame('mediumblob', $sessions['session_value']['type']);

        foreach (['idno_upgrade_progress', 'idno_upgrade_rejects'] as $table) {
            foreach ($this->columns($table) as $name => $column) {
                $this->assertContains($column['collation'], [null, 'utf8mb4_unicode_520_ci'], $table . '.' . $name);
            }
        }
        $this->assertSame('longblob', $this->columns('idno_upgrade_rejects')['contents']['type']);
        $this->assertSame(
            ['collection', 'source_rows', 'source_existed', 'copied', 'rejected', 'position', 'verify_cursor', 'error', 'error_detail', 'completed_at'],
            array_keys($this->columns('idno_upgrade_progress')),
        );
        $this->assertSame('varchar', $this->columns('idno_upgrade_progress')['verify_cursor']['type']);
        // The rejects are looked up by collection and exact uuid while the copy is verified.
        $this->assertSame(
            [['collection', 1], ['legacy_uuid', 2]],
            array_map(static fn (array $row): array => [(string) $row['c'], (int) $row['s']], $this->connection()->fetchAllAssociative(
                "select column_name as c, seq_in_index as s from information_schema.statistics where table_schema = database() and table_name = 'idno_upgrade_rejects' and index_name = 'collection_uuid' order by seq_in_index",
            )),
        );
    }

    /**
     * `access` is in every entity query, so the baseline indexes it itself: a STORED generated column
     * (no JSON is parsed to check a row found through another index) holding the whole value, and an
     * index on the type and it, which answers a count by types and access without reading a row. The
     * type comes first: with `access` first MariaDB's optimizer chooses this index and a filesort for a
     * listing page beyond the first few dozen, over reading `created` backwards.
     */
    public function testEveryCollectionTableHoldsAccessInAStoredColumnIndexedWithTheType(): void
    {
        foreach (['idno_entities', 'idno_reader', 'idno_site'] as $table) {
            $column = $this->connection()->fetchAssociative(
                'select data_type as data_type, character_maximum_length as length, collation_name as collation_name, extra as extra, is_nullable as nullable from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
                [$table, 'ix_access'],
            );
            $this->assertIsArray($column, $table);
            $this->assertSame('varchar', strtolower((string) $column['data_type']), $table);
            $this->assertSame(255, (int) $column['length'], $table);
            $this->assertSame('utf8mb4_unicode_520_ci', $column['collation_name'], $table);
            $this->assertStringContainsString('STORED', strtoupper((string) $column['extra']), $table);
            $this->assertSame('YES', $column['nullable'], $table);
            $this->assertSame(
                [['type', 1, null, 1], ['ix_access', 2, null, 1]],
                array_map(static fn (array $row): array => [(string) $row['c'], (int) $row['s'], $row['p'] === null ? null : (int) $row['p'], (int) $row['n']], $this->connection()->fetchAllAssociative(
                    "select column_name as c, seq_in_index as s, sub_part as p, non_unique as n from information_schema.statistics where table_schema = database() and table_name = ? and index_name = 'ix_access' order by seq_in_index",
                    [$table],
                )),
                $table,
            );
        }
        $catalog = new SchemaCatalog($this->connection());
        foreach (['entities', 'reader', 'site'] as $collection) {
            $this->assertSame(['access'], $catalog->indexedFields($collection), $collection);
        }
    }

    public function testCatalogReadsTablesWithOneStatement(): void
    {
        $counter = new class implements Middleware {
            public int $statements = 0;

            public function wrap(Driver $driver): Driver
            {
                $counter = $this;

                return new class ($driver, $counter) extends AbstractDriverMiddleware {
                    public function __construct(Driver $driver, private readonly object $counter)
                    {
                        parent::__construct($driver);
                    }

                    public function connect(#[\SensitiveParameter] array $params): DriverConnection
                    {
                        return new class (parent::connect($params), $this->counter) extends AbstractConnectionMiddleware {
                            public function __construct(DriverConnection $connection, private readonly object $counter)
                            {
                                parent::__construct($connection);
                            }

                            public function prepare(string $sql): Statement
                            {
                                $this->counter->statements++;

                                return parent::prepare($sql);
                            }

                            public function query(string $sql): Result
                            {
                                $this->counter->statements++;

                                return parent::query($sql);
                            }

                            public function exec(string $sql): int|string
                            {
                                $this->counter->statements++;

                                return parent::exec($sql);
                            }
                        };
                    }
                };
            }
        };
        $counted = DriverManager::getConnection($this->connection()->getParams(), (new Configuration())->setMiddlewares([$counter]));
        try {
            $counted->getNativeConnection();
            $counter->statements = 0;

            $catalog = new SchemaCatalog($counted);
            $this->assertContains('idno_entities', $catalog->tables());
            $this->assertSame(['access'], $catalog->indexedFields('entities'));
            $this->assertTrue($catalog->hasTable('idno_settings'));
            $this->assertFalse($catalog->hasTable('entities'));

            $this->assertSame(1, $counter->statements);
        } finally {
            $counted->close();
        }
    }

    public function testTheCatalogFindsIndexedFields(): void
    {
        $catalog = new SchemaCatalog($this->connection());
        $this->assertSame(['access'], $catalog->indexedFields('entities'), 'the schema\'s own');

        $this->connection()->executeStatement(
            "alter table idno_entities add column ix_slug varchar(255) generated always as (json_unquote(json_extract(data, '$.slug'))) virtual, add index ix_slug (ix_slug)",
        );
        $catalog->forget();

        $this->assertSame(['access', 'slug'], $catalog->indexedFields('entities'));
        $this->assertSame(['access'], $catalog->indexedFields('reader'));
    }
}
