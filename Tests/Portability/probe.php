<?php

/**
 * Subprocess helper for the portability tests: starts Idno on the test rig's database and runs one
 * Migration operation the way a web request would (a current page, a request address, optionally a
 * logged-in user), then prints the outcome as one JSON line on standard output.
 *
 *     php probe.php <operation> <json arguments>
 *
 * Operations:
 *     rss             Migration::getExportRSS(hide_private, user_uuid, wxr_mode), as user "login" (a UUID, or none).
 *                     Prints the document and the path of the file it was read from.
 *     archive_folder  Migration::archiveExportFolder("folder"), the path of an export folder made earlier.
 *     create_archive  Migration::createCompressedArchive("dir"): the export, the archive, the folder removed.
 *
 * It runs in its own process because the code under test cannot run inside PHPUnit: the RSS template
 * sends a header, which PHPUnit's own output has already made impossible, and PharData, which builds the
 * archive, keeps what it has read of an archive file in the process, so a second archive of the same file
 * name in one process crashes PHP (a web request makes one).
 */

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

$operation = $argv[1] ?? '';
$arguments = json_decode($argv[2] ?? '{}', true) ?: [];

include dirname(__DIR__) . '/_bootstrap.php';

// Pages and templates ask for the address of the current page, which the command line does not have
$_SERVER['REQUEST_URI'] = $arguments['uri'] ?? '/';

if (!empty($arguments['login'])) {
    $user = \Idno\Entities\User::getByUUID($arguments['login']);
    \Idno\Core\Idno::site()->session()->logUserOn($user);
}

$outcome = [];
switch ($operation) {
    case 'rss':
        \Idno\Core\Idno::site()->setCurrentPage(new \Idno\Pages\Admin\Export\RSS());
        $stream = \Idno\Core\Migration::getExportRSS(
            (bool) ($arguments['hide_private'] ?? true),
            (string) ($arguments['user_uuid'] ?? ''),
            (bool) ($arguments['wxr'] ?? false)
        );
        if (is_resource($stream)) {
            $outcome['file'] = stream_get_meta_data($stream)['uri'];
            $outcome['document'] = stream_get_contents($stream);
            fclose($stream);
        } else {
            $outcome['file'] = null;
            $outcome['document'] = null;
        }
        break;

    case 'archive_folder':
        \Idno\Core\Idno::site()->setCurrentPage(new \Idno\Pages\Admin\Export\Generate());
        $outcome['archive'] = \Idno\Core\Migration::archiveExportFolder((string) $arguments['folder']);
        break;

    case 'create_archive':
        \Idno\Core\Idno::site()->setCurrentPage(new \Idno\Pages\Admin\Export\Generate());
        $outcome['archive'] = \Idno\Core\Migration::createCompressedArchive($arguments['dir'] ?? false);
        break;
}

fwrite(STDOUT, json_encode(['stage' => 'done'] + $outcome, JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
