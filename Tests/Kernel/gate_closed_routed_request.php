<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for SchemaGateBootTest: boots the kernel as the front controller does, on the database the environment names (IDNO_DBNAME), and
 * handles a request for the address of a page routed by the legacy table while the schema gate is closed. Prints one line of JSON: whether the legacy
 * application booted, the status, whether the legacy router's dispatcher was built, and whether the request was given the router's choice or routed.
 *
 * Usage: IDNO_DBNAME=<database> php gate_closed_routed_request.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$kernel = \Idno\Kernel::fromGlobals();
$kernel->boot();
$request = \Symfony\Component\HttpFoundation\Request::create('/_test/routed/word/abc');
$response = $kernel->handle($request);

echo json_encode([
    'legacy' => $kernel->legacyBooted(),
    'status' => $response->getStatusCode(),
    'dispatcher' => $kernel->getContainer()->initialized(\Idno\Legacy\LegacyDispatcher::class),
    'choice' => $request->attributes->has('_idno_legacy_choice'),
    'routed' => $request->attributes->has('_idno_legacy_routed'),
]) . "\n";
