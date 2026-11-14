<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for FatalErrorPageTest: registers the fatal-error page as index.php
 * does, then lets the kernel fail while booting, before the legacy application exists.
 * Run it with -d error_log=<file> to collect what was logged.
 */

require dirname(__DIR__, 2) . '/Idno/Legacy/FatalErrorPage.php';
\Idno\Legacy\FatalErrorPage::register();

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$_SERVER['SERVER_NAME'] = 'probe.test';
$_SERVER['REQUEST_URI'] = '/probe';

$kernel = new class ('prod', false) extends \Idno\Kernel {
    public function boot(): void
    {
        throw new \RuntimeException('the kernel failed to boot');
    }
};
$kernel->boot();
