<?php

declare(strict_types=1);

namespace Idno\Markdown;

/**
 * Whether a request for a post's permalink asks for its Markdown form: pure rules, on values the caller read.
 *
 * Three ways in: the suffix `.md` on the permalink's path, the query `?format=markdown` (or `?_t=markdown`), and an
 * `Accept` header that names `text/markdown` as the client's first choice.
 */
final class MarkdownRequest
{
    public const SUFFIX = '.md';
    public const MEDIA_TYPE = 'text/markdown';

    /**
     * The path without its `.md` suffix, or null when the path does not end in a path segment that has more than the suffix.
     */
    public static function pathWithoutSuffix(string $path): ?string
    {
        if (!str_ends_with($path, self::SUFFIX)) {
            return null;
        }
        $stripped = substr($path, 0, -strlen(self::SUFFIX));
        if ($stripped === '' || str_ends_with($stripped, '/')) {
            return null;
        }

        return $stripped;
    }

    /**
     * @param bool  $suffix whether the request's path carried the `.md` suffix
     * @param mixed $format the request's `format` value
     * @param mixed $type   the request's `_t` value
     * @param mixed $accept the request's `Accept` header
     */
    public static function wanted(bool $suffix, mixed $format, mixed $type, mixed $accept): bool
    {
        if ($suffix) {
            return true;
        }
        if (is_string($format) && strtolower($format) === 'markdown') {
            return true;
        }
        if (is_string($type) && strtolower($type) === 'markdown') {
            return true;
        }
        // A request that names another template type, or another format, has chosen: the Accept header is not asked
        if ((is_string($type) && $type !== '') || is_array($type) || (is_string($format) && $format !== '') || is_array($format)) {
            return false;
        }

        return is_string($accept) && self::acceptPrefersMarkdown($accept);
    }

    /**
     * Whether an `Accept` header names `text/markdown`, with a quality above 0 that no other range of the header exceeds.
     *
     * So `text/markdown, text/html` is Markdown (a tie goes to the type the client took the trouble to name),
     * `text/html, text/markdown;q=0.9` is HTML, and a header that does not name the type (a browser's, or a bare `*` range)
     * is never Markdown.
     */
    public static function acceptPrefersMarkdown(string $accept): bool
    {
        if (strlen($accept) > 2000) {
            return false;
        }
        $markdown = null;
        $others = 0.0;
        foreach (explode(',', $accept) as $range) {
            $parameters = explode(';', $range);
            $type = strtolower(trim((string) array_shift($parameters)));
            if ($type === '') {
                continue;
            }
            $quality = 1.0;
            foreach ($parameters as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9]*\.?[0-9]*)\s*$/i', $parameter, $match) === 1) {
                    $quality = max(0.0, min(1.0, (float) $match[1]));
                }
            }
            if ($type === self::MEDIA_TYPE) {
                $markdown = max($markdown ?? 0.0, $quality);
            } else {
                $others = max($others, $quality);
            }
        }

        return $markdown !== null && $markdown > 0.0 && $markdown >= $others;
    }
}
