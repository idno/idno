<?php

declare(strict_types=1);

namespace Idno\Upgrade;

use Idno\Storage\RecordIndex;

/**
 * What the copy of a 1.x database needs to know about a row's `contents`, without decoding the
 * whole document (a document can be megabytes):
 *
 *  - problem(): why the contents cannot be copied (not UTF-8, not JSON, nested deeper than PHP's
 *    512 levels, not an object or array), or null;
 *  - created(): the `created` column of the copy: the document's top-level `created` when it is a
 *    timestamp by RecordIndex::created()'s rule, else the 1.x column's instant. Only that one value
 *    is decoded: the top level of the document is scanned, nested values are skipped.
 *
 * A static helper, not a container service.
 */
final class DocumentCheck
{
    private const WHITESPACE = " \t\n\r";

    public static function problem(string $contents): ?string
    {
        if (!mb_check_encoding($contents, 'UTF-8')) {
            return 'contents are not valid UTF-8';
        }
        if (!json_validate($contents)) {
            return json_last_error() === JSON_ERROR_DEPTH ? 'contents are nested deeper than 512 levels' : 'contents are not valid JSON';
        }
        // Valid JSON whose first character opens an object or an array decodes to a PHP array.
        $first = $contents[strspn($contents, self::WHITESPACE)] ?? '';

        return $first === '{' || $first === '[' ? null : 'contents are not a JSON object or array';
    }

    /**
     * The `created` of the copy of a document problem() accepted.
     */
    public static function created(string $contents, int $column): int
    {
        $value = self::topLevelValue($contents, 'created');
        if ($value === null || $value === '' || $value[0] === '{' || $value[0] === '[') {
            return $column;
        }
        try {
            return RecordIndex::created(['created' => json_decode($value, true)], $column);
        } catch (\InvalidArgumentException) {
            return $column;
        }
    }

    /**
     * The JSON text of the last top-level member named $name of a valid JSON object (json_decode()
     * keeps the last of duplicate keys), or null when there is none (or the document is a list).
     */
    public static function topLevelValue(string $json, string $name): ?string
    {
        $length = strlen($json);
        $i = self::skipSpace($json, 0);
        if (($json[$i] ?? '') !== '{') {
            return null;
        }
        $found = null;
        $i = self::skipSpace($json, $i + 1);
        while ($i < $length && $json[$i] !== '}') {
            $keyEnd = self::stringEnd($json, $i);
            $key = json_decode(substr($json, $i, $keyEnd - $i));
            $i = self::skipSpace($json, $keyEnd);
            $i = self::skipSpace($json, $i + 1); // the colon
            $valueEnd = self::valueEnd($json, $i);
            if ($key === $name) {
                $found = substr($json, $i, $valueEnd - $i);
            }
            $i = self::skipSpace($json, $valueEnd);
            if (($json[$i] ?? '') === ',') {
                $i = self::skipSpace($json, $i + 1);
            }
        }

        return $found;
    }

    private static function skipSpace(string $json, int $i): int
    {
        return $i + strspn($json, self::WHITESPACE, $i);
    }

    /**
     * The offset just after the string that starts at $i (an opening quote).
     */
    private static function stringEnd(string $json, int $i): int
    {
        $length = strlen($json);
        $i++;
        while ($i < $length) {
            $i += strcspn($json, '"\\', $i);
            if (($json[$i] ?? '') === '\\') {
                $i += 2;
                continue;
            }

            return $i + 1;
        }

        return $length;
    }

    /**
     * The offset just after the value that starts at $i.
     */
    private static function valueEnd(string $json, int $i): int
    {
        $length = strlen($json);
        $first = $json[$i] ?? '';
        if ($first === '"') {
            return self::stringEnd($json, $i);
        }
        if ($first !== '{' && $first !== '[') {
            return $i + strcspn($json, ',}]' . self::WHITESPACE, $i);
        }
        $depth = 0;
        while ($i < $length) {
            $i += strcspn($json, '"{}[]', $i);
            $char = $json[$i] ?? '';
            if ($char === '"') {
                $i = self::stringEnd($json, $i);
                continue;
            }
            $depth += $char === '{' || $char === '[' ? 1 : -1;
            $i++;
            if ($depth === 0) {
                return $i;
            }
        }

        return $length;
    }
}
