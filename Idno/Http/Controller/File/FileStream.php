<?php

declare(strict_types=1);

namespace Idno\Http\Controller\File;

/**
 * Writing a stored file's bytes to the output without holding them in memory. Static: not a service.
 */
final class FileStream
{
    /**
     * Position a stream at a byte offset. A stream that cannot seek (one read over HTTP, from S3,
     * say) is read and discarded up to the offset instead.
     *
     * @param resource $stream
     */
    public static function seekTo($stream, int $offset): void
    {
        if ($offset <= 0 || @fseek($stream, $offset) === 0) {
            return;
        }
        // fseek() failed: the stream is still where it was (the start, for a stream just opened)
        $skipped = (int) ftell($stream);
        while ($skipped < $offset && !feof($stream)) {
            $chunk = fread($stream, min(8192, $offset - $skipped));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $skipped += strlen($chunk);
        }
    }

    /**
     * Write the bytes of a range from a stream positioned at its first byte (seekTo()) to the output,
     * 8 KiB at a time, so that a range of any size is never held in memory: the bytes from $start to
     * $end, both positions inclusive, so exactly as many as the response's Content-Length announces;
     * fewer when the stream ends first, or a read returns nothing or fails: it never waits for bytes
     * that do not come.
     *
     * @param resource                    $stream
     * @param (callable(string): mixed)|null $write Called with each piece; null echoes it
     *
     * @return int The number of bytes written
     */
    public static function streamRange($stream, int $start, int $end, ?callable $write = null): int
    {
        if ($write === null) {
            self::endOutputBuffers();
        }
        $length  = $end - $start + 1;
        $written = 0;
        while ($written < $length) {
            $chunk = fread($stream, min(8192, $length - $written));
            if ($chunk === false || $chunk === '') {
                break;
            }
            if ($write === null) {
                echo $chunk;
            } else {
                $write($chunk);
            }
            $written += strlen($chunk);
        }

        return $written;
    }

    /**
     * End every active output buffer, flushing what it holds first (so output that came before is
     * not lost or reordered), so that what a file page echoes next goes out as it is written and is
     * never accumulated in memory. The one place that does it: the file store's pass-through uses it too.
     */
    public static function endOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            if (!@ob_end_flush()) {
                break;
            }
        }
    }

    /**
     * Write a whole stream to the output, 8 KiB at a time. It stops at the end of the stream, or on an
     * empty or failed read, as streamRange() does: it never spins, and never writes `false`.
     *
     * @param resource                    $stream
     * @param (callable(string): mixed)|null $write Called with each piece; null echoes it
     *
     * @return int The number of bytes written
     */
    public static function streamAll($stream, ?callable $write = null): int
    {
        if ($write === null) {
            self::endOutputBuffers();
        }
        $written = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            if ($write === null) {
                echo $chunk;
            } else {
                $write($chunk);
            }
            $written += strlen($chunk);
        }

        return $written;
    }
}
