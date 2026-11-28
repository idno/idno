<?php

/**
 * Subprocess helper for Tests\Core\PerDomainConfigTest: starts Idno as the host named by the
 * IDNO_DOMAIN environment variable and prints the site's title as JSON.
 *
 *     IDNO_DOMAIN=<host> php per_domain_config.php
 */

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

ob_start();
include dirname(__DIR__, 2) . '/_bootstrap.php';
ob_end_clean();

echo json_encode(['title' => \Idno\Core\Idno::site()->config()->title]);
