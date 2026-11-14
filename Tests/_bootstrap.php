<?php
// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

// Intentionally loading vendor libraries before bootstrapping from environment variables,
// so we can use .env files. require_once ensures that our call to start.php later on won't
// cause an issue, even though we're calling this twice.
if (file_exists(dirname(dirname(__FILE__)) . '/vendor/autoload.php')) {
    include_once dirname(dirname(__FILE__)) . '/vendor/autoload.php';
}

if (file_exists(dirname(dirname(__FILE__)) . '/.env')) {
    $dotenv = Dotenv\Dotenv::createUnsafeImmutable(dirname(dirname(__FILE__))); // @TODO remove unsafe once we've moved from getenv across the board
    $dotenv->load();
}

define('IDNO_UNIT_TEST', true);
if (!defined('KNOWN_UNIT_TEST')) {
    define('KNOWN_UNIT_TEST', IDNO_UNIT_TEST);
}

// Set some environment: Use export IDNO_DOMAIN / IDNO_PORT to override from the command line
$domain = 'localhost';
if (isset($_SERVER['IDNO_DOMAIN'])) {
    $domain = $_SERVER['IDNO_DOMAIN'];
} elseif (isset($_SERVER['KNOWN_DOMAIN'])) {
    $domain = $_SERVER['KNOWN_DOMAIN'];
}

if (!$domain && isset($_SERVER['SERVER_NAME'])) {
    $domain = $_SERVER['SERVER_NAME'];
}

$_SERVER['SERVER_NAME'] = $domain;

$port = getenv('IDNO_PORT') ?: getenv('KNOWN_PORT');
if (!$port && isset($_SERVER['SERVER_PORT'])) {
    $port = $_SERVER['SERVER_PORT'];
}
if (!$port) {
    $port = 80;
}
$_SERVER['SERVER_PORT'] = $port;

// Boot the kernel in the test environment, in debug mode so that a changed service
// definition is picked up without clearing the compiled container by hand.
$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '1';
putenv('APP_ENV=test');
putenv('APP_DEBUG=1');

try {
    // Load Idno framework (boots the kernel, which boots the legacy application)
    include_once dirname(dirname(__FILE__)) . '/Idno/start.php';

} catch (Exception $ex) {
    echo $ex->getMessage();
}
