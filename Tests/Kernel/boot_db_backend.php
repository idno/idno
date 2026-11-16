<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for RepositoryDataConciergeTest: boots the application with the `database`
 * setting of the environment (IDNO_DATABASE) and prints the class of Idno::site()->db() and what
 * ConnectionParameters::driverFamily() says the setting means. With
 * IDNO_TEST_OLD_BACKEND_STAND_IN=1 it first defines a stand-in class under the old SQL backend's
 * name (OldSqlBackendStandIn.php, Idno\Data\MySQL) and also prints whether it was constructed.
 *
 * Usage: IDNO_DATABASE=<value> [IDNO_TEST_OLD_BACKEND_STAND_IN=1] php boot_db_backend.php
 */

$root = dirname(__DIR__, 2);
$standIn = getenv('IDNO_TEST_OLD_BACKEND_STAND_IN') === '1';
if ($standIn) {
    require $root . '/vendor/autoload.php';
    require __DIR__ . '/OldSqlBackendStandIn.php';
}
require $root . '/Idno/start.php';

$site = \Idno\Core\Idno::site();
$result = [
    'class' => get_class($site->db()),
    'family' => \Idno\Storage\ConnectionParameters::driverFamily(['database' => $site->config()->database]),
];
if ($standIn) {
    $result['standInConstructed'] = \Idno\Data\MySQL::$constructed;
}
echo json_encode($result), "\n";
