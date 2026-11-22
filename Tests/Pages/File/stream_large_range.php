<?php

/**
 * Serves a range of a generated file through the file page's range code, for
 * LargeRangeStreamingTest, which runs it with a memory limit smaller than the file. The bytes are
 * counted and hashed as they are written, never collected. Prints the count, the SHA-256 of the
 * bytes written and the peak memory, as JSON.
 *
 * php stream_large_range.php <file> <start> <end>
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if ($argc !== 4 || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php stream_large_range.php <file> <start> <end>\n");
    exit(2);
}
[$file, $start, $end] = [$argv[1], (int) $argv[2], (int) $argv[3]];

$hash  = hash_init('sha256');
$count = 0;
$stream = fopen($file, 'rb');
\Idno\Pages\File\View::seekTo($stream, $start);
\Idno\Pages\File\View::streamRange(
    $stream, $start, $end, static function (string $chunk) use (&$count, $hash): void {
        $count += strlen($chunk);
        hash_update($hash, $chunk);
    }
);
fclose($stream);

echo json_encode(['count' => $count, 'sha256' => hash_final($hash), 'peak' => memory_get_peak_usage(true)]);
