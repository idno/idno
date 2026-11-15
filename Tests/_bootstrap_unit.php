<?php
// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

// The dependency-free test tier: Composer's autoloader and nothing else. No kernel, no
// configuration, no database, no network (see AGENTS.md).
require dirname(__DIR__) . '/vendor/autoload.php';
