<?php

declare(strict_types=1);

// Command line only: under a web server, answer 404 with an empty body before loading anything.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

/**
 * Creates the SQLite test rig's database file and loads warmup/schemas/sqlite/sqlite.sql into it,
 * through a connection set up as the site's is (SqliteConnectionSetup: the schema needs its
 * collation and search function). Run by Tests/rig/up.sh.
 *
 * A database that already has tables is left as it is (so running the rig up again keeps its
 * data), unless --reset is given: then the file (and its -wal and -shm files) is deleted first.
 *
 * Usage: php sqlite_schema.php <database file> [--reset]
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Tests\Unit\Storage\SqliteTestDatabase;

$file = $argv[1] ?? '';
$reset = ($argv[2] ?? '') === '--reset';
if ($file === '' || $file === ':memory:') {
    fwrite(STDERR, "Usage: php sqlite_schema.php <database file> [--reset]\n");
    exit(2);
}

if ($reset) {
    foreach (['', '-wal', '-shm'] as $suffix) {
        if (file_exists($file . $suffix)) {
            unlink($file . $suffix);
        }
    }
}
if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0700, true) && !is_dir(dirname($file))) {
    fwrite(STDERR, 'Could not create ' . dirname($file) . "\n");
    exit(1);
}

$connection = SqliteTestDatabase::connection($file);
if ((int) $connection->fetchOne("select count(*) from sqlite_master where type = 'table'") > 0) {
    echo "SQLite database $file already has tables; left as it is.\n";
    exit(0);
}
SqliteTestDatabase::loadSchema($connection);
$connection->close();
echo "Loaded the SQLite schema into $file.\n";
