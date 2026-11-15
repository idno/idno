<?php

declare(strict_types=1);

namespace Idno\Storage;

use Doctrine\Bundle\DoctrineBundle\ConnectionFactory;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Idno\Config\BootConfig;

/**
 * Decorates DoctrineBundle's connection factory (`doctrine.dbal.connection_factory`, see
 * config/services.php): when the container builds the DBAL connection, the parameters that
 * identify the database are replaced by the ones ConnectionParameters derives from the boot
 * configuration. They are resolved at runtime, when the connection service is first fetched,
 * so no credential is compiled into the cached container. Building the connection does not
 * connect (DBAL connects on the first query).
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
    )
    {
    }

    /**
     * @param array<string, mixed>  $params       the parameters DoctrineBundle compiled from config/packages/doctrine.php
     * @param array<string, string> $mappingTypes
     *
     * @throws \LogicException when the site's database is not an SQL database (MongoDB, or a plugin backend)
     */
    public function createConnection(array $params, ?Configuration $config = null, array $mappingTypes = []): Connection
    {
        $legacy = ConnectionParameters::fromLegacyConfig($this->bootConfig->all(), array_filter($this->server, static fn (mixed $value): bool => $value !== null));
        foreach (ConnectionParameters::IDENTITY_KEYS as $key) {
            unset($params[$key]);
        }

        return $this->inner->createConnection($legacy + $params, $config, $mappingTypes);
    }
}
