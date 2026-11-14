<?php

declare(strict_types=1);

/**
 * Subprocess helper for LoggerAutowiringTest: boots the kernel without the legacy
 * application in a clean PHP process and reports whether the Idno\Core\Logging service
 * has been constructed.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$kernel = new \Idno\Kernel('test', true, bootLegacy: false);
$kernel->boot();

echo json_encode(['logging_initialized' => $kernel->getContainer()->initialized(\Idno\Core\Logging::class)]);
