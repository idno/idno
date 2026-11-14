<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for KernelExceptionTest: in a clean PHP process, handles one request with
 * a kernel that has NOT booted the legacy application, and reports what came back. Run it
 * with -d error_log=<file> to collect what was logged.
 *
 * Usage: php -d error_log=<file> boot_handled_exception.php before-routing|controller|legacy
 *
 * "before-routing": a kernel.request listener throws a \RuntimeException.
 * "controller": GET /_test/throw (Tests\Fixtures\ThrowingController throws).
 * "legacy": GET /some/legacy/path, which the legacy catch-all matches (the response is not sent).
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

$scenario = $argv[1] ?? 'before-routing';

$kernel = new \Idno\Kernel('test', true, bootLegacy: false);
$kernel->boot();

$path = '/some/legacy/path';
if ($scenario === 'before-routing') {
    $kernel->getContainer()->get('event_dispatcher')->addListener(
        KernelEvents::REQUEST,
        static function (RequestEvent $event): void {
            // Main request only, so that Symfony's error page (a sub-request) can render.
            if ($event->isMainRequest()) {
                throw new \RuntimeException('thrown before routing by a test listener');
            }
        },
        4096
    );
} elseif ($scenario === 'controller') {
    $path = '/_test/throw';
}

$errorHandler = set_error_handler(null);
restore_error_handler();

$result = ['thrown' => null, 'message' => null, 'status' => null, 'response_class' => null];
try {
    $response = $kernel->handle(Request::create($path));
    $result['status'] = $response->getStatusCode();
    $result['response_class'] = get_class($response);
} catch (\Throwable $e) {
    $result['thrown'] = get_class($e);
    $result['message'] = $e->getMessage();
}

$after = set_error_handler(null);
restore_error_handler();
$result['error_handler_unchanged'] = $after === $errorHandler;

echo json_encode($result);
