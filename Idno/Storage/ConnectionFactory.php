<?php

declare(strict_types=1);

namespace Idno\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Storage\Platform\ConnectFailureIsFinal;
use Idno\Storage\Platform\SqliteConnectionSetup;

/**
 * The DBAL connection a legacy database configuration gives: the parameters of
 * ConnectionParameters::fromLegacyConfig() and the site's middlewares (SqliteConnectionSetup for an
 * SQLite database; ConnectFailureIsFinal last, on every database). It is the one place that decides
 * both: the site's own connection (BootConfigConnectionFactory, through DoctrineBundle's factory) and
 * a connection to another database built from a configuration of its own (the MongoDB import's
 * target, forConfig()) are the same connection for the same configuration. Building one does not
 * connect (DBAL connects on the first query).
 *
 * A static helper, not a container service (see config/services.php).
 */
final class ConnectionFactory
{
    /**
     * The connection the site would have with this configuration.
     *
     * @param array<string, mixed> $legacyConfig the legacy database settings (`database`, `dbname`, `dbhost`, ...)
     * @param array<string, mixed> $server       the RDS_* variables, for `beanstalk-mysql`
     *
     * @throws \LogicException when the configuration's database is not an SQL database
     */
    public static function forConfig(array $legacyConfig, array $server = [], ?string $projectDir = null): Connection
    {
        $params = self::parameters($legacyConfig, $server, $projectDir);

        return DriverManager::getConnection($params, self::configuration($params));
    }

    /**
     * The DBAL parameters that identify the database (ConnectionParameters::IDENTITY_KEYS).
     *
     * @param array<string, mixed> $legacyConfig
     * @param array<string, mixed> $server
     *
     * @return array<string, mixed>
     *
     * @throws \LogicException when the configuration's database is not an SQL database
     */
    public static function parameters(array $legacyConfig, array $server = [], ?string $projectDir = null): array
    {
        return ConnectionParameters::fromLegacyConfig($legacyConfig, $server, $projectDir);
    }

    /**
     * A copy of the configuration (a new one when none is given) with the site's middlewares added
     * after its own: SqliteConnectionSetup for an SQLite connection, then ConnectFailureIsFinal, last,
     * so that it sees every failure (a first connect that fails is final: the schema gate has seen the
     * database unreachable, and nothing may boot on a second attempt that succeeds).
     *
     * @param array<string, mixed> $params the parameters of parameters()
     */
    public static function configuration(array $params, ?Configuration $base = null): Configuration
    {
        $config = clone ($base ?? new Configuration());
        $middlewares = $config->getMiddlewares();
        if (($params['driver'] ?? null) === 'pdo_sqlite') {
            $middlewares[] = new SqliteConnectionSetup();
        }
        $middlewares[] = new ConnectFailureIsFinal();
        $config->setMiddlewares($middlewares);

        return $config;
    }
}
