<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for SchemaGateBootTest: boots through Idno/start.php, as a third-party script
 * does, on the database the environment names (IDNO_DBNAME), and prints `booted` with whether the
 * legacy application exists. While the schema gate is closed start.php prints its one line on
 * stderr and exits 1 before this script goes on.
 *
 * Usage: IDNO_DBNAME=<database> php boot_schema_gate.php
 */

require dirname(__DIR__, 2) . '/Idno/start.php';

echo 'booted ' . (\Idno\Core\Idno::site() !== null ? 'with' : 'without') . ' the legacy application' . "\n";
