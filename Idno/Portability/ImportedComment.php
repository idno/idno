<?php

declare(strict_types=1);

namespace Idno\Portability;

/**
 * A comment as an importer read it. A value object, not a service.
 */
final class ImportedComment
{
    public function __construct(
        public readonly string $author,
        public readonly string $url,
        public readonly string $content,
        public readonly int $time,
    )
    {
    }

    /** The address, kept only when it is an http or https one. */
    public static function safeUrl(string $url): string
    {
        $url = trim($url);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) ? $url : '';
    }
}
