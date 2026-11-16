<?php

declare(strict_types=1);

namespace Idno\Storage\Platform;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * A DBAL driver middleware that sets up every new SQLite connection for SqliteDialect
 * (SqliteDialect::setUpConnection(): it refuses an SQLite library older than 3.38 or without the
 * JSON functions with a \LogicException naming the version required and the one found, then sets
 * the pragmas busy_timeout, journal_mode = WAL and foreign_keys) when it connects: once per
 * physical connection, never before the first query. Any connection that is not pdo_sqlite's
 * (`Pdo\Sqlite`) is refused with a \LogicException: the middleware must only be given to SQLite
 * connections (BootConfigConnectionFactory adds it only to those).
 *
 * BootConfigConnectionFactory adds it to the `database_connection` service of an SQLite site;
 * code that builds its own SQLite connection for the storage adds it to the connection's
 * Configuration (Configuration::setMiddlewares()), as Tests\Storage\PrivateDatabase does.
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
                if (!$native instanceof \Pdo\Sqlite) {
                    throw new \LogicException(sprintf('SqliteConnectionSetup is for SQLite connections through the pdo_sqlite driver, but the connection is a %s.', get_debug_type($native)));
                }
                SqliteDialect::setUpConnection($native);

                return $connection;
            }
        };
    }
}
