<?php

    /**
     * Idno loader and all-purpose conductor
     *
     * @package    idno
     * @subpackage core
     */

// Register a function to catch premature shutdowns and output some friendlier text.
// Registered first, before the autoloader exists (as before), and so also covering the
// kernel boot; LegacyBootstrap registers it too, which is a no-op after this.
require_once __DIR__ . '/Legacy/FatalErrorPage.php';
\Idno\Legacy\FatalErrorPage::register();

// Load external libraries
if (file_exists(dirname(dirname(__FILE__)) . '/vendor/autoload.php')) {
    include_once dirname(dirname(__FILE__)) . '/vendor/autoload.php';
} else {
    http_response_code(500);

    $title = 'Installation incomplete';
    $heading = 'Your Idno installation is incomplete!';
    $body = '<p>It looks like you\'re running Idno directly from a GitHub checkout. You need to run "composer install" to fetch other required packages!</p>';
    $helplink = "<a href=\"http://docs.idno.co/en/latest/install/instructions/\">Read installation instructions.</a>";

    include dirname(dirname(__FILE__)) . '/statics/error-page.php';
    exit();
}

// Host for the purposes of extra paths
if (!empty($_SERVER['HTTP_HOST'])) {
    $host = strtolower($_SERVER['HTTP_HOST']);
    $host = str_replace('www.', '', $host);
    define('IDNO_MULTITENANT_HOST', $host);
    if (!defined('KNOWN_MULTITENANT_HOST')) {
        define('KNOWN_MULTITENANT_HOST', IDNO_MULTITENANT_HOST);
    }
}

// Boot the Symfony kernel, which boots the legacy application
// (see \Idno\Legacy\LegacyBootstrap).
try {
    (new \Idno\Kernel($_SERVER['APP_ENV'] ?? 'prod', (bool) ($_SERVER['APP_DEBUG'] ?? false)))->boot();
} catch (\Idno\Legacy\NotInstalledException $e) {
    header('Location: ./warmup/');
    exit; // Load the installer
}
