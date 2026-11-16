<?php

declare(strict_types=1);

namespace Idno\Storage;

use Doctrine\DBAL\Connection;
use Idno\Config\BootConfig;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\Platform\MySqlDialect;
use Idno\Storage\Platform\SqliteDialect;

/**
 * Builds the container's Dialect at runtime, when it is first fetched: the dialect of the site's
 * database as the boot configuration names it (ConnectionParameters::driverFamily()): MySqlDialect
 * for MySQL and MariaDB, SqliteDialect on the `database_connection` service for SQLite. Building it
 * does not connect.
 *
 * Any other database (MongoDB, a backend class of a plugin) has no dialect: create() throws a
 * \LogicException. So does fetching the connection on such a site, which happens first when a
 * service that takes both (IndexedFields) is built.
 */
final class DialectFactory
{
    public function __construct(
        private readonly BootConfig $bootConfig,
        private readonly Connection $connection,
    )
    {
    }

    public function create(): Dialect
    {
        return match (ConnectionParameters::driverFamily($this->bootConfig->all())) {
            'mysql' => new MySqlDialect(),
            'sqlite' => new SqliteDialect($this->connection),
            default => throw new \LogicException('The site\'s database is neither MySQL, MariaDB nor SQLite: it has no SQL dialect.'),
        };
    }
}
