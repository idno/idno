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
 * If the migrations fail, mysql() drops the database it made before rethrowing. drop() removes
 * what either made: call it in tearDown().
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
        $name = self::siteDatabase($params) . '_' . $purpose;

        $server = $params;
        unset($server['dbname']);
        $admin = DriverManager::getConnection($server);
        try {
            $quoted = $admin->quoteSingleIdentifier($name);
            $admin->executeStatement('drop database if exists ' . $quoted);
            $admin->executeStatement('create database ' . $quoted . ' character set utf8mb4 collate utf8mb4_general_ci');
        } catch (\Doctrine\DBAL\Exception $e) {
            Assert::markTestSkipped('The database user cannot create a private database: ' . $e->getMessage());
        } finally {
            $admin->close();
        }

        $connection = DriverManager::getConnection(['dbname' => $name] + $params);
        try {
            (new SchemaMigrator($connection))->migrate();
        } catch (\Throwable $e) {
            // Leave nothing behind: the database is this call's own.
            try {
                $connection->executeStatement('drop database if exists ' . $connection->quoteSingleIdentifier($name));
            } finally {
                $connection->close();
            }

            throw $e;
        }

        return $connection;
    }

    /**
     * The site's database name, which private databases are named after.
     *
     * @param array<string, mixed> $params the site's DBAL connection parameters
     */
    private static function siteDatabase(array $params): string
    {
        $site = (string) ($params['dbname'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]+$/', $site) !== 1) {
            throw new \LogicException(sprintf(
                'The site\'s database name "%s" is not made of letters, digits and underscores only, so no private database is named after it.',
                $site,
            ));
        }

        return $site;
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
            $site = self::siteDatabase(ConnectionParameters::fromLegacyConfig($config, $_SERVER));
            if ($name === $site || !str_starts_with($name, $site . '_')) {
                throw new \LogicException('Not a private database: ' . $name);
            }
            $connection->executeStatement('drop database if exists ' . $connection->quoteSingleIdentifier($name));
        }
        $connection->close();
    }
}
