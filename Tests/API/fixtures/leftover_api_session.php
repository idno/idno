<?php

/**
 * Subprocess helper for Tests\API\SignedRequestSessionTest: stores a session as earlier versions
 * left one behind after a request signed with an API key (marked as an API request, holding the
 * member), and prints its id.
 *
 *     php leftover_api_session.php <the member's UUID>
 */

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

ob_start();

include dirname(__DIR__, 2) . '/_bootstrap.php';

$_SESSION['is_api_request'] = true;
$_SESSION['user_uuid'] = (string) $argv[1];
$id = session_id();
session_write_close();

ob_end_clean();
echo $id;
