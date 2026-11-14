<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        // Not a real secret: nothing in this phase uses it. Override with IDNO_KERNEL_SECRET if that changes.
        'secret' => '%env(default:idno.kernel_secret_placeholder:IDNO_KERNEL_SECRET)%',
        'http_method_override' => false,
        'handle_all_throwables' => false,
        // The legacy application owns sessions and error handling for now.
        'session' => ['enabled' => false],
        'php_errors' => ['log' => false, 'throw' => false],
        'router' => ['utf8' => true],
    ]);
};
