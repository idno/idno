#!/usr/bin/php -q
<?php
// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}
// Alias for bin/console, kept for backwards compatibility.
require_once __DIR__ . '/bin/console';
