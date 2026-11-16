<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Subprocess helper for SqliteConcurrencyTest: saves <count> records to the `entities`
 * collection of an SQLite database file (with the schema) through the record store the site
 * builds, on a connection set up as a site's is, with ids <prefix>-1 ... <prefix>-<count>. It waits for the file <go> to exist before
 * the first save, so that two writers start together. Exits 0 when every save returned its id;
 * otherwise prints the failures to stderr and exits 1.
 *
 * Usage: php sqlite_writer.php <database file> <prefix> <count> <go file>
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Tests\Storage\PrivateDatabase;

[, $file, $prefix, $count, $go] = $argv + [null, '', '', '0', ''];

$store = PrivateDatabase::recordStore(PrivateDatabase::sqliteFile($file, migrate: false));

$deadline = microtime(true) + 10;
while (!file_exists($go) && microtime(true) < $deadline) {
    usleep(1000);
}

$failures = 0;
for ($i = 1; $i <= (int) $count; $i++) {
    $id = $prefix . '-' . $i;
    try {
        $result = $store->save('entities', ['_id' => $id, 'title' => 'record ' . $i, 'body' => str_repeat('text ', 20)]);
    } catch (\Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }
    if ($result !== $id) {
        $failures++;
        fwrite(STDERR, $id . ': ' . (is_string($result) ? $result : json_encode($result)) . "\n");
    }
}

exit($failures === 0 ? 0 : 1);
