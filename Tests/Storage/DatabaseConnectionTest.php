<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Config\BootConfig;
use Idno\Kernel;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\Criteria;
use PHPUnit\Framework\TestCase;

/**
 * The container's DBAL connection (the `database_connection` service) on a MySQL or MariaDB site:
 * built at runtime from the boot configuration, lazy, never compiled into the container, with the
 * session a connection of the old backend had; and the record store on such a connection after a
 * failure (on a private database, never the site's tables). Skipped when the site's database is
 * not MySQL or MariaDB.
 */
final class DatabaseConnectionTest extends TestCase
{
    private ?Kernel $kernel = null;

    private ?string $cacheDir = null;

    private ?Connection $private = null;

    /** @var array<string, mixed> $_SERVER entries this test changed, with their previous values (null: unset) */
    private array $serverBackup = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $config = \Idno\Core\Idno::container()->get(BootConfig::class)->all();
        if (ConnectionParameters::driverFamily($config) !== 'mysql') {
            self::markTestSkipped('The site\'s database is not MySQL or MariaDB.');
        }
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        if ($this->cacheDir !== null) {
            self::removeDir($this->cacheDir);
            $this->cacheDir = null;
        }
        foreach ($this->serverBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        $this->serverBackup = [];
        if ($this->private !== null) {
            PrivateDatabase::drop($this->private);
            $this->private = null;
        }
    }

