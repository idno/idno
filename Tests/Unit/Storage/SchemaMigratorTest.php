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

    /** The empty migration of the indexed fields (no SQL): recorded with the baseline; the newest migration is the one the upgrade holds back. */
    private const MARKER = '20261115000001';

    /** The queue's table. */
    private const QUEUE = '20261121000000';

    private const TABLES = [
        'idno_entities',
        'idno_messages',
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
        $this->assertSame(self::QUEUE, (new SchemaMigrator($connection))->latest());
    }

    /**
     * migrate() returns what THIS call executed: the baseline on an empty database, nothing on one
     * that has it (whoever ran it), so idno:upgrade reports only its own work.
     */
    public function testMigrateReturnsWhatItExecuted(): void
    {
        $connection = PrivateDatabase::sqliteMemory(migrate: false);

        $this->assertSame([self::BASELINE, self::MARKER, self::QUEUE], (new SchemaMigrator($connection))->migrate());
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
        $this->assertSame(3, (int) $connection->fetchOne('select count(*) from idno_migrations'));
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
        $this->assertSame([self::BASELINE, self::MARKER, self::QUEUE], $migrator->pending());

        $migrator->migrate();

        $catalog = new SchemaCatalog($connection);
        foreach (self::TABLES as $table) {
            $this->assertTrue($catalog->hasTable($table), $table);
        }
        $this->assertCount(5, $connection->fetchFirstColumn("select name from sqlite_master where type = 'index' and tbl_name = 'idno_reader' and sql is not null"));
        $this->assertSame([], $migrator->pending());
    }

    public function testPendingListsTheBaselineOnAnEmptyDatabase(): void
    {
        $connection = self::emptyDatabase();
        $migrator = new SchemaMigrator($connection);

        $this->assertSame([self::BASELINE, self::MARKER, self::QUEUE], $migrator->pending());
        $this->assertSame('0', $migrator->latest());
        $this->assertSame([], (new SchemaCatalog($connection))->tables(), 'asking creates nothing');
    }

    /**
     * hasPending() is what the schema gate asks on every request: ONE statement (the executed
     * versions), compared with the migration classes on disk, never doctrine/migrations' machinery.
     */
    public function testHasPendingAsksOneQuestion(): void
    {
        $recorder = new \Tests\Storage\StatementRecorder();
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new SqliteConnectionSetup(), $recorder]));
        $migrator = new SchemaMigrator($connection);
        $migrator->migrate();
        $recorder->statements = [];

        $this->assertFalse($migrator->hasPending());
        $this->assertSame([['sql' => 'select version from idno_migrations', 'params' => []]], $recorder->statements);

        $connection->executeStatement('delete from idno_migrations');
        $this->assertTrue($migrator->hasPending());
        $this->assertSame([self::BASELINE, self::MARKER, self::QUEUE], $migrator->pending(), 'the same answer as pending()');

        $connection->executeStatement("insert into idno_migrations (version) values ('Idno\\Storage\\Migrations\\Version19990101000000')");
        $this->assertTrue($migrator->hasPending(), 'an executed version that is not on disk does not count');
    }

    /**
     * migrate(exceptLast: true) leaves the newest pending migration (the upgrade records it only once
     * the declared indexes exist). It is the newest, whichever it is: today the queue's table.
     */
    public function testMigrateCanLeaveTheLastPendingMigration(): void
    {
        $connection = PrivateDatabase::sqliteMemory(migrate: false);
        $migrator = new SchemaMigrator($connection);

        $this->assertSame([self::BASELINE, self::MARKER], $migrator->migrate(exceptLast: true));
        $this->assertSame([self::QUEUE], $migrator->pending());
        $this->assertTrue($migrator->hasPending());
        $this->assertNotContains('idno_messages', (new SchemaCatalog($connection))->tables());
        $this->assertSame([], $migrator->migrate(exceptLast: true), 'the last one is left again');
        $this->assertSame([self::QUEUE], $migrator->migrate());
        $this->assertContains('idno_messages', (new SchemaCatalog($connection))->tables());
        $this->assertFalse($migrator->hasPending());
        $this->assertSame(self::QUEUE, $migrator->latest());
    }

    /**
     * The queue's table is exactly the one symfony/doctrine-messenger declares (its own
     * Connection::configureSchema(), written out in the migration as literal SQL): the same columns
     * with the same types, the same key and the same index.
     */
    public function testTheQueuesTableIsTheOneTheTransportDeclares(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $transport = new \Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection(['table_name' => 'idno_messages'], $connection);
        $declared = $transport->configureSchema(new \Doctrine\DBAL\Schema\Schema(), $connection, static fn (): bool => true)->getTable('idno_messages');

        $actual = $connection->createSchemaManager()->introspectTableByUnquotedName('idno_messages');

        // SQLite has one integer type: the declared BIGINT is INTEGER there (an auto-increment key must be).
        $normalise = static fn (string $type): string => $type === \Doctrine\DBAL\Types\BigIntType::class ? \Doctrine\DBAL\Types\IntegerType::class : $type;
        $this->assertSame(
            array_map(static fn ($column): array => [trim($column->getObjectName()->toString(), '"`'), $normalise($column->getType()::class), $column->getNotnull(), $column->getAutoincrement()], $declared->getColumns()),
            array_map(static fn ($column): array => [trim($column->getObjectName()->toString(), '"`'), $normalise($column->getType()::class), $column->getNotnull(), $column->getAutoincrement()], $actual->getColumns()),
        );
        $this->assertSame(190, $actual->getColumn('queue_name')->getLength());
        $indexed = array_values(array_filter($actual->getIndexes(), static fn ($index): bool => !$index->isPrimary()));
        $this->assertCount(1, $indexed);
        $this->assertSame(['queue_name', 'available_at', 'delivered_at', 'id'], array_map(static fn ($column): string => trim($column->getColumnName()->toString(), '"`'), $indexed[0]->getIndexedColumns()));
        $this->assertSame(['id'], array_map(static fn ($name): string => trim($name->toString(), '"`'), $actual->getPrimaryKeyConstraint()->getColumnNames()));
    }

    /**
     * A run interrupted after the queue's table was created and before its version was recorded
     * (nothing is transactional on every platform) runs again without failing.
     */
    public function testAnInterruptedQueueMigrationCanBeRunAgain(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $connection->executeStatement('delete from idno_migrations where version like ?', ['%' . self::QUEUE]);
        $connection->insert('idno_messages', ['body' => 'kept', 'headers' => '[]', 'queue_name' => 'idno', 'created_at' => '2026-11-21 00:00:00', 'available_at' => '2026-11-21 00:00:00']);
        $migrator = new SchemaMigrator($connection);
        $this->assertSame([self::QUEUE], $migrator->pending());

        $migrator->migrate();

        $this->assertSame([], $migrator->pending());
        $this->assertSame('kept', $connection->fetchOne('select body from idno_messages'), 'what was queued is kept');
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
        $this->assertSame(['idno_entities_created', 'idno_entities_ix_access', 'idno_entities_owner_created', 'idno_entities_type_created', 'idno_entities_uuid'], $indexes);
        foreach (['idno_reader', 'idno_site'] as $table) {
            $this->assertCount(5, $connection->fetchFirstColumn("select name from sqlite_master where type = 'index' and tbl_name = ? and sql is not null", [$table]), $table);
        }
        $columns = array_column($connection->fetchAllAssociative('PRAGMA table_info(idno_settings)'), 'name');
        $this->assertSame(['name', 'value', 'position'], $columns);
        $this->assertSame(
            ['collection', 'source_rows', 'source_existed', 'copied', 'rejected', 'position', 'verify_cursor', 'error', 'error_detail', 'completed_at'],
            array_column($connection->fetchAllAssociative('PRAGMA table_info(idno_upgrade_progress)'), 'name'),
        );
        $this->assertSame(['collection', 'legacy_uuid'], array_column($connection->fetchAllAssociative('PRAGMA index_info(idno_upgrade_rejects_collection_uuid)'), 'name'));
    }

    /**
     * `access` is in every entity query, so the baseline indexes it itself, with the type: the
     * index a declared field gets (the catalog lists it), plus the type.
     */
    public function testEveryCollectionTableIndexesAccessWithTheType(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $catalog = new SchemaCatalog($connection);

        foreach (['entities', 'reader', 'site'] as $collection) {
            $this->assertSame(['access'], $catalog->indexedFields($collection), $collection);
            $columns = $connection->fetchAllAssociative('PRAGMA index_xinfo(idno_' . $collection . '_ix_access)');
            $key = array_values(array_filter($columns, static fn (array $column): bool => (int) $column['key'] === 1));
            $this->assertCount(2, $key, $collection);
            $this->assertSame('type', $key[0]['name'], $collection);
            $this->assertNull($key[1]['name'], $collection . ': an expression');
            $this->assertSame('NOCASE', $key[1]['coll'], $collection);
        }
    }

    public function testTheCatalogFindsIndexedFields(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $catalog = new SchemaCatalog($connection);
        $this->assertSame(['access'], $catalog->indexedFields('entities'), 'the schema\'s own');

        $connection->executeStatement("create index idno_entities_ix_slug on idno_entities (json_extract(data, '$.slug'))");
        $connection->executeStatement("create index idno_reader_ix_feed on idno_reader (json_extract(data, '$.feed'))");
        $this->assertSame(['access'], $catalog->indexedFields('entities'), 'read once, until forgotten');

        $catalog->forget();
        $this->assertSame(['access', 'slug'], $catalog->indexedFields('entities'));
        $this->assertSame(['access', 'feed'], $catalog->indexedFields('reader'));
        $this->assertSame(['access'], $catalog->indexedFields('site'));
        $this->assertSame([], $catalog->indexedFields('nonesuch'));
    }

    public function testHasTableIgnoresCase(): void
    {
        $catalog = new SchemaCatalog(PrivateDatabase::sqliteMemory());

        $this->assertTrue($catalog->hasTable('IDNO_Entities'));
        $this->assertFalse($catalog->hasTable('entities'));
    }
}
