<?php

/**
 * Runs FlysystemFile::passThroughBytes() with two output buffers active, for LegacyFileSystemTest.
 * Output: "BEFORE" (written while buffered), then the file. Prints the buffer level afterwards on stderr.
 *
 * php pass_through_buffered.php <upload directory>
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$store = new \Idno\Storage\Files\FileStore(\Idno\Storage\Files\FilesystemFactory::fromConfig(['uploadpath' => $argv[1] . '/']), 'host');
$memory = fopen('php://memory', 'w+b');
fwrite($memory, 'passed through');
rewind($memory);
$id = $store->store($memory, ['filename' => 'p.txt']);
$file = new \Idno\Legacy\FlysystemFile($store, $store->find($id), '', static fn () => new \Psr\Log\NullLogger());

ob_start();
ob_start();
echo 'BEFORE';
$file->passThroughBytes();
fwrite(STDERR, (string) ob_get_level());
