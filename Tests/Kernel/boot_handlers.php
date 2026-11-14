<?php

declare(strict_types=1);

/**
 * Subprocess helper for KernelBootTest: boots the kernel in a clean PHP process
 * (no PHPUnit handlers) and reports whether the error/exception handlers changed.
 *
 * Usage: php boot_handlers.php none|custom
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$scenario = $argv[1] ?? 'none';

$errorHandler = static fn (): bool => false;
$exceptionHandler = static function (): void {
};

if ($scenario === 'custom') {
    set_error_handler($errorHandler);
    set_exception_handler($exceptionHandler);
}

$kernel = new \Idno\Kernel('test', true, bootLegacy: false);
$kernel->boot();

$currentError = set_error_handler(null);
restore_error_handler();
$currentException = set_exception_handler(null);
restore_exception_handler();

echo json_encode([
    'error_unchanged' => $scenario === 'custom' ? $currentError === $errorHandler : $currentError === null,
    'exception_unchanged' => $scenario === 'custom' ? $currentException === $exceptionHandler : $currentException === null,
]);
