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

// Boot the Symfony kernel, which boots the legacy application
// (see \Idno\Legacy\LegacyBootstrap). This only boots: HTTP requests are handled by
// index.php (\Idno\Http\FrontController); code that includes this file gets a booted
// legacy application. A site that is not installed says so on stderr and exits 1. While the schema gate is closed (the database needs creating or
// upgrading) the legacy application cannot boot: this says so on stderr and exits 1.
$kernel = \Idno\Kernel::fromGlobals();
$kernel->boot();
if (!$kernel->installed()) {
    file_put_contents('php://stderr', \Idno\Install\InstallGateListener::NOT_INSTALLED . "\n");
    http_response_code(503);
    exit(1);
}
if (!$kernel->legacyBooted()) {
    file_put_contents('php://stderr', $kernel->getContainer()->get(\Idno\Upgrade\SchemaGate::class)->closedMessage() . "\n");
    http_response_code(503);
    exit(1);
}
unset($kernel);
