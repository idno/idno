<?php

/**
 * Router script for PHP's built-in web server (`php -S`), used by the local test rig.
 *
 * Existing non-PHP files under the repository root are served as static files;
 * everything else goes through the front controller.
 */

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = realpath($root . rawurldecode($path));

if ($file !== false
    && is_file($file)
    && str_starts_with($file, $root . DIRECTORY_SEPARATOR)
    && strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php'
) {
    return false;
}

chdir($root);
// Idno derives its subdirectory from PHP_SELF, which the built-in server sets to the request path.
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
