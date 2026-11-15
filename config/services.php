<?php

declare(strict_types=1);

use Idno\Config\BootConfig;
use Idno\Config\LegacyConfigLoader;
use Idno\Config\SiteSettings;
use Idno\Kernel\CodeFingerprint;
use Idno\Legacy\LegacyAccessContext;
use Idno\Legacy\LegacyBootstrap;
use Idno\Legacy\LegacyRecordDefaults;
use Idno\Legacy\LegacyRecordStoreFactory;
use Idno\Legacy\LegacyServiceFactory;
use Idno\Legacy\LegacySiteSettings;
use Idno\Storage\AccessContext;
use Idno\Storage\BootConfigConnectionFactory;
use Idno\Storage\EntityRepository;
use Idno\Storage\RecordStore;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $container->parameters()->set('idno.kernel_secret_placeholder', 'idno-kernel-placeholder-not-a-secret');

    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $projectDir = $builder->getParameter('kernel.project_dir');

    // Only the directories in CodeFingerprint::SERVICE_DIRECTORIES are scanned (the same list
    // keys the cache directory, so it is not repeated here); the rest of Idno/ is legacy
    // code. The excluded files are wired explicitly below, or are not services at all.
    $excludes = [
        // A compiler pass, registered by Idno\Kernel::build().
        'Kernel' => ['DoctrineTrimPass.php'],
        'Config' => ['BootConfig.php'],
        // Static methods only, used by index.php before the container exists.
        'Http' => ['FrontController.php'],
        // Takes the kernel (so cannot be a container service); functions.php is not a class.
        'Console' => ['Application.php', 'functions.php'],
        // Value and helper classes (a query tree and its nodes, an entity query, the record index
        // derivation, the defaults a store fills in, the connection parameters, the rules MySQL
        // applies that the other stores reproduce, the SQL dialects and the SQLite connection setup
        // middleware), the test doubles (FixedRecordDefaults, FixedAccessContext) and the
        // RecordStore implementations, built with new, not services: the container's RecordStore is
        // built by LegacyRecordStoreFactory (below). BootConfigConnectionFactory is wired by hand below.
        'Storage' => ['BootConfigConnectionFactory.php', 'ConnectionParameters.php', 'Criteria.php', 'Criterion', 'DbalRecordStore.php', 'EntityQuery.php', 'FixedAccessContext.php', 'FixedRecordDefaults.php', 'InMemoryRecordStore.php', 'MySqlSemantics.php', 'Platform', 'RecordDefaults.php', 'RecordIndex.php'],
        'Legacy' => ['FatalErrorPage.php', 'LegacyBootstrap.php', 'LegacyResponse.php', 'LegacyServiceFactory.php', 'LegacySiteSettings.php', 'NotInstalledException.php'],
    ];
    foreach (CodeFingerprint::SERVICE_DIRECTORIES as $dir) {
        $path = $projectDir . '/Idno/' . $dir;
        // fileExists() records the check as a container resource, so creating the
        // directory later triggers a recompile in debug mode.
        if ($builder->fileExists($path)) {
            $services->load('Idno\\' . $dir . '\\', $path . '/')
                ->exclude(array_map(static fn (string $file): string => $path . '/' . $file, $excludes[$dir] ?? []));
        }
    }

    // BootConfig is built at runtime from config files and the environment by a
    // factory, so none of its values are compiled into the cached container.
    $services->set(BootConfig::class)
        ->factory([LegacyConfigLoader::class, 'fromGlobals'])
        ->args(['%kernel.project_dir%'])
        ->public();

    // The legacy application's services. Each is built at runtime, when first fetched, by
    // the LegacyServiceFactory method named after its Idno::site() accessor; Idno::init()
    // fetches them in its original order. They are public because legacy code fetches them
    // with Idno::container()->get(). No instance or configuration value is ever compiled
    // into the cached container.
    $services->set(LegacyServiceFactory::class)->autowire(false)->autoconfigure(false);
    $legacyServices = [
        \Idno\Core\PageHandler::class => 'routes',
        \Idno\Core\EventDispatcher::class => 'events',
        \Idno\Core\Config::class => 'config',
        \Idno\Core\DataConcierge::class => 'db',
        \Idno\Files\FileSystem::class => 'filesystem',
        \Idno\Core\Logging::class => 'logging',
        \Idno\Core\Session::class => 'session',
        \Idno\Core\Actions::class => 'actions',
        \Idno\Core\Template::class => 'template',
        \Idno\Core\Language::class => 'language',
        \Idno\Core\Syndication::class => 'syndication',
        \Idno\Core\Reader::class => 'reader',
        \Idno\Core\HelperRobot::class => 'helperRobot',
        \Idno\Core\EventQueue::class => 'queue',
        \Idno\Stats\StatisticsCollector::class => 'statistics',
        \Idno\Caching\PersistentCache::class => 'cache',
        \Idno\Core\Plugins::class => 'plugins',
        \Idno\Core\Themes::class => 'themes',
    ];
    foreach ($legacyServices as $id => $method) {
        $services->set($id)
            ->factory([service(LegacyServiceFactory::class), $method])
            ->autowire(false)
            ->autoconfigure(false)
            ->public();
    }
    // Config reads the same BootConfig as the rest of the container.
    $services->get(\Idno\Core\Config::class)->args([service(BootConfig::class)]);
    // Logging writes through the "idno" Monolog channel (config/packages/monolog.php).
    $services->get(\Idno\Core\Logging::class)->args([service('monolog.logger.idno')]);
    // Autowiring Psr\Log\LoggerInterface gives application code this level-aware logger, not
    // the raw channel (which would bypass the site's log level and line format). Symfony's own
    // services name the "logger" / "monolog.logger.*" services they use, so are unaffected.
    $services->alias(\Psr\Log\LoggerInterface::class, \Idno\Core\Logging::class);

    $services->set(LegacyBootstrap::class)
        ->args([service('service_container')])
        ->autowire(false)
        ->autoconfigure(false)
        ->public();

    // The DBAL connection (DoctrineBundle's `database_connection`, config/packages/doctrine.php)
    // gets its parameters at runtime: this decorator of the bundle's connection factory derives
    // them from the boot configuration when the connection is first fetched (and throws a
    // \LogicException for a site whose database is not SQL; on an SQLite site it adds the
    // SqliteConnectionSetup middleware). The RDS_* variables are read for a
    // `beanstalk-mysql` site, as LegacyServiceFactory::db() reads them. Fetching the connection
    // does not connect; nothing may fetch it while the kernel boots.
    $services->set(BootConfigConnectionFactory::class)
        ->decorate('doctrine.dbal.connection_factory')
        ->args([
            service('.inner'),
            service(BootConfig::class),
            [
                'RDS_HOSTNAME' => '%env(default::RDS_HOSTNAME)%',
                'RDS_USERNAME' => '%env(default::RDS_USERNAME)%',
                'RDS_PASSWORD' => '%env(default::RDS_PASSWORD)%',
                'RDS_PORT' => '%env(default::RDS_PORT)%',
                'RDS_DB_NAME' => '%env(default::RDS_DB_NAME)%',
            ],
        ])
        ->autowire(false)
        ->autoconfigure(false);

    // The storage services. All three need the legacy application (the record store reads its
    // settings and the site URL, the access context its session and ignore-access flag), so, like
    // the logger, they must not be fetched before it has booted, nor in a kernel built with
    // bootLegacy: false; nothing fetches them while the kernel boots. They are public because
    // legacy code will fetch them with Idno::container()->get(). Nothing from the configuration is
    // compiled in: the store is built by LegacyRecordStoreFactory when first fetched, and throws a
    // \LogicException on a site whose database is neither MySQL nor SQLite (the connection is
    // fetched only after that check).
    // Set explicitly (not only found by the scan), like LegacySiteSettings below, so the
    // definitions exist whatever the scan finds.
    $services->set(LegacyRecordDefaults::class);
    $services->set(LegacyAccessContext::class);
    $services->set(LegacyRecordStoreFactory::class)
        ->args([service_closure('database_connection'), service(BootConfig::class), service(LegacyRecordDefaults::class)]);
    $services->set(RecordStore::class)
        ->factory([service(LegacyRecordStoreFactory::class), 'create'])
        ->public();
    $services->alias(AccessContext::class, LegacyAccessContext::class)->public();
    $services->set(EntityRepository::class)->public();

    $services->set(LegacySiteSettings::class)
        ->args([service(\Idno\Core\Config::class), service(BootConfig::class)]);
    $services->alias(SiteSettings::class, LegacySiteSettings::class)->public();
};
