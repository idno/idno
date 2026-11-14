<?php

declare(strict_types=1);

/**
 * Router script for PHP's built-in web server, used by LegacyDispatcherTest: sends a
 * LegacyResponse the way index.php does, so the test can inspect the real HTTP response.
 *
 * Usage: php -S 127.0.0.1:<port> legacy_response_server.php, then GET /?scenario=plain|status|head
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Idno\Legacy\LegacyResponse;
use Symfony\Component\HttpFoundation\Request;

$request = Request::create('/', $_SERVER['REQUEST_METHOD'] ?? 'GET');

$response = match ($_GET['scenario'] ?? 'plain') {
    // Symfony-side headers and status on the object must not be sent; the legacy header() call must.
    'plain' => new LegacyResponse(static function (): void {
        header('X-Legacy: 1');
        echo 'legacy body';
    }, 500, ['X-Symfony' => '1', 'Cache-Control' => 'private']),
    'status' => new LegacyResponse(static function (): void {
        http_response_code(418);
        echo 'teapot';
    }),
    'head' => new LegacyResponse(static function (): void {
        header('X-Callback: ran');
    }),
};

// What the kernel's response listeners do to every response.
$response->prepare($request);
$response->send();
