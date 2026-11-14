<?php

/**
 * Idno index page and router.
 * It all starts here!
 *
 * If you're wondering what this is all about, you could do worse than
 * check out the README.md file.
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

// Load the idno framework

require_once dirname(__FILE__) . '/Idno/start.php';

// Get page routes

$routes = \Idno\Core\Idno::site()->routes();

// Get subdirectory

$url = \Idno\Core\Idno::site()->config()->getURL();
$path = parse_url($url, PHP_URL_PATH);
if (substr($path, -1) == '/') {
    $path = substr($path, 0, -1);
}
if (!empty($path)) {
    if (!empty($routes['/'])) {
        $routes[$path . '/'] = $routes['/'];
    }
}

// Manage routing

\Idno\Core\PageHandler::hook(
    '404', function ($params = array()) {
        http_response_code(404);
        $t = \Idno\Core\Idno::site()->template();

        // Take over page detection
        \Idno\Core\Idno::site()->template()->autodetectTemplateType();

        (new \Idno\Pages\Homepage())->noContent();
    }
);
\Idno\Core\PageHandler::serve($routes);
