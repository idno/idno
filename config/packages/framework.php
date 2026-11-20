<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', [
        // Not a real secret: nothing in this phase uses it. Set APP_SECRET if that changes. Not an
        // IDNO_* variable: those are copied into the legacy config, which is saved to the database.
        'secret' => '%env(default:idno.kernel_secret_placeholder:APP_SECRET)%',
        'http_method_override' => false,
        'handle_all_throwables' => false,
        // The legacy application owns sessions and error handling for now.
        'session' => ['enabled' => false],
        'php_errors' => ['log' => false, 'throw' => false],
        'router' => ['utf8' => true],
        // The site's HTTP clients are Idno's own (config/services.php: idno.http_client and
        // idno.http_client.remote); FrameworkBundle must not register a second http_client.
        'http_client' => ['enabled' => false],
    ]);
};
