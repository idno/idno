<?php

declare(strict_types=1);

namespace Idno\Storage;

use Doctrine\Bundle\DoctrineBundle\ConnectionFactory;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Idno\Config\BootConfig;
use Idno\Storage\Platform\ConnectFailureIsFinal;
use Idno\Storage\Platform\SqliteConnectionSetup;

/**
 * Decorates DoctrineBundle's connection factory (`doctrine.dbal.connection_factory`, see
 * config/services.php): when the container builds the DBAL connection, the parameters that
 * identify the database are replaced by the ones ConnectionParameters derives from the boot
 * configuration. They are resolved at runtime, when the connection service is first fetched,
 * so no credential is compiled into the cached container. Building the connection does not
 * connect (DBAL connects on the first query).
 *
 * For an SQLite site it also adds SqliteConnectionSetup to the connection's middlewares (on a
 * copy of the Configuration), so that every physical connection is set up for SqliteDialect when
 * it connects. On every site it adds Platform\ConnectFailureIsFinal last (on that copy): when the
 * connection's first attempt to connect fails, no later attempt is made, so the database the
 * schema gate saw as unreachable stays so for the request.
 *
 * This class is wired by hand in config/services.php, not by the directory scan.
 */
final class BootConfigConnectionFactory
{
    /**
     * @param array<string, mixed> $server the RDS_* environment variables (null when unset), for `beanstalk-mysql`
     */
    public function __construct(
        private readonly ConnectionFactory $inner,
        private readonly BootConfig $bootConfig,
        private readonly array $server,
        private readonly string $projectDir,
    )
    {
    }

    /**
     * @param array<string, mixed>  $params       the parameters DoctrineBundle compiled from config/packages/doctrine.php
     * @param array<string, string> $mappingTypes
     *
     * @throws \LogicException when the site's database is not an SQL database (MongoDB, or a loadable plugin backend class: ConnectionParameters::driverFamily())
     */
    public function createConnection(array $params, ?Configuration $config = null, array $mappingTypes = []): Connection
    {
        $legacy = ConnectionParameters::fromLegacyConfig($this->bootConfig->all(), array_filter($this->server, static fn (mixed $value): bool => $value !== null), $this->projectDir);
        foreach (ConnectionParameters::IDENTITY_KEYS as $key) {
            unset($params[$key]);
        }

        $config = clone ($config ?? new Configuration());
        $middlewares = $config->getMiddlewares();
        if ($legacy['driver'] === 'pdo_sqlite') {
            $middlewares[] = new SqliteConnectionSetup();
        }
        // Last, so that it sees every failure: a first connect that fails is final (the schema gate
        // has seen the database unreachable; nothing may boot on a second attempt that succeeds).
        $middlewares[] = new ConnectFailureIsFinal();
        $config->setMiddlewares($middlewares);

        return $this->inner->createConnection($legacy + $params, $config, $mappingTypes);
    }
}
