<?php

declare(strict_types=1);

namespace Idno\Core;

/**
 * Takes the value of the `access_token` parameter out of a request's query string or address before it is written
 * into an error report (PHP's error log, the administrator's error mail, a debug page): a token given in the query
 * is a credential. The name is matched as PHP reads it (URL-decoded, cut at a NUL byte, `.`, space and `+` read as `_`, `[` handled as PHP does, in any letter case,
 * `access_token[]` included) and written back as it was sent. Pure, and loadable on its own: the fatal-error page calls it.
 */
final class QueryRedactor
{
    public const REPLACEMENT = '[redacted]';

    /**
     * A query string (`a=1&access_token=x`).
     */
    public static function query(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = explode('&', $query);
        foreach ($pairs as $i => $pair) {
            $equals = strpos($pair, '=');
            if ($equals === false) {
                continue;
            }
            if (self::isAccessToken(substr($pair, 0, $equals))) {
                $pairs[$i] = substr($pair, 0, $equals + 1) . self::REPLACEMENT;
            }
        }

        return implode('&', $pairs);
    }

    /**
     * Whether PHP would read the parameter name as `access_token`, as php_register_variable_ex() builds the name: the
     * name URL-decoded once (`+` is a space) and cut at the first NUL byte, leading spaces stripped, `.` and space before
     * the first `[` turned into `_`; then at that `[`, when a `]` follows it anywhere the name is what came before it
     * (the array form), and when none does the `[` becomes `_` and the rest is kept as it is. Compared in any letter
     * case (PHP's names are case-sensitive: redacting more is fine).
     */
    private static function isAccessToken(string $rawName): bool
    {
        $name = urldecode($rawName);
        $nul = strpos($name, "\0");
        if ($nul !== false) {
            $name = substr($name, 0, $nul);
        }
        $name = ltrim($name, ' ');
        $bracket = strpos($name, '[');
        if ($bracket === false) {
            return strtolower(strtr($name, '. ', '__')) === 'access_token';
        }
        $head = strtr(substr($name, 0, $bracket), '. ', '__');
        $rest = substr($name, $bracket + 1);
        $name = str_contains($rest, ']') ? $head : $head . '_' . $rest;

        return strtolower($name) === 'access_token';
    }

    /**
     * A request address (`/path?a=1&access_token=x#fragment`): the query string of it is redacted.
     */
    public static function uri(string $uri): string
    {
        $question = strpos($uri, '?');
        if ($question === false) {
            return $uri;
        }
        $rest = substr($uri, $question + 1);
        $hash = strpos($rest, '#');
        $fragment = $hash === false ? '' : substr($rest, $hash);
        $query = $hash === false ? $rest : substr($rest, 0, $hash);

        return substr($uri, 0, $question + 1) . self::query($query) . $fragment;
    }
}
