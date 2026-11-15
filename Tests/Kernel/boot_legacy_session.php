<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for RepositoryDataConciergeTest: writes a session for a user with the OLD
 * backend's session handler (Idno\Data\MySQL::handleSession()), as a site running the code from
 * before Task 7 would have, and prints the session id. The session the boot itself started is
 * abandoned without being written.
 *
 * Usage: php boot_legacy_session.php <user uuid>
 */

$root = dirname(__DIR__, 2);
$userUuid = $argv[1] ?? '';

require $root . '/Idno/start.php';

if (session_status() === PHP_SESSION_ACTIVE) {
    session_abort();
}

$legacy = new \Idno\Data\MySQL();
$legacy->handleSession();
session_start();
$_SESSION['user_uuid'] = $userUuid;
$sessionId = session_id();
session_write_close();

echo json_encode(['writer' => get_class($legacy), 'session_id' => $sessionId]), "\n";
