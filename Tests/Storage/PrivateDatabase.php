<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Idno\Config\BootConfig;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\IdentityTypeMap;
use Idno\Storage\JsonRecordStore;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\Platform\MySqlDialect;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\RecordDefaults;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use Idno\Storage\SettingsRecordStore;
use Idno\Storage\TypeMap;
use PHPUnit\Framework\Assert;

/**
 * Databases of a test's own, with the schema migrations run: never the rig site's database.
 *
 *  - sqliteMemory(): a new in-memory SQLite database, set up as the site's connections are
 *    (SqliteConnectionSetup). Needs nothing but PHP: usable in the unit tier.
 *  - sqliteFile($path): the SQLite database file at $path (created when it does not exist; the
 *    caller chooses a path in a temporary directory of its own and removes it), on a connection set
 *    up likewise. Needs nothing but PHP.
 *  - With $migrate false, both give a connection to an empty database (no table at all).
 *  - mysql($purpose): the database `<site's dbname>_<purpose>` on the site's MySQL or MariaDB
 *    server (the rig's), dropped first if a previous run left it, on a NEW connection (not the
 *    site's); with $migrate false it is left empty. Needs the booted application (its boot
 *    configuration) and so the rig; skips the test, saying why, when the site's database is not
 *    MySQL (an SQLite rig, MongoDB).
 *
 * If the migrations fail, mysql() drops the database it made before rethrowing. drop() removes
 * what either made: call it in tearDown().
 *
 * recordStore() builds the store the site builds on such a database (a SettingsRecordStore over a
 * JsonRecordStore), and dialect() the dialect of its platform.
 */
final class PrivateDatabase
{
    public static function sqliteMemory(bool $migrate = true): Connection
    {
        return self::sqlite(['driver' => 'pdo_sqlite', 'memory' => true], $migrate);
    }

    public static function sqliteFile(string $path, bool $migrate = true): Connection
    {
        if ($path === '' || $path === ':memory:') {
            throw new \InvalidArgumentException('A database file needs a path.');
        }

        return self::sqlite(['driver' => 'pdo_sqlite', 'path' => $path], $migrate);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function sqlite(array $params, bool $migrate): Connection
    {
        $connection = DriverManager::getConnection($params, (new Configuration())->setMiddlewares([new SqliteConnectionSetup()]));
        if ($migrate) {
            (new SchemaMigrator($connection))->migrate();
        }

        return $connection;
    }

    /**
     * The record store the site builds (LegacyRecordStoreFactory), on this connection: `config` in
     * `idno_settings`, every other collection in its `idno_<collection>` table. By default without
     * the application: FixedRecordDefaults, no aliases (IdentityTypeMap), no quote normalisation,
     * boolean-mode search on and the full-text index not bypassed (the site's defaults).
     */
    public static function recordStore(Connection $connection, ?RecordDefaults $defaults = null, ?TypeMap $types = null, ?\Closure $uncurlQuotes = null): SettingsRecordStore
    {
        $dialect = self::dialect($connection);
        $defaults ??= new FixedRecordDefaults();
        $documents = new JsonRecordStore(
            $connection,
            $dialect,
            $defaults,
            $types ?? new IdentityTypeMap(),
            new SchemaCatalog($connection),
            $uncurlQuotes ?? static fn (string $text): string => $text,
            true,
            false,
        );

        return new SettingsRecordStore($documents, $connection, $dialect, $defaults);
    }

    /**
     * The dialect of the connection's driver: SqliteDialect for pdo_sqlite, MySqlDialect otherwise
     * (MySQL and MariaDB). Read from the parameters, so it does not connect.
     */
    public static function dialect(Connection $connection): Dialect
    {
        return ($connection->getParams()['driver'] ?? '') === 'pdo_sqlite' ? new SqliteDialect() : new MySqlDialect();
    }

    /**
     * @param string $purpose lower-case letters, digits and underscores: the database name's suffix
     * @param bool   $migrate false for an empty database (no table at all)
     */
    public static function mysql(string $purpose, bool $migrate = true): Connection
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
            $admin->executeStatement('create database ' . $quoted . ' character set utf8mb4 collate utf8mb4_unicode_520_ci');
        } catch (\Doctrine\DBAL\Exception $e) {
            Assert::markTestSkipped('The database user cannot create a private database: ' . $e->getMessage());
        } finally {
            $admin->close();
        }

        $connection = DriverManager::getConnection(['dbname' => $name] + $params);
        if (!$migrate) {
            return $connection;
        }
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
