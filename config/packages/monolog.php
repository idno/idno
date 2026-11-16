<?php

declare(strict_types=1);

use Idno\Legacy\VerbatimLogFormatter;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // Idno\Core\Logging builds the complete line ("Idno (host): level - message") and
    // filters by the site's log level, so this channel writes the message verbatim.
    $container->services()
        ->set('idno.log_formatter', VerbatimLogFormatter::class)
        ->private();

    $container->extension('monolog', [
        // `upgrade`: the upgrade (Idno\Upgrade\: the copy of a 1.x database, the runner, the step
        // endpoint), which runs while the legacy application, and so the "idno" channel's logger, is
        // not booted. The "symfony" handler writes it (warning and above); it logs failures only.
        'channels' => ['idno', 'upgrade'],
        'handlers' => [
            // error_log() with message type 0: the same call, and the same "error_log"
            // ini setting, as the logger used before Monolog. No error handler is registered.
            'idno' => [
                'type' => 'error_log',
                'level' => 'debug',
                'channels' => ['idno'],
                'formatter' => 'idno.log_formatter',
            ],
            // Symfony's own records (the request, console, php ... channels): exceptions that
            // Symfony handles, before routing or from a new-style controller, would otherwise
            // be logged nowhere. Warning and above, in Monolog's standard line format (with
            // channel and level), to PHP's error log like the "idno" channel. It registers no
            // error handler.
            'symfony' => [
                'type' => 'error_log',
                'level' => 'warning',
                'channels' => ['!idno'],
            ],
        ],
    ]);
};
