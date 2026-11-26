<?php

/**
 * Serves a file through the file page's real output path (no callback) with two output buffers
 * active, for LargeRangeStreamingTest. Output: "BEFORE" (written while buffered), then the bytes.
 * The final state goes to the file named by the fourth argument, as JSON.
 *
 * php stream_buffered.php <file> <all|range> <size> <report>
 */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[$file, $mode, $size, $report] = [$argv[1], $argv[2], (int) $argv[3], $argv[4]];

ob_start();
ob_start();
echo 'BEFORE';
$stream = fopen($file, 'rb');
if ($mode === 'all') {
    \Idno\Http\Controller\File\FileStream::streamAll($stream);
} else {
    \Idno\Http\Controller\File\FileStream::seekTo($stream, 0);
    \Idno\Http\Controller\File\FileStream::streamRange($stream, 0, $size - 1);
}
fclose($stream);

file_put_contents($report, json_encode(['level' => ob_get_level(), 'peak' => memory_get_peak_usage(true)]));
