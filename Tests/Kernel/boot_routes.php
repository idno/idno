<?php

declare(strict_types=1);

/**
 * Subprocess helper for RoutingTest: boots a kernel without the legacy application in a
 * clean PHP process and reports which of the named routes its router has.
 *
 * Usage: IDNO_CACHE_DIR=/tmp/x php boot_routes.php <env> <0|1 debug>
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$kernel = new \Idno\Kernel($argv[1] ?? 'prod', ($argv[2] ?? '0') === '1', bootLegacy: false);
$kernel->boot();

$routes = $kernel->getContainer()->get('router')->getRouteCollection();

echo json_encode([
    'idno_legacy' => $routes->get('idno_legacy')?->getPath(),
    'idno_test_ping' => $routes->get('idno_test_ping')?->getPath(),
]);
