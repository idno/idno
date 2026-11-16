<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\Platform\MySqlDialect;
use Tests\Storage\Contract\SettingsRecordStoreContract;
use Tests\Unit\Storage\FailingStatementMiddleware;

/**
 * SettingsRecordStore on MySQL or MariaDB: a private database on the rig's server
 * (PrivateDatabase::mysql('settings'), made once for the class and dropped after it), never the
 * site's tables; each test gets a new connection to it (with the failing-statement middleware) and
 * empty tables. Skipped when the site's database is not MySQL or MariaDB. The tests are those of
 * SettingsRecordStoreContract, which the unit tier runs on SQLite.
 */
final class SettingsRecordStoreMySqlTest extends SettingsRecordStoreContract
{
    private static ?Connection $database = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$database = PrivateDatabase::mysql('settings');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$database !== null) {
            PrivateDatabase::drop(self::$database);
            self::$database = null;
        }
        parent::tearDownAfterClass();
    }

    protected function openDatabase(FailingStatementMiddleware $failure): Connection
    {
        self::assertNotNull(self::$database);
        foreach (['idno_settings', 'idno_entities', 'idno_reader', 'idno_site'] as $table) {
            self::$database->executeStatement('delete from ' . $table);
        }

        return DriverManager::getConnection(self::$database->getParams(), (new Configuration())->setMiddlewares([$failure]));
    }

    protected function openSecond(): Connection
    {
        self::assertNotNull(self::$database);
        $connection = DriverManager::getConnection(self::$database->getParams());
        $connection->executeStatement('set session innodb_lock_wait_timeout = 1');

        return $connection;
    }

    protected function closeDatabase(Connection $connection): void
    {
        $connection->close();
    }

    protected function dialect(Connection $connection): Dialect
    {
        return new MySqlDialect();
    }
}
