<?php

declare(strict_types=1);

namespace Idno\Http\Controller\File;

/**
 * Which bytes of a file a `Range` request header asks for (RFC 9110, section 14). Static and pure: not a service.
 */
final class ByteRange
{
    /**
     * Work out which bytes of a file a Range request header asks for.
     *
     * @param  string $header The value of the Range header, for example "bytes=0-499", "bytes=500-" or "bytes=-500"
     * @param  int    $size   The size of the file in bytes
     * @return array{int, int}|false|null The positions of the first and the last byte to send, counted from 0;
     *                                    false if the file has none of the bytes asked for (answer 416);
     *                                    null if the header is not a single range of bytes, and so is ignored
     */
    public static function parse(string $header, int $size): array|false|null
    {
        if (!preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $matches)) {
            return null;
        }
        [, $first, $last] = $matches;

        if ($first === '') {
            if ($last === '') {
                return null;
            }

            // "-500": the last 500 bytes, or the whole file if it is smaller
            if ((int) $last === 0 || $size === 0) {
                return false;
            }

            return [max($size - (int) $last, 0), $size - 1];
        }

        $start = (int) $first;

        // "500-": from byte 500 to the end. An end past the last byte means the last byte.
        $end = ($last === '') ? $size - 1 : min((int) $last, $size - 1);

        if ($start >= $size || ($last !== '' && (int) $last < $start)) {
            return false;
        }

        return [$start, $end];
    }
}
