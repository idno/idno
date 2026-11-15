<?php

declare(strict_types=1);

namespace Idno\Storage\Platform;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * A DBAL driver middleware that sets up every new SQLite connection for SqliteDialect
 * (SqliteDialect::setUpConnection(): the pragmas, the collation, the search function) when it
 * connects: once per physical connection, never before the first query. A connection to another
 * database passes through untouched.
 *
 * BootConfigConnectionFactory adds it to the `database_connection` service of an SQLite site;
 * code that builds its own SQLite connection for DbalRecordStore adds it to the connection's
 * Configuration (Configuration::setMiddlewares()).
 *
 * Built with new, not a container service (see config/services.php).
 */
final class SqliteConnectionSetup implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                $connection = parent::connect($params);
                $native = $connection->getNativeConnection();
                if ($native instanceof \Pdo\Sqlite) {
                    SqliteDialect::setUpConnection($native);
                } elseif ($native instanceof \SQLite3) {
                    throw new \LogicException('SQLite storage needs the pdo_sqlite DBAL driver, not sqlite3.');
                }

                return $connection;
            }
        };
    }
}
