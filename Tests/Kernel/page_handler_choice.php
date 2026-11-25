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

$front = static fn (string $uri): Symfony\Component\HttpFoundation\Request => new Symfony\Component\HttpFoundation\Request([], [], [], [], [], [
    'REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/blog/index.php', 'PHP_SELF' => '/blog/index.php', 'SCRIPT_FILENAME' => '/srv/blog/index.php', 'HTTP_HOST' => 'localhost',
]);
$routes->addRoute('/?', Tests\Fixtures\ClaimingPage::class);
$classes = static fn (string $uri): array => array_values(array_unique(array_map(static fn (array $claim): string => $claim['class'], Idno\Core\PageHandler::claimsOf($routes, $front($uri), ['/_test/claimable/?', '/?']))));

echo json_encode([
    'keys' => Idno\Core\PageHandler::keysFor('/_test/claimable/?'),
    'frontController' => $classes('/blog/index.php/_test/claimable'),
    'frontControllerHome' => $classes('/blog/index.php'),
    'frontControllerHomeSlash' => $classes('/blog/index.php/'),
    'encodedBase' => $classes('/bl%6Fg/index.php/_test/claimable'),
    'prefixed' => Idno\Core\PageHandler::chosenFor($routes, '/blog/_test/claimable'),
    'prefixedDoubled' => Idno\Core\PageHandler::chosenFor($routes, '//blog/_test/claimable/'),
    'bare' => Idno\Core\PageHandler::chosenFor($routes, '/_test/claimable'),
]);
