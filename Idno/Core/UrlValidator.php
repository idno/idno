<?php

namespace Idno\Core;

/**
 * Validates URLs to prevent Server-Side Request Forgery (SSRF).
 */
class UrlValidator
{
    /**
     * Check whether a URL is safe for server-side fetching.
     * Blocks private/reserved IP ranges, non-HTTP schemes, and localhost.
     *
     * @param string $url The URL to validate
     * @return bool True if the URL is safe to fetch
     */
    public static function isSafeUrl(string $url): bool
    {
        // Must be a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            Idno::site()->logging()->warning("UrlValidator: Invalid URL rejected: {$url}");
            return false;
        }

        // Only allow http and https schemes
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'])) {
            Idno::site()->logging()->warning("UrlValidator: Non-HTTP scheme rejected: {$url}");
            return false;
        }

        // Must have a host
        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            Idno::site()->logging()->warning("UrlValidator: Missing host rejected: {$url}");
            return false;
        }

        // Resolve hostname to IP and check for private/reserved ranges
        $ip = gethostbyname($host);
        if ($ip === $host) {
            // gethostbyname returns the input on failure; might be IPv6
            // Try to validate as a literal IP
            if (!filter_var($host, FILTER_VALIDATE_IP)) {
                Idno::site()->logging()->warning("UrlValidator: Unresolvable host rejected: {$url}");
                return false;
            }
            $ip = $host;
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            Idno::site()->logging()->warning("UrlValidator: Private/reserved IP rejected: {$url} (resolved to {$ip})");
            return false;
        }

        return true;
    }
}
