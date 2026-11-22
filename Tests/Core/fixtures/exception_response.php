<?php

/**
 * Subprocess helper for Tests\Core\ExceptionResponseTest: starts Idno, asks for a page in the
 * given template type, and throws an exception while the page is being served, so that
 * Page::exception() answers with that template type's page shell.
 *
 *     php exception_response.php <mode> <template type> [<message>]
 *
 * Modes:
 *     production  the site is not in debug mode
 *     debug       the site is in debug mode
 *
 * Template types: json, jsonp
 *
 * Messages:
 *     friendly    the exception's message is written for the visitor (the default)
 *     technical   the exception's type marks its message as a technical one
 *
 * The response body is written to standard output. Run it with -d error_log=<file> to collect
 * what was logged.
 */

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

// Text an attacker could put in an exception message
define('EXCEPTION_RESPONSE_TEST_MESSAGE', 'Could not open <script>alert(1)</script> for \'dbuser\'@"dbhost"');

$mode = isset($argv[1]) ? $argv[1] : 'production';
$template = isset($argv[2]) ? $argv[2] : 'json';
$message = isset($argv[3]) ? $argv[3] : 'friendly';

include dirname(__DIR__, 2) . '/_bootstrap.php';

\Idno\Core\Idno::site()->config()->debug = ($mode == 'debug');

// The page shell asks for the address of the current page, which the command line doesn't have
$_SERVER['REQUEST_URI'] = '/';

// Page::init() sets the template type from the _t input, and installs the exception handler
// that hands uncaught exceptions to Page::exception().
$page = new \Idno\Pages\Homepage();
$page->setInput('_t', $template);
$page->init();

if ($message == 'technical') {
    throw new \Idno\Exceptions\TechnicalException(EXCEPTION_RESPONSE_TEST_MESSAGE);
}

throw new \RuntimeException(EXCEPTION_RESPONSE_TEST_MESSAGE);
