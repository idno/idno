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
 * ConnectionParameters::driverFamily() says the setting means.
 *
 * Usage: IDNO_DATABASE=<value> php boot_db_backend.php
 */

$root = dirname(__DIR__, 2);
require $root . '/Idno/start.php';

$site = \Idno\Core\Idno::site();
echo json_encode([
    'class' => get_class($site->db()),
    'family' => \Idno\Storage\ConnectionParameters::driverFamily(['database' => $site->config()->database]),
]), "\n";
