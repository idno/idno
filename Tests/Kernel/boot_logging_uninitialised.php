<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for LoggerAutowiringTest: boots the kernel without the legacy
 * application in a clean PHP process and reports whether the Idno\Core\Logging service
 * has been constructed.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$kernel = new \Idno\Kernel('test', true, bootLegacy: false);
$kernel->boot();

echo json_encode(['logging_initialized' => $kernel->getContainer()->initialized(\Idno\Core\Logging::class)]);
