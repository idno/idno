<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // One DBAL connection (the `database_connection` service, autowired as Doctrine\DBAL\Connection).
    // Its parameters (driver, host, credentials, charset, PDO options) are NOT configured here:
    // Idno\Storage\BootConfigConnectionFactory supplies them at runtime from the boot
    // configuration (config/services.php), so nothing from configuration is compiled into the
    // container. The driver below only keeps the bundle's own compile-time defaults sensible.
    //
    // The connection is lazy: it connects on its first query. Nothing may use it while the
    // kernel boots, because a site whose database is MongoDB (or a plugin backend) has none:
    // fetching the service then throws a \LogicException.
    $container->extension('doctrine', [
        'dbal' => [
            'default_connection' => 'default',
            'connections' => [
                'default' => [
                    'driver' => 'pdo_mysql',
                    // No query logging or profiling middleware (they would also keep every query
                    // in memory in a long-running worker), and no closing of idle connections on
                    // the next request: the connection behaves like the legacy one.
                    'logging' => false,
                    'profiling' => false,
                    'idle_connection_ttl' => 0,
                ],
            ],
        ],
    ]);
};
