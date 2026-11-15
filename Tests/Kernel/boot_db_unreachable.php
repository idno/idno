<?php

declare(strict_types=1);

// PHP's built-in web server only: anywhere else, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

/**
 * Router script for PHP's built-in web server, used by RepositoryDataConciergeTest: serves every
 * request through index.php, as the rig's router does, with the IDNO_* and APP_* variables of the
 * server's environment (the built-in server does not copy them into $_SERVER). The test starts it
 * with IDNO_DBPORT set to a closed port, to see what a site whose database is unreachable answers.
 *
 * Usage: IDNO_DBHOST=127.0.0.1 IDNO_DBPORT=<closed port> php -S 127.0.0.1:<port> boot_db_unreachable.php
 */

$root = dirname(__DIR__, 2);
foreach (getenv() as $name => $value) {
    if (str_starts_with($name, 'IDNO_') || $name === 'APP_ENV' || $name === 'APP_DEBUG') {
        $_SERVER[$name] = $value;
    }
}

chdir($root);
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
