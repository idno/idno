<?php

declare(strict_types=1);

use Idno\Config\BootConfig;
use Idno\Config\LegacyConfigLoader;
use Idno\Config\SiteSettings;
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

    // Only these directories are scanned; the rest of Idno/ is legacy code.
    foreach (['Kernel', 'Config', 'Http', 'Console', 'Legacy'] as $dir) {
        $path = $projectDir . '/Idno/' . $dir;
        // fileExists() records the check as a container resource, so creating the
        // directory later triggers a recompile in debug mode.
        if ($builder->fileExists($path)) {
            $services->load('Idno\\' . $dir . '\\', $path . '/')
                // Wired explicitly below.
                ->exclude([$path . '/BootConfig.php', $path . '/LegacySiteSettings.php']);
        }
    }

    // BootConfig is built at runtime from config files and the environment by a
    // factory, so none of its values are compiled into the cached container.
    $services->set(BootConfig::class)
        ->factory([LegacyConfigLoader::class, 'fromGlobals'])
        ->args(['%kernel.project_dir%'])
        ->public();

    // Idno\Core\Config is not a service yet; the factory reads it from the legacy
    // singleton when SiteSettings is first requested.
    $services->set(LegacySiteSettings::class)
        ->factory([LegacySiteSettings::class, 'fromLegacySite'])
        ->args([service(BootConfig::class)]);
    $services->alias(SiteSettings::class, LegacySiteSettings::class)->public();
};
