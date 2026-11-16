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
        $this->assertSame('20261115000000', $migrator->latest());
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
        $this->assertSame(1, (int) $this->connection()->fetchOne('select count(*) from idno_migrations'));
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
        $this->assertSame(['20261115000000'], $migrator->pending());

        $migrator->migrate();

        $catalog = new SchemaCatalog($this->connection());
        foreach (self::TABLES as $table) {
            $this->assertTrue($catalog->hasTable($table), $table);
        }
        $this->assertSame([], $migrator->pending());
        $this->assertSame('20261115000000', $migrator->latest());
    }

    public function testColumnsAndCollations(): void
    {
        foreach (['idno_entities', 'idno_reader', 'idno_site'] as $table) {
            $columns = $this->columns($table);
            $this->assertSame(['id', 'uuid', 'type', 'owner', 'created', 'publish_status', 'data', 'search'], array_keys($columns), $table);
            $this->assertSame(['type' => 'longtext', 'collation' => 'utf8mb4_bin'], $columns['data'], $table);
            foreach (['id', 'uuid', 'type', 'owner', 'publish_status', 'search'] as $column) {
                $this->assertSame('utf8mb4_general_ci', $columns[$column]['collation'], $table . '.' . $column);
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
                $this->assertContains($column['collation'], [null, 'utf8mb4_general_ci'], $table . '.' . $name);
            }
        }
        $this->assertSame('longblob', $this->columns('idno_upgrade_rejects')['contents']['type']);
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
            $this->assertSame([], $catalog->indexedFields('entities'));
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
        $this->assertSame([], $catalog->indexedFields('entities'));

        $this->connection()->executeStatement(
            "alter table idno_entities add column ix_slug varchar(255) generated always as (json_unquote(json_extract(data, '$.slug'))) virtual, add index ix_slug (ix_slug)",
        );
        $catalog->forget();

        $this->assertSame(['slug'], $catalog->indexedFields('entities'));
        $this->assertSame([], $catalog->indexedFields('reader'));
    }
}