    /**
     * The connection service of the kernel that booted the legacy application (the test bootstrap's).
     */
    private static function connection(): Connection
    {
        $connection = \Idno\Core\Idno::container()->get('database_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function setServer(string $key, mixed $value): void
    {
        if (!array_key_exists($key, $this->serverBackup)) {
            $this->serverBackup[$key] = $_SERVER[$key] ?? null;
        }
        if ($value === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $value;
        }
    }

    /**
     * A kernel without the legacy application, compiled into a fresh cache directory.
     */
    private function bootIsolatedKernel(): Kernel
    {
        $this->cacheDir = sys_get_temp_dir() . '/idno-test-cache-' . bin2hex(random_bytes(6));
        $this->setServer('IDNO_CACHE_DIR', $this->cacheDir);
        $this->kernel = new Kernel('test', true, bootLegacy: false);
        $this->kernel->boot();

        return $this->kernel;
    }

    private static function removeDir(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeDir($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    // ------------------------------------------------ the connection service

    public function testNoCredentialInCompiledContainer(): void
    {
        $sentinel = 'dbpass-sentinel-' . bin2hex(random_bytes(6));
        $this->setServer('IDNO_DBPASS', $sentinel);
        $kernel = $this->bootIsolatedKernel();

        // The password reaches the connection at runtime...
        $connection = $kernel->getContainer()->get('database_connection');
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame($sentinel, $connection->getParams()['password']);
        $this->assertFalse($connection->isConnected());

        // ...and is nowhere in the cache directory.
        $files = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($kernel->getCacheDir(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files++;
                $this->assertStringNotContainsString($sentinel, (string) file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
        $this->assertGreaterThan(0, $files, 'expected a compiled container in the cache directory');
    }

    public function testFetchingTheConnectionServiceDoesNotConnect(): void
    {
        // An address nothing listens on: connecting would fail, so the test also proves nothing tries.
        $this->setServer('IDNO_DBHOST', '127.0.0.1');
        $this->setServer('IDNO_DBPORT', '9');
        $container = $this->bootIsolatedKernel()->getContainer();

        $connection = $container->get('database_connection');
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame($connection, $container->get('doctrine')->getConnection());
        $this->assertFalse($connection->isConnected());
        $this->assertSame(9, $connection->getParams()['port']);
    }

    public function testTheConnectionParametersComeFromTheBootConfig(): void
    {
        $container = $this->bootIsolatedKernel()->getContainer();
        $boot = $container->get(BootConfig::class);
        $expected = ConnectionParameters::fromLegacyConfig($boot->all(), [], $container->getParameter('kernel.project_dir'));

        $params = $container->get('database_connection')->getParams();
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $params[$key] ?? null, $key);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonSqlDatabases(): array
    {
        return ['mongo' => ['mongo'], 'mongodb' => ['MongoDB'], 'a plugin backend' => [\Tests\Unit\Storage\PluginBackendFixture::class]];
    }

    /**
     * The kernel boots with `bootLegacy: false`: this proves that the container compiles and the
     * kernel boots on a non-SQL site without touching the connection, not a full legacy boot on a
     * MongoDB site (which needs a MongoDB server).
     *
     * @dataProvider nonSqlDatabases
     */
    public function testANonSqlDatabaseCompilesAndBootsButHasNoConnection(string $database): void
    {
        $this->setServer('IDNO_DATABASE', $database);
        $container = $this->bootIsolatedKernel()->getContainer();
        $this->assertSame($database, $container->get(BootConfig::class)->get('database'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not an SQL database');
        $container->get('database_connection');
    }

    /**
     * The shared connection's session is what a connection of the old backend (Idno\Data\MySQL,
     * since removed) had: on every server what a control connection opened the way it opened its
     * own (a PDO with the DSN charset utf8mb4) answers, so the server's own defaults (MariaDB's
     * sql_mode differs from MySQL 8's) are what both have; on MySQL 8 also exactly what a connection
     * of the old backend answered on the rig's MySQL 8, observed on the commit before its removal.
     */
    public function testTheSessionMatchesTheLegacyConnection(): void
    {
        $query = 'SELECT @@session.sql_mode, @@session.time_zone, @@character_set_client, @@collation_connection, @@character_set_connection, @@character_set_results';
        $legacy = [
            'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
            'SYSTEM',
            'utf8mb4',
            'utf8mb4_general_ci',
            'utf8mb4',
            'utf8mb4',
        ];
        $params = self::connection()->getParams();
        $dsn = 'mysql:host=' . $params['host'] . ';dbname=' . $params['dbname'] . ';charset=utf8mb4' . (empty($params['port']) ? '' : ';port=' . $params['port']);
        $control = new \PDO($dsn, $params['user'] ?? null, $params['password'] ?? null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $dbal = self::connection()->fetchNumeric($query);

        $this->assertSame($control->query($query)->fetch(\PDO::FETCH_NUM), $dbal, 'the session of a connection the old backend opened on this server');
        if (!str_contains((string) $control->query('select version()')->fetchColumn(), 'MariaDB')) {
            $this->assertSame($legacy, $dbal, 'what the old backend\'s connection answered on MySQL 8');
        }
        $this->assertSame(['utf8mb4', 'utf8mb4_general_ci'], [$dbal[2], $dbal[3]]);
        $native = self::connection()->getNativeConnection();
        $this->assertSame(1, $native->getAttribute(\PDO::ATTR_EMULATE_PREPARES) ? 1 : 0, 'prepared statements are emulated, as legacy');
    }

    // ------------------------------------------------ failures, on a private database

    /**
     * A query the database rejects for its operand (a term MySQL's boolean-mode full-text parser
     * cannot parse) matches nothing and leaves the connection usable, outside any transaction.
     */
    public function testARejectedQueryLeavesTheConnectionUsable(): void
    {
        $this->private = PrivateDatabase::mysql('connection');
        $store = PrivateDatabase::recordStore($this->private);
        $id = $store->save('entities', ['slug' => 'usable', 'body' => 'before abc@def.com after', 'created' => 1_700_000_000]);
        $this->assertIsString($id);
        $level = $this->private->getTransactionNestingLevel();

        $this->assertSame([], $store->find('entities', Criteria::fromLegacyArray(['$search' => ['abc@def.com']]), 10, 0));
        $this->assertSame(0, $store->count('entities', Criteria::fromLegacyArray(['$search' => ['abc@def.com']])));

        $this->assertSame($level, $this->private->getTransactionNestingLevel());
        $this->assertSame([$id], array_column($store->find('entities', Criteria::fromLegacyArray(['slug' => 'usable']), 10, 0), '_id'));
    }

    /**
     * A save the database refuses (a new record with another record's uuid) returns false, stores
     * nothing and leaves the connection usable, outside any transaction.
     */
    public function testARefusedSaveLeavesTheConnectionUsable(): void
    {
        $this->private = PrivateDatabase::mysql('connection');
        $store = PrivateDatabase::recordStore($this->private);
        $first = $store->save('entities', ['slug' => 'first', 'created' => 1_700_000_000]);
        $this->assertIsString($first);
        $uuid = $store->findById('entities', $first)['uuid'];
        $level = $this->private->getTransactionNestingLevel();

        $this->assertFalse($store->save('entities', ['_id' => 'refused', 'uuid' => $uuid, 'created' => 1_700_000_000]));

        $this->assertSame($level, $this->private->getTransactionNestingLevel());
        $this->assertNull($store->findById('entities', 'refused'));
        $this->assertIsString($store->save('entities', ['slug' => 'after', 'created' => 1_700_000_000]));
    }

    public function testConnectionFailuresAreNotNoResults(): void
    {
        $params = ConnectionParameters::fromLegacyConfig(\Idno\Core\Idno::container()->get(BootConfig::class)->all(), []);
        $params['dbname'] = 'known_unittest_no_such_database_' . bin2hex(random_bytes(4));
        $store = PrivateDatabase::recordStore(DriverManager::getConnection($params));

        foreach ([
            'find' => static fn () => $store->find('entities', Criteria::all(), 10, 0),
            'count' => static fn () => $store->count('entities', Criteria::all()),
            'findById' => static fn () => $store->findById('entities', 'x'),
            'save' => static fn () => $store->save('entities', ['_id' => 'x']),
            'delete' => static fn () => $store->delete('entities', 'x'),
            'the settings' => static fn () => $store->findLatest('config'),
        ] as $operation => $call) {
            try {
                $call();
                $this->fail($operation . ' should throw');
            } catch (\Doctrine\DBAL\Exception $e) {
                $this->assertInstanceOf(\Doctrine\DBAL\Exception\ConnectionException::class, $e, $operation);
            }
        }
    }
}
