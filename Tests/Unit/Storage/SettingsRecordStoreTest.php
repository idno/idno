<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\SchemaMigrator;
use Tests\Storage\Contract\SettingsRecordStoreContract;

/**
 * SettingsRecordStore on SQLite: a new database FILE per test in a temporary directory this test
 * makes and removes (an in-memory database belongs to one connection, and the tests need a second
 * writer), with the schema migrations run, on connections set up as the site's are
 * (SqliteConnectionSetup). It needs nothing but PHP, so it runs in the unit tier. The same tests
 * run on a private MySQL or MariaDB database in Tests\Storage\SettingsRecordStoreMySqlTest.
 *
 * Two writers: SQLite has one write lock. A save starts with a write (it deletes every row), so
 * the lock is taken at once and a second writer waits for it (the second connection's busy timeout
 * is 100 ms) and then has its save refused (false).
 */
final class SettingsRecordStoreTest extends SettingsRecordStoreContract
{
    private string $directory = '';

    protected function openDatabase(FailingStatementMiddleware $failure): Connection
    {
        $this->directory = sys_get_temp_dir() . '/idno-settings-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'path' => $this->directory . '/site.sqlite'],
            (new Configuration())->setMiddlewares([new SqliteConnectionSetup(), $failure]),
        );
        (new SchemaMigrator($connection))->migrate();

        return $connection;
    }

    protected function openSecond(): Connection
    {
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'path' => $this->directory . '/site.sqlite'],
            (new Configuration())->setMiddlewares([new SqliteConnectionSetup()]),
        );
        $connection->executeStatement('pragma busy_timeout = 100');

        return $connection;
    }

    protected function closeDatabase(Connection $connection): void
    {
        $connection->close();
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    protected function dialect(Connection $connection): Dialect
    {
        return new SqliteDialect($connection);
    }
}
