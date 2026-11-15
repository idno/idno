<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Connection;
use Idno\Kernel;
use Idno\Storage\Criteria;
use Idno\Storage\DbalRecordStore;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\Platform\SqliteDialect;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Storage\SqliteTestDatabase;

/**
 * The container's `database_connection` service on a site whose database is SQLite (a kernel
 * booted without the legacy application, configured through IDNO_* variables, with a database file
 * in a temporary directory): it is set up for SqliteDialect on its first use, and not before.
 */
final class SqliteConnectionTest extends TestCase
{
    private ?Kernel $kernel = null;

    private string $dir = '';

    /** @var array<string, mixed> $_SERVER entries this test changed, with their previous values (null: unset) */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/idno-sqlite-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        foreach ($this->serverBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        $this->serverBackup = [];
        self::removeDir($this->dir);
    }

    private function setServer(string $key, ?string $value): void
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

    /**
     * The connection service of a kernel (without the legacy application, compiled into a fresh
     * cache directory) whose site database is the one given.
     */
    private function connection(string $database, ?string $dbname): Connection
    {
        $this->setServer('IDNO_CACHE_DIR', $this->dir . '/cache');
        $this->setServer('IDNO_DATABASE', $database);
        $this->setServer('IDNO_DBNAME', $dbname);
        $this->kernel = new Kernel('test', true, bootLegacy: false);
        $this->kernel->boot();
        $connection = $this->kernel->getContainer()->get('database_connection');
        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    public function testTheServiceIsSetUpOnFirstUseAndNotBefore(): void
    {
        $file = $this->dir . '/site.sqlite';
        $connection = $this->connection('sqlite', $file);

        $this->assertFalse($connection->isConnected());
        $this->assertFileDoesNotExist($file, 'fetching the service does not open the database');

        $this->assertSame('1', (string) $connection->fetchOne('pragma foreign_keys'));
        $this->assertFileExists($file);
        $this->assertSame('wal', (string) $connection->fetchOne('pragma journal_mode'));
        $this->assertSame((string) SqliteDialect::BUSY_TIMEOUT_MILLISECONDS, (string) $connection->fetchOne('pragma busy_timeout'));
        $this->assertSame('0', (string) $connection->fetchOne("select 'a_' < 'aZ' collate " . SqliteDialect::COLLATION));
        $this->assertSame('1', (string) $connection->fetchOne('select ' . SqliteDialect::SEARCH_FUNCTION . "('a platypus', 'xx PLATY')"));

        $setups = array_filter($connection->getConfiguration()->getMiddlewares(), static fn (object $m): bool => $m instanceof SqliteConnectionSetup);
        $this->assertCount(1, $setups);
    }

    public function testARecordStoreWorksOnTheService(): void
    {
        $connection = $this->connection('sqlite', $this->dir . '/site.sqlite');
        SqliteTestDatabase::loadSchema($connection);
        $store = new DbalRecordStore($connection, new SqliteDialect(), new FixedRecordDefaults(), static fn (string $s): string => $s, true, false);

        $id = $store->save('entities', ['title' => 'A platypus', 'slug' => 'Red', 'created' => 1686700000]);

        $this->assertIsString($id);
        $this->assertSame([$id], array_column($store->find('entities', Criteria::fromLegacyArray(['slug' => 'red', '$search' => ['platy']]), 10, 0), '_id'));
        $this->assertTrue($store->delete('entities', $id));
        $this->assertSame('0', (string) $connection->fetchOne('select count(*) from entities_metadata'));
    }

    public function testAnInMemoryDatabaseIsSetUpToo(): void
    {
        $connection = $this->connection('sqlite', ':memory:');

        $this->assertSame('1', (string) $connection->fetchOne('pragma foreign_keys'));
        $this->assertSame('0', (string) $connection->fetchOne("select 'a_' < 'aZ' collate " . SqliteDialect::COLLATION));
    }

    public function testAMysqlConnectionIsNotTouched(): void
    {
        $connection = $this->connection('mysql', 'idno_never_connected');

        $this->assertFalse($connection->isConnected());
        $this->assertSame('pdo_mysql', $connection->getParams()['driver']);
        $setups = array_filter($connection->getConfiguration()->getMiddlewares(), static fn (object $m): bool => $m instanceof SqliteConnectionSetup);
        $this->assertSame([], $setups);
    }
}
