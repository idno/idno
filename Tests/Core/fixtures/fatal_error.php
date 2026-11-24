<?php

/**
 * Subprocess helper for Tests\Core\FatalErrorPageTest: starts Idno, then dies of an uncaught
 * error, so that the shutdown function (Idno\Legacy\FatalErrorPage) draws the fatal-error page.
 *
 *     php fatal_error.php <mode>
 *
 * Modes:
 *     production  the site is not in debug mode
 *     debug       the site is in debug mode
 *     boot        the error happens while the kernel boots, before the legacy application
 *                 (and so the configuration) exists
 *
 * The page is written to standard output. Run it with -d error_log=<file> to collect what
 * was logged.
 */

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

// Text an attacker could put in an error message and in the page address
define('FATAL_ERROR_TEST_MESSAGE', 'Could not open <script>alert(1)</script> for \'dbuser\'@"dbhost"');
define('FATAL_ERROR_TEST_URI', '/probe?q=<script>alert(2)</script>&access_token=tok-9f3a7c');

$mode = isset($argv[1]) ? $argv[1] : 'production';

$_SERVER['SERVER_NAME'] = 'probe.test';
$_SERVER['REQUEST_URI'] = FATAL_ERROR_TEST_URI;

if ($mode == 'boot') {
    // As index.php does: the page is registered first, then the autoloader is loaded and the
    // kernel booted. Here the kernel fails while it boots.
    require dirname(__DIR__, 3) . '/Idno/Legacy/FatalErrorPage.php';
    \Idno\Legacy\FatalErrorPage::register();
    require dirname(__DIR__, 3) . '/vendor/autoload.php';

    $kernel = new class ('prod', false) extends \Idno\Kernel {
        public function boot(): void
        {
            throw new \RuntimeException(FATAL_ERROR_TEST_MESSAGE);
        }
    };
    $kernel->boot();
    exit;
}

include dirname(__DIR__, 2) . '/_bootstrap.php';

\Idno\Core\Idno::site()->config()->debug = ($mode == 'debug');
$_SERVER['SERVER_NAME'] = 'probe.test';

throw new \RuntimeException(FATAL_ERROR_TEST_MESSAGE);
