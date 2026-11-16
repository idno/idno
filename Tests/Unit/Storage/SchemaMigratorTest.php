<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use PHPUnit\Framework\TestCase;
use Tests\Storage\PrivateDatabase;

/**
 * The schema migrations on SQLite: the baseline creates every table, running the migrations again
 * does nothing, the schema names no custom collation or function (so the database file opens with
 * standard SQLite tools), and the schema catalog reads what exists.
 */
final class SchemaMigratorTest extends TestCase
{
    private const BASELINE = '20261115000000';

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

    private ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
            $this->directory = null;
        }
    }

    private static function emptyDatabase(?string $path = null): Connection
    {
        $params = $path === null ? ['driver' => 'pdo_sqlite', 'memory' => true] : ['driver' => 'pdo_sqlite', 'path' => $path];

        return DriverManager::getConnection($params, (new Configuration())->setMiddlewares([new SqliteConnectionSetup()]));
    }

    public function testMigrateCreatesEveryTable(): void
    {
        $connection = PrivateDatabase::sqliteMemory();

        $catalog = new SchemaCatalog($connection);
        foreach (self::TABLES as $table) {
            $this->assertContains($table, $catalog->tables());
            $this->assertTrue($catalog->hasTable($table), $table);
        }
        $this->assertSame([], (new SchemaMigrator($connection))->pending());
        $this->assertSame(self::BASELINE, (new SchemaMigrator($connection))->latest());
    }

    /**
     * migrate() returns what THIS call executed: the baseline on an empty database, nothing on one
     * that has it (whoever ran it), so idno:upgrade reports only its own work.
     */
    public function testMigrateReturnsWhatItExecuted(): void
    {
        $connection = PrivateDatabase::sqliteMemory(migrate: false);

        $this->assertSame([self::BASELINE], (new SchemaMigrator($connection))->migrate());
        $this->assertSame([], (new SchemaMigrator($connection))->migrate());
        $connection->close();
    }

    public function testMigrateTwiceIsANoOp(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $connection->insert('idno_settings', ['name' => 'kept', 'value' => '"yes"', 'position' => 0]);
        $before = $connection->fetchAllAssociative('select type, name, sql from sqlite_master order by name');

        (new SchemaMigrator($connection))->migrate();
        (new SchemaMigrator($connection))->migrate();

        $this->assertSame($before, $connection->fetchAllAssociative('select type, name, sql from sqlite_master order by name'));
        $this->assertSame('"yes"', $connection->fetchOne('select value from idno_settings where name = ?', ['kept']));
        $this->assertSame(1, (int) $connection->fetchOne('select count(*) from idno_migrations'));
        $this->assertSame([], (new SchemaMigrator($connection))->pending());
    }

    /**
     * A first run interrupted after some of its statements (a killed request): some tables exist,
     * the version is not recorded. Running it again completes the schema.
     */
    public function testAnInterruptedBaselineCanBeRunAgain(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $connection->executeStatement('drop table idno_reader');
        $connection->executeStatement('delete from idno_migrations');
        $migrator = new SchemaMigrator($connection);
        $this->assertSame([self::BASELINE], $migrator->pending());

        $migrator->migrate();

        $catalog = new SchemaCatalog($connection);
        foreach (self::TABLES as $table) {
            $this->assertTrue($catalog->hasTable($table), $table);
        }
        $this->assertCount(4, $connection->fetchFirstColumn("select name from sqlite_master where type = 'index' and tbl_name = 'idno_reader' and sql is not null"));
        $this->assertSame([], $migrator->pending());
    }

    public function testPendingListsTheBaselineOnAnEmptyDatabase(): void
    {
        $connection = self::emptyDatabase();
        $migrator = new SchemaMigrator($connection);

        $this->assertSame([self::BASELINE], $migrator->pending());
        $this->assertSame('0', $migrator->latest());
        $this->assertSame([], (new SchemaCatalog($connection))->tables(), 'asking creates nothing');
    }

    public function testConstructingQueriesNothing(): void
    {
        $connection = self::emptyDatabase();
        new SchemaMigrator($connection);
        new SchemaCatalog($connection);

        $this->assertFalse($connection->isConnected());
    }

    public function testTheSqliteSchemaNamesNoCustomCollationOrFunction(): void
    {
        $connection = PrivateDatabase::sqliteMemory();

        $statements = $connection->fetchFirstColumn('select sql from sqlite_master where sql is not null');
        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('idno_ci', (string) $sql);
            $this->assertStringNotContainsStringIgnoringCase('idno_search', (string) $sql);
        }
    }

    public function testTheDatabaseFileOpensWithoutTheConnectionSetup(): void
    {
        $this->directory = sys_get_temp_dir() . '/idno-schema-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $path = $this->directory . '/site.sqlite';
        $connection = self::emptyDatabase($path);
        (new SchemaMigrator($connection))->migrate();
        $connection->close();

        $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->assertSame(0, (int) $pdo->query('select count(*) from idno_entities')->fetchColumn());
        $this->assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        $insert = $pdo->prepare('insert into idno_entities (id, uuid, type, owner, created, publish_status, data, search) values (?, ?, ?, ?, ?, ?, ?, ?)');
        $this->assertTrue($insert->execute(['a1', 'https://example.com/a1', 'Idno\\Entities\\Note', '', 1700000000, '', '{}', '']));
        $this->assertSame(1, (int) $pdo->query('select count(*) from idno_entities')->fetchColumn());
        $pdo = null;
    }

    public function testTheBaselineHasItsIndexes(): void
    {
        $connection = PrivateDatabase::sqliteMemory();

        $indexes = $connection->fetchFirstColumn("select name from sqlite_master where type = 'index' and tbl_name = 'idno_entities' and sql is not null order by name");
        $this->assertSame(['idno_entities_created', 'idno_entities_owner_created', 'idno_entities_type_created', 'idno_entities_uuid'], $indexes);
        foreach (['idno_reader', 'idno_site'] as $table) {
            $this->assertCount(4, $connection->fetchFirstColumn("select name from sqlite_master where type = 'index' and tbl_name = ? and sql is not null", [$table]), $table);
        }
        $columns = array_column($connection->fetchAllAssociative('PRAGMA table_info(idno_settings)'), 'name');
        $this->assertSame(['name', 'value', 'position'], $columns);
    }

    public function testTheCatalogFindsIndexedFields(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $catalog = new SchemaCatalog($connection);
        $this->assertSame([], $catalog->indexedFields('entities'));

        $connection->executeStatement("create index idno_entities_ix_slug on idno_entities (json_extract(data, '$.slug'))");
        $connection->executeStatement("create index idno_reader_ix_feed on idno_reader (json_extract(data, '$.feed'))");
        $this->assertSame([], $catalog->indexedFields('entities'), 'read once, until forgotten');

        $catalog->forget();
        $this->assertSame(['slug'], $catalog->indexedFields('entities'));
        $this->assertSame(['feed'], $catalog->indexedFields('reader'));
        $this->assertSame([], $catalog->indexedFields('site'));
        $this->assertSame([], $catalog->indexedFields('nonesuch'));
    }

    public function testHasTableIgnoresCase(): void
    {
        $catalog = new SchemaCatalog(PrivateDatabase::sqliteMemory());

        $this->assertTrue($catalog->hasTable('IDNO_Entities'));
        $this->assertFalse($catalog->hasTable('entities'));
    }
}
