<?php

declare(strict_types=1);

use Idno\Config\BootConfig;
use Idno\Config\LegacyConfigLoader;
use Idno\Config\SiteSettings;
use Idno\Kernel\CodeFingerprint;
use Idno\Legacy\LegacyBootstrap;
use Idno\Legacy\LegacyServiceFactory;
use Idno\Legacy\LegacySiteSettings;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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
        'Config' => ['BootConfig.php'],
        // Static methods only, used by index.php before the container exists.
        'Http' => ['FrontController.php'],
        // Takes the kernel (so cannot be a container service); functions.php is not a class.
        'Console' => ['Application.php', 'functions.php'],
        // Value objects (a query tree and its nodes), built with new, not services.
        'Storage' => ['Criteria.php', 'Criterion'],
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

    $services->set(LegacySiteSettings::class)
        ->args([service(\Idno\Core\Config::class), service(BootConfig::class)]);
    $services->alias(SiteSettings::class, LegacySiteSettings::class)->public();
};
