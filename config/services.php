<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('idno.kernel_secret_placeholder', 'idno-kernel-placeholder-not-a-secret');

    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Only these directories are scanned; the rest of Idno/ is legacy code.
    foreach (['Kernel', 'Config', 'Http', 'Console', 'Legacy'] as $dir) {
        if (is_dir(dirname(__DIR__) . '/Idno/' . $dir)) {
            $services->load('Idno\\' . $dir . '\\', '../Idno/' . $dir . '/');
        }
    }
};
