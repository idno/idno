<?php

declare(strict_types=1);

namespace Tests\Upgrade;

/**
 * The upgrade page carries a random base64 nonce (in its Content-Security-Policy header and in its style
 * and script tags), which may contain `//x`: a test that looks for URLs to another origin in the page
 * must not read the nonce as one. withoutNonce() removes exactly the nonce the response's own policy names.
 */
final class PageNonce
{
    public static function withoutNonce(string $body, string $contentSecurityPolicy): string
    {
        if (preg_match("/'nonce-([^']+)'/", $contentSecurityPolicy, $m) !== 1) {
            throw new \InvalidArgumentException('The policy names no nonce.');
        }

        return str_replace($m[1], '', $body);
    }
}
