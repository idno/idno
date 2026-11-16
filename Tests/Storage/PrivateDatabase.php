<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Idno\Config\BootConfig;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\SchemaMigrator;
use PHPUnit\Framework\Assert;

/**
 * Databases of a test's own, with the schema migrations run: never the rig site's database.
 *
 *  - sqliteMemory(): a new in-memory SQLite database, set up as the site's connections are
 *    (SqliteConnectionSetup). Needs nothing but PHP: usable in the unit tier.
 *  - mysql($purpose): the database `<site's dbname>_<purpose>` on the site's MySQL or MariaDB
 *    server (the rig's), dropped first if a previous run left it, on a NEW connection (not the
 *    site's). Needs the booted application (its boot configuration) and so the rig; skips the
 *    test, saying why, when the site's database is not MySQL (an SQLite rig, MongoDB).
 *
 * drop() removes what either made: call it in tearDown().
 */
final class PrivateDatabase
{
    public static function sqliteMemory(): Connection
    {
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            (new Configuration())->setMiddlewares([new SqliteConnectionSetup()]),
        );
        (new SchemaMigrator($connection))->migrate();

        return $connection;
    }

    /**
     * @param string $purpose lower-case letters, digits and underscores: the database name's suffix
     */
    public static function mysql(string $purpose): Connection
    {
        if (preg_match('/^[a-z0-9_]{1,32}$/', $purpose) !== 1) {
            throw new \InvalidArgumentException('A private database\'s purpose is 1 to 32 lower-case letters, digits or underscores.');
        }
        $config = \Idno\Core\Idno::container()->get(BootConfig::class)->all();
        if (ConnectionParameters::driverFamily($config) !== 'mysql') {
            Assert::markTestSkipped('The site\'s database is not MySQL or MariaDB, so there is no MySQL server to make a private database on.');
        }
        $params = ConnectionParameters::fromLegacyConfig($config, $_SERVER);
        $site = (string) ($params['dbname'] ?? '');
        if ($site === '') {
            throw new \LogicException('The site\'s configuration names no database.');
        }
        $name = $site . '_' . $purpose;

        $server = $params;
        unset($server['dbname']);
        $admin = DriverManager::getConnection($server);
        try {
            $admin->executeStatement('drop database if exists `' . $name . '`');
            $admin->executeStatement('create database `' . $name . '` character set utf8mb4 collate utf8mb4_general_ci');
        } catch (\Doctrine\DBAL\Exception $e) {
            Assert::markTestSkipped('The database user cannot create a private database: ' . $e->getMessage());
        } finally {
            $admin->close();
        }

        $connection = DriverManager::getConnection(['dbname' => $name] + $params);
        (new SchemaMigrator($connection))->migrate();

        return $connection;
    }

    /**
     * Removes a database made by mysql() (drops it) or sqliteMemory() (closes it), and closes the
     * connection.
     */
    public static function drop(Connection $connection): void
    {
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $name = (string) $connection->getDatabase();
            $config = \Idno\Core\Idno::container()->get(BootConfig::class)->all();
            $site = (string) (ConnectionParameters::fromLegacyConfig($config, $_SERVER)['dbname'] ?? '');
            if ($name === '' || $name === $site || !str_starts_with($name, $site . '_')) {
                throw new \LogicException('Not a private database: ' . $name);
            }
            $connection->executeStatement('drop database if exists `' . $name . '`');
        }
        $connection->close();
    }
}
