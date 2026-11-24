<?php

declare(strict_types=1);

namespace Idno\Core;

/**
 * Takes the value of the `access_token` parameter out of a request's query string or address before it is written
 * into an error report (PHP's error log, the administrator's error mail, a debug page): a token given in the query
 * is a credential. The name is matched as the server reads it (URL-decoded, in any letter case, `access_token[]`
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
            $name = strtolower(urldecode(substr($pair, 0, $equals)));
            if ($name === 'access_token' || str_starts_with($name, 'access_token[')) {
                $pairs[$i] = substr($pair, 0, $equals + 1) . self::REPLACEMENT;
            }
        }

        return implode('&', $pairs);
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
