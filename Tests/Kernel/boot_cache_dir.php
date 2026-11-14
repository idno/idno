<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for KernelBootTest: boots a non-debug (production-style) kernel in a
 * clean PHP process and reports its compiled-container directory.
 *
 * Usage: IDNO_CACHE_DIR=/tmp/x php boot_cache_dir.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$kernel = new \Idno\Kernel('prod', false, bootLegacy: false);
$kernel->boot();

echo json_encode([
    'booted' => $kernel->getContainer()->has('router'),
    'cache_dir' => $kernel->getCacheDir(),
]);
