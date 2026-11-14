<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

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
            $services->load('Idno\\' . $dir . '\\', $path . '/');
        }
    }
};
