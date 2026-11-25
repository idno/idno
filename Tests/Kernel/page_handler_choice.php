<?php

// Run by PageHandlerChoiceTest in a process of its own: IDNO_SUBDIRECTORY is a constant. Prints the router's choices as JSON.

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

define('IDNO_SUBDIRECTORY', 'blog');

$routes = new Idno\Core\PageHandler();
$routes->addRoute('/_test/claimable/?', Tests\Fixtures\ReplacedPage::class);

echo json_encode([
    'prefixed' => Idno\Core\PageHandler::chosenFor($routes, '/blog/_test/claimable'),
    'prefixedDoubled' => Idno\Core\PageHandler::chosenFor($routes, '//blog/_test/claimable/'),
    'bare' => Idno\Core\PageHandler::chosenFor($routes, '/_test/claimable'),
]);
