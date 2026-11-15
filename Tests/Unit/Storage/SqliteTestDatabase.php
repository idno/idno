<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Storage\Platform\SqliteConnectionSetup;

/**
 * SQLite connections for tests, set up as the site's would be (SqliteConnectionSetup), and the
 * schema loaded the way the legacy installer loads a schema file: split on ";\n", one statement
 * at a time. Needs nothing but PHP (pdo_sqlite).
 */
final class SqliteTestDatabase
{
    public const SCHEMA = __DIR__ . '/../../../warmup/schemas/sqlite/sqlite.sql';

    /**
     * A DBAL connection to a database file (a path) or, by default, a new in-memory database.
     * It does not connect until it is first used.
     */
    public static function connection(?string $path = null): Connection
    {
        $params = $path === null ? ['driver' => 'pdo_sqlite', 'memory' => true] : ['driver' => 'pdo_sqlite', 'path' => $path];

        return DriverManager::getConnection($params, (new Configuration())->setMiddlewares([new SqliteConnectionSetup()]));
    }

    /**
     * A connection with the schema loaded.
     */
    public static function withSchema(?string $path = null): Connection
    {
        $connection = self::connection($path);
        self::loadSchema($connection);

        return $connection;
    }

    public static function loadSchema(Connection $connection): void
    {
        foreach (explode(";\n", (string) file_get_contents(self::SCHEMA)) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $connection->executeStatement($statement);
            }
        }
    }
}
