<?php

declare(strict_types=1);

use Monolog\Formatter\LineFormatter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // Idno\Core\Logging builds the complete line ("Idno (host): level - message") and
    // filters by the site's log level, so this channel writes the message as it is.
    $container->services()
        ->set('idno.log_formatter', LineFormatter::class)
        ->args(['%%message%%', null, true, true])
        ->private();

    $container->extension('monolog', [
        'channels' => ['idno'],
        'handlers' => [
            // error_log() with message type 0: the same call, and the same "error_log"
            // ini setting, as the logger used before Monolog. No error handler is registered.
            'idno' => [
                'type' => 'error_log',
                'level' => 'debug',
                'channels' => ['idno'],
                'formatter' => 'idno.log_formatter',
            ],
        ],
    ]);
};
