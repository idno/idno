<?php

declare(strict_types=1);

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
