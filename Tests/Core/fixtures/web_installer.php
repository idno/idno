<?php

/**
 * Runs one request to the web installer in its own PHP process, writing the installation
 * into a folder given by the test instead of into this checkout. Used by
 * Tests/Core/InstallerFlowTest.php.
 *
 *     php web_installer.php <install folder> <GET|POST> <stage> <JSON of the fields sent>
 *
 * Prints the page. A redirect is printed as "REDIRECT: <address>".
 */

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';
require_once $root . '/warmup/WebInstaller.php';

$_SERVER['REQUEST_METHOD'] = $argv[2];
$_SERVER['HTTP_HOST'] = 'installer.test';
$fields = (array) json_decode($argv[4] ?? '{}', true);
$_GET = $argv[3] !== '' ? ['stage' => $argv[3]] : [];
if ($argv[2] === 'POST') {
    $_POST = $fields;
} else {
    // Fields of a GET request are in the address
    $_GET += $fields;
}
$_REQUEST = array_merge($_GET, $_POST);

$installer = new class($argv[1]) extends WebInstaller {
    protected function redirect($location)
    {
        echo 'REDIRECT: ' . $location;
        exit;
    }
};
$installer->run();
