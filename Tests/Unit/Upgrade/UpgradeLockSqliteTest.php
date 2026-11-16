<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Upgrade\UpgradeLock;
use PHPUnit\Framework\TestCase;

/**
 * The upgrade lock on SQLite: an exclusive, non-blocking flock() on `<database file>.upgrade.lock`,
 * always granted for an in-memory database. Needs nothing but PHP: the database files are in a
 * temporary directory of the test's own, removed afterwards.
 */
final class UpgradeLockSqliteTest extends TestCase
{
    private string $directory = '';

    /** @var list<Connection> */
    private array $connections = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/idno-upgrade-lock-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
        foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
    }

    public function testOnlyOneLockOnADatabaseFileIsGrantedAndItNeverWaits(): void
    {
        $one = new UpgradeLock($this->file('site.sqlite'));
        $two = new UpgradeLock($this->file('site.sqlite'));

        self::assertTrue($one->acquire());
        self::assertTrue($one->holds());
        self::assertFileExists($this->directory . '/site.sqlite.upgrade.lock');
        $started = microtime(true);
        self::assertFalse($two->acquire());
        self::assertLessThan(1.0, microtime(true) - $started);
        self::assertFalse($two->holds());

        $one->release();
        self::assertFalse($one->holds());
        self::assertTrue($two->acquire());
        self::assertFalse($one->acquire());
        $two->release();
    }

    public function testAcquiringALockItHoldsIsGranted(): void
    {
        $one = new UpgradeLock($this->file('site.sqlite'));

        self::assertTrue($one->acquire());
        self::assertTrue($one->acquire());
        $one->release();
        self::assertTrue((new UpgradeLock($this->file('site.sqlite')))->acquire());
    }

    public function testLocksOnDifferentFilesAreIndependent(): void
    {
        self::assertTrue((new UpgradeLock($this->file('one.sqlite')))->acquire());
        self::assertTrue((new UpgradeLock($this->file('two.sqlite')))->acquire());
    }

    public function testAnInMemoryDatabaseAlwaysGetsTheLock(): void
    {
        $one = new UpgradeLock($this->memory());
        $two = new UpgradeLock($this->memory());

        self::assertTrue($one->acquire());
        self::assertTrue($two->acquire());
        self::assertTrue($one->holds());
        $one->release();
        $two->release();
        self::assertSame([], glob($this->directory . '/*') ?: []);
    }

    public function testReleasingALockThatIsNotHeldDoesNothing(): void
    {
        $one = new UpgradeLock($this->file('site.sqlite'));
        $one->release();
        self::assertFalse($one->holds());
        self::assertTrue($one->acquire());
    }

    private function file(string $name): Connection
    {
        return $this->connections[] = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->directory . '/' . $name]);
    }

    private function memory(): Connection
    {
        return $this->connections[] = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }
}
