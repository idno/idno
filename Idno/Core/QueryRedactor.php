<?php

declare(strict_types=1);

namespace Idno\Core;

/**
 * Takes the value of the `access_token` parameter out of a request's query string or address before it is written
 * into an error report (PHP's error log, the administrator's error mail, a debug page): a token given in the query
 * is a credential. The name is matched as PHP reads it (URL-decoded, `.`, space and `+` read as `_`, in any letter case, `access_token[]`
 * included) and written back as it was sent. Pure, and loadable on its own: the fatal-error page calls it.
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
     * Whether PHP would read the parameter name as `access_token`: URL-decoded (`+` is a space), leading spaces
     * stripped, `.` and space turned into `_`, and the part before any `[` (the array form) compared in any letter case.
     */
    private static function isAccessToken(string $rawName): bool
    {
        $name = ltrim(urldecode($rawName), ' ');
        $bracket = strpos($name, '[');
        if ($bracket !== false) {
            $name = substr($name, 0, $bracket);
        }

        return strtolower(strtr($name, '. ', '__')) === 'access_token';
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
