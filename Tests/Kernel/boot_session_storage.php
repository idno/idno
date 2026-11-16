<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for RepositoryDataConciergeTest: boots the application with the session settings
 * of the environment (IDNO_SESSIONS_DATABASE, IDNO_SESSIONS_STORAGE), with the given session cookie,
 * and prints what handles the session it started: PHP's save handler (`user` or `files`), whether the
 * session's advisory lock is held by the db() service's own connection (MySQL; null when no lock is
 * held, as with file sessions, and on other databases), the
 * `user_uuid` the session held when it was read, and, after it writes `probe` and closes the session,
 * whether its row is in the `session` table (a file session is destroyed before it exits).
 *
 * Usage: IDNO_SESSIONS_STORAGE=<class> php boot_session_storage.php <cookie name> [<session id>]
 */

$root = dirname(__DIR__, 2);
if (($argv[2] ?? '') !== '') {
    $_COOKIE[$argv[1]] = $argv[2];
}

require $root . '/Idno/start.php';

$site = \Idno\Core\Idno::site();
$pdo = $site->db()->getClient();
$sessionId = session_id();
$sharedLock = null;
if ($pdo instanceof \PDO && $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
    $holder = $pdo->prepare('select is_used_lock(?)');
    $holder->execute([substr($sessionId, 0, 64)]);
    $lockHolder = $holder->fetchColumn();
    $sharedLock = $lockHolder === null ? null : (string) $lockHolder === (string) $pdo->query('select connection_id()')->fetchColumn();
}
$read = $_SESSION['user_uuid'] ?? null;

$_SESSION['probe'] = 'written';
session_write_close();
$row = $pdo->prepare('select session_value from session where session_id = ?');
$row->execute([$sessionId]);
$value = $row->fetchColumn();
$saveHandler = ini_get('session.save_handler');
if ($saveHandler === 'files') {
    // Leave no session file behind (a database session is deleted by the test).
    session_start();
    session_destroy();
}

echo json_encode([
    'save_handler' => $saveHandler,
    'shared_lock' => $sharedLock,
    'read' => $read,
    'session_id' => $sessionId,
    'row' => is_string($value) && str_contains($value, 'probe|s:7:"written"'),
]), "\n";
