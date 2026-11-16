<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * Rules MySQL applies by itself (in the server, or through its full-text index), written in PHP:
 *
 *  - datetime(): which literals MySQL accepts for a `created` operand; epoch(): the same literals
 *    as the timestamps JsonRecordStore compares its `created` column with (on every engine: a
 *    literal it rejects makes the query match nothing);
 *  - fold() and compare(): the in-memory store's text comparison, which ignores case like MySQL's
 *    collation (folded to UPPER case, so `[ \ ] ^ _` and the backtick sort after the letters:
 *    'a_' > 'aZ', where MySQL's utf8mb4_unicode_520_ci sorts them before);
 *  - searchMatches(): the search rule of the in-memory store, which has no full-text index.
 *
 * InMemoryRecordStore applies them directly; JsonRecordStore uses epoch(). The known differences
 * from MySQL are listed on InMemoryRecordStore.
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
     * The instant a datetime literal MySQL accepts (see datetime()) names, read in the PHP default
     * time zone of this moment, as Unix seconds in decimal text: the integer, plus the fraction when
     * it is not zero ('1686700000.5'; before 1970 '-99.75' for -100 seconds plus .25), so that a
     * numeric comparison with a stored timestamp is exact. Null when MySQL would reject the literal.
     */
    public static function epoch(string $text): ?string
    {
        $datetime = self::datetime($text);
        if ($datetime === null) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $datetime, 2), 2, '');
        $seconds = (new \DateTimeImmutable($whole, new \DateTimeZone(date_default_timezone_get())))->getTimestamp();
        if ($fraction === '') {
            return (string) $seconds;
        }
        if ($seconds >= 0) {
            return $seconds . '.' . $fraction;
        }
        // -100 + 0.25 is -99.75: one second fewer, and the fraction's complement.
        $complement = str_pad((string) (10 ** strlen($fraction) - (int) $fraction), strlen($fraction), '0', STR_PAD_LEFT);

        return '-' . (-$seconds - 1) . '.' . $complement;
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
     * Whether a search text matches a search term (the in-memory store's search). A term of more than 5 bytes is split on
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
