<?php

/**
 * Subprocess helper for Tests\Core\FatalErrorPageTest: starts Idno, then dies of an uncaught
 * error, so that the shutdown function in Idno/start.php draws the fatal-error page.
 *
 *     php fatal_error.php <mode>
 *
 * Modes:
 *     production  the site is not in debug mode
 *     debug       the site is in debug mode
 *     boot        the error happens while Idno starts, before the configuration is loaded
 *
 * The page is written to standard output. Run it with -d error_log=<file> to collect what
 * was logged.
 */

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Stands in for the configuration class in "boot" mode, so that Idno fails as soon as it
 * asks for its configuration.
 */
class FatalErrorTestConfig
{
    function __construct()
    {
        throw new \RuntimeException(FATAL_ERROR_TEST_MESSAGE);
    }
}

// Text an attacker could put in an error message and in the page address
define('FATAL_ERROR_TEST_MESSAGE', 'Could not open <script>alert(1)</script> for \'dbuser\'@"dbhost"');
define('FATAL_ERROR_TEST_URI', '/probe?q=<script>alert(2)</script>');

$mode = isset($argv[1]) ? $argv[1] : 'production';

$_SERVER['SERVER_NAME'] = 'probe.test';
$_SERVER['REQUEST_URI'] = FATAL_ERROR_TEST_URI;

if ($mode == 'boot') {
    class_alias('FatalErrorTestConfig', 'Idno\Core\Config');
    include dirname(__DIR__, 3) . '/Idno/start.php';
    exit;
}

include dirname(__DIR__, 2) . '/_bootstrap.php';

\Idno\Core\Idno::site()->config()->debug = ($mode == 'debug');
$_SERVER['SERVER_NAME'] = 'probe.test';

throw new \RuntimeException(FATAL_ERROR_TEST_MESSAGE);
