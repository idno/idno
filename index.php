<?php

/**
 * Idno front controller: every HTTP request starts here.
 *
 * The Symfony kernel (Idno\Kernel) handles the request. Routes defined the new way
 * (controllers with #[Route] attributes) are matched first; everything else is served by
 * the legacy router through Idno\Legacy\LegacyController. See AGENTS.md.
 *
 * Project homepage:    https://idno.co/
 * Project repo:        https://github.com/idno/idno
 *
 * @package    idno
 * @subpackage core
 */

// Check PHP version first of all
if (version_compare(phpversion(), '8.4', '<')) {
    http_response_code(500);
    $body = "Sorry, this version of PHP (".phpversion().") is not supported. This probably means that you should update your server to the latest stable PHP release.";
    $heading = "PHP Version not supported";
    $helplink = '<a href="http://docs.idno.co/en/latest/install/requirements/" target="_blank">Read system requirements</a>';

    include dirname(__FILE__) . '/statics/error-page.php';
    exit;
}

// Register a function to catch premature shutdowns and output some friendlier text.
// Registered first, before the autoloader exists, so that it also covers the kernel boot.
require_once __DIR__ . '/Idno/Legacy/FatalErrorPage.php';
\Idno\Legacy\FatalErrorPage::register();

// Load external libraries
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    include_once __DIR__ . '/vendor/autoload.php';
} else {
    http_response_code(500);

    $title = 'Installation incomplete';
    $heading = 'Your Idno installation is incomplete!';
    $body = '<p>It looks like you\'re running Idno directly from a GitHub checkout. You need to run "composer install" to fetch other required packages!</p>';
    $helplink = "<a href=\"http://docs.idno.co/en/latest/install/instructions/\">Read installation instructions.</a>";

    include __DIR__ . '/statics/error-page.php';
    exit();
}

// Host for the purposes of extra paths
\Idno\Legacy\LegacyBootstrap::defineMultitenantHost();

// Handle the request. FrontController::handle() boots the kernel, which boots the legacy
// application (Idno\Legacy\LegacyBootstrap); a site with no configuration yet is
// redirected to the installer.
$kernel   = \Idno\Kernel::fromGlobals();
$request  = \Idno\Http\FrontController::requestFromGlobals();
$response = \Idno\Http\FrontController::handle($kernel, $request);
$response->send();
$kernel->terminate($request, $response);
