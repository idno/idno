<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for ConsoleTemplatesTest: boots as a command-line process does (IDNO_CONSOLE, through
 * Idno/start.php) and prints the length of two core templates drawn as they are for a mail.
 */
define('IDNO_CONSOLE', true);
require dirname(__DIR__, 2) . '/Idno/start.php';

$lengths = [];
foreach (['email-text', 'email'] as $type) {
    $template = clone \Idno\Core\Idno::site()->template();
    $template->setTemplateType($type);
    $lengths[$type] = strlen((string) $template->__(['unrendered' => 0])->draw('admin/export'));
}
echo json_encode($lengths), "\n";
