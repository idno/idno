<?php

declare(strict_types=1);

use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Tests\Fixtures\LoggerConsumer;

/**
 * Test-environment only (the kernel loads config/services_<env>.php for the active
 * environment): services from Tests/Fixtures. Like the test routes, they do not exist in prod.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $builder->addResource(new FileExistenceResource(dirname(__DIR__) . '/Tests/Fixtures'));

    // Public so that tests can fetch it from the container that booted the legacy application.
    $container->services()
        ->defaults()->autowire()->autoconfigure()
        ->set(LoggerConsumer::class)->public();

    // "logger" is private; this public alias lets a test check which service it is.
    $container->services()->alias('idno.test.framework_logger', 'logger')->public();
};
