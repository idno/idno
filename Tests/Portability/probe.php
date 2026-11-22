<?php

/**
 * Subprocess helper for the portability tests: starts Idno on the test rig's database and runs one
 * export or archive operation the way a web request would (a current page, a request address, optionally a
 * logged-in user), then prints the outcome as one JSON line on standard output.
 *
 *     php probe.php <operation> <json arguments>
 *
 * Operations:
 *     rss             FeedExporter::stream(hide_private, user_uuid, wxr_mode), as user "login" (a UUID, or none).
 *                     Prints the document, and the names the export added to the temporary directory (none).
 *     rss_delegate    The same through the delegate Migration::getExportRSS().
 *     archive_folder  Migration::archiveExportFolder("folder"), the path of an export folder made earlier.
 *     create_archive  Migration::createCompressedArchive("dir"): the export, the archive, the folder removed.
 *     export_large    Stores a file of "mib" MiB (zeros), attaches it to an article, runs ArchiveExporter::export() as user
 *                     "login" and prints the peak memory and the size of the stored archive; everything is removed again.
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
    case 'rss_delegate':
        \Idno\Core\Idno::site()->setCurrentPage(new \Idno\Pages\Admin\Export\RSS());
        $temporary = rtrim(sys_get_temp_dir(), '/');
        $before = scandir($temporary) ?: [];
        if ($operation === 'rss') {
            $stream = \Idno\Core\Idno::container()->get(\Idno\Portability\FeedExporter::class)->stream(
                (bool) ($arguments['hide_private'] ?? true),
                (string) ($arguments['user_uuid'] ?? '') === '' ? null : (string) $arguments['user_uuid'],
                (bool) ($arguments['wxr'] ?? false)
            );
        } else {
            $stream = \Idno\Core\Migration::getExportRSS(
                (bool) ($arguments['hide_private'] ?? true),
                (string) ($arguments['user_uuid'] ?? ''),
                (bool) ($arguments['wxr'] ?? false)
            );
        }
        if (is_resource($stream)) {
            $outcome['document'] = stream_get_contents($stream);
            fclose($stream);
        } else {
            $outcome['document'] = null;
        }
        $outcome['created'] = array_values(array_diff(scandir($temporary) ?: [], $before));
        break;

    case 'export_large':
        $admin = \Idno\Entities\User::getByUUID($arguments['login']);
        $files = \Idno\Core\Idno::container()->get(\Idno\Storage\Files\FileStore::class);
        $path = tempnam(sys_get_temp_dir(), 'idno-large-');
        $handle = fopen($path, 'wb');
        $megabyte = str_repeat("\0", 1048576);
        for ($i = 0; $i < (int) $arguments['mib']; $i++) {
            fwrite($handle, $megabyte);
        }
        fclose($handle);
        unset($megabyte);
        $stream = fopen($path, 'rb');
        $fileId = $files->store($stream, ['filename' => 'large.bin', 'mime_type' => 'application/octet-stream']);
        fclose($stream);
        unlink($path);
        $article = new \IdnoPlugins\Text\Entry();
        $article->title = 'An article with a very large attachment';
        $article->body = '<p>Large.</p>';
        $article->attachFile(\Idno\Entities\File::getByID($fileId));
        $article->setOwner($admin);
        $article->save(true);
        $archiveId = null;
        try {
            $before = memory_get_peak_usage();
            $archiveId = \Idno\Core\Idno::container()->get(\Idno\Portability\ArchiveExporter::class)->export($admin->getUUID());
            $outcome['peak'] = memory_get_peak_usage();
            $outcome['boot_peak'] = $before;
            $outcome['archive_size'] = $files->find($archiveId)->size;
            $outcome['file_size'] = $files->find($fileId)->size;
            $zip = new \ZipArchive();
            $copy = tempnam(sys_get_temp_dir(), 'idno-large-zip-');
            stream_copy_to_stream($files->readStream($archiveId), $out = fopen($copy, 'wb'));
            fclose($out);
            $outcome['entries'] = $zip->open($copy) === true ? $zip->count() : -1;
            $outcome['has_file'] = $zip->locateName('files/readable/' . $fileId . '.bin') !== false;
            $zip->close();
            unlink($copy);
        } finally {
            $article->delete();
            $files->delete($fileId);
            if ($archiveId !== null) {
                $files->delete($archiveId);
            }
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
