<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for KernelBootTest: boots the kernel WITH the legacy application in
 * a clean PHP process (no PHPUnit handlers) and reports whether the PHP error handler
 * is the one Idno\Core\Logging installs, i.e. whether legacy still owns error handling.
 *
 * Usage: php boot_legacy_handlers.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Same environment as Tests/_bootstrap.php.
define('IDNO_UNIT_TEST', true);
$_SERVER['SERVER_NAME'] = $_SERVER['IDNO_DOMAIN'] ?? 'localhost';
$_SERVER['SERVER_PORT'] = $_SERVER['IDNO_PORT'] ?? 80;

ob_start();
$kernel = new \Idno\Kernel('test', true);
$kernel->boot();
ob_end_clean();

$current = set_error_handler(null);
restore_error_handler();

$legacyOwns = $current instanceof \Closure
    && (new \ReflectionFunction($current))->getClosureThis() === \Idno\Core\Idno::site()->logging();

echo json_encode(['legacy_owns_error_handler' => $legacyOwns]);
