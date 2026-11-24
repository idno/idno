<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for CsrfAdapterTest: boots as a command-line process does and draws `forms/token` twice and signs
 * a form, as the export and the action links do, printing what was drawn, whether the drawing changed the session's
 * state and whether it sent a header.
 */
define('IDNO_CONSOLE', true);
require dirname(__DIR__, 2) . '/Idno/start.php';

$before = session_status();
$template = \Idno\Core\Idno::site()->template();
$first = (string) $template->__(['action' => '/a', 'time' => 1])->draw('forms/token');
$second = (string) $template->__(['action' => '/b', 'time' => 2])->draw('forms/token');
$signed = (string) \Idno\Core\Idno::site()->actions()->signForm('/x');

echo json_encode([
    'first' => $first,
    'second' => $second,
    'signed' => $signed,
    'statusChanged' => $before !== session_status(),
    'headers' => headers_list(),
]), "\n";
