<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * The rules today's MySQL backend applies by itself (in the server, or through its full-text
 * index), written in PHP for the stores that do not run on MySQL: InMemoryRecordStore applies
 * them directly, and the SQLite dialect registers them on its connections (a collation and a
 * search function, see Platform\SqliteDialect::setUpConnection()). One copy, so the two cannot
 * drift apart.
 *
 *  - datetime(): which literals MySQL accepts for the `created` column, in a form whose plain
 *    string comparison orders them correctly against the stored values;
 *  - fold() and compare(): text comparison as `utf8mb3_general_ci` (MySQL's identifier columns)
 *    does it for ASCII (folded to UPPER case, so `[ \ ] ^ _` and the backtick sort after the
 *    letters: 'a_' > 'aZ'). MySQL's indexed values are `utf8mb4_unicode_520_ci`, which orders
 *    punctuation before the letters; that order is not reproduced here;
 *  - searchMatches(): the search rule of the stores without a full-text index (in memory, SQLite).
 *
 * The known differences from MySQL are listed on InMemoryRecordStore.
 *
 * Static helpers, not a container service (see config/services.php).
 */
final class MySqlSemantics
{
    /** Search terms longer than this many bytes are matched word by word. */
    public const SHORT_SEARCH_BYTES = 5;

    /**
     * The datetime literal in the form the stored `created` strings have ('Y-m-d H:i:s'), plus
     * '.ffffff' when the fraction is not zero (so a plain string comparison orders it correctly),
     * or null when MySQL would reject the literal (or it is one of the forms not supported here).
     *
     * Accepted: 'Y-m-d H:i:s', unpadded fields ('2023-6-4 1:2:3'), a date only (midnight), a 'T'
     * instead of the space, up to six fractional digits (kept exactly, no rounding).
     */
    public static function datetime(string $text): ?string
    {
        $matched = preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{1,2}):(\d{1,2})(?:\.(\d{1,6}))?)?$/', $text, $m);
        if ($matched !== 1) {
            return null;
        }
        [$year, $month, $day, $hour, $minute, $second] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        $fraction = rtrim($m[7] ?? '', '0');

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second) . ($fraction === '' ? '' : '.' . $fraction);
    }

    /**
     * The text as the case-insensitive comparison sees it (upper case).
     */
    public static function fold(string $text): string
    {
        return mb_strtoupper($text, 'UTF-8');
    }

    /**
     * Compares two texts ignoring case: negative, zero or positive, as strcmp().
     */
    public static function compare(string $a, string $b): int
    {
        return strcmp(self::fold($a), self::fold($b));
    }

    /**
     * Whether a search text matches a search term. A term of more than 5 bytes is split on
     * whitespace and matches when the text contains ANY of the words as a case-insensitive
     * substring (the words are plain text, no wildcards). A term of at most 5 bytes is one
     * substring, matched like SQL `LIKE '%term%'` without regard to case: '%' and '_' are
     * wildcards and a backslash escapes the next character (a trailing backslash is a literal).
     */
    public static function searchMatches(string $term, string $searchText): bool
    {
        $text = self::fold($searchText);
        if (strlen($term) > self::SHORT_SEARCH_BYTES) {
            foreach (preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (str_contains($text, self::fold($word))) {
                    return true;
                }
            }

            return false;
        }

        return preg_match(self::likePattern(self::fold($term)), $text) === 1;
    }

    /**
     * The regular expression for SQL `LIKE '%term%'`.
     */
    private static function likePattern(string $term): string
    {
        $pattern = '';
        $length = strlen($term);
        for ($i = 0; $i < $length; $i++) {
            $char = $term[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $char = $term[++$i];
                $pattern .= preg_quote($char, '/');
            } elseif ($char === '%') {
                $pattern .= '.*';
            } elseif ($char === '_') {
                $pattern .= '.';
            } else {
                $pattern .= preg_quote($char, '/');
            }
        }

        return '/' . $pattern . '/su';
    }
}
