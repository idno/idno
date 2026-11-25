<?php

declare(strict_types=1);

namespace Idno\Install;

/**
 * The address of the site being installed. The web installer asks for a domain (read from the
 * request's Host header, shown to the person and corrected by them before it is written) and makes
 * the address from it: https, at the top of the domain. Only a name for this computer (localhost
 * and the like) gets http.
 */
final class SiteAddress
{
    private const HOST = '(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*';

    /**
     * A domain as the form shows it, from a Host header: lower case, or '' when the header is not a
     * host name or address with an optional port.
     */
    public static function fromHostHeader(string $header): string
    {
        $domain = strtolower(trim($header));

        return self::valid($domain) ? $domain : '';
    }

    /**
     * Whether a typed domain is a host name, an IPv4 address or a bracketed IPv6 address, with an
     * optional port, and nothing else (no scheme, path, user name or white space).
     */
    public static function valid(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 255) {
            return false;
        }
        if (preg_match('/\A(' . self::HOST . '|\[[0-9a-f:]{2,45}\])(?::([0-9]{1,5}))?\z/', $domain, $match) !== 1) {
            return false;
        }
        if (str_starts_with($match[1], '[') && filter_var(trim($match[1], '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        return !isset($match[2]) || ((int) $match[2] >= 1 && (int) $match[2] <= 65535);
    }

    /**
     * The site's address for a domain: `https://<domain>/`, or `http://<domain>/` for a name of this
     * computer.
     *
     * @throws \InvalidArgumentException when the domain is not valid()
     */
    public static function url(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if (!self::valid($domain)) {
            throw new \InvalidArgumentException('Not a domain.');
        }

        return (self::isLocal($domain) ? 'http' : 'https') . '://' . $domain . '/';
    }

    /**
     * An address given whole (the command line): http or https, a host with an optional port, no
     * path, query or user name. Returned with one trailing slash.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromUrl(string $url): string
    {
        if (preg_match('#\A(https?)://([^/?\#@\s]+)/?\z#i', trim($url), $match) !== 1 || !self::valid(strtolower($match[2]))) {
            throw new \InvalidArgumentException('The address must be http:// or https://, a domain and nothing after it, for example https://example.com/');
        }

        return strtolower($match[1]) . '://' . strtolower($match[2]) . '/';
    }

    /**
     * Whether a host (with or without a port) names this computer: localhost, 127.0.0.1, [::1], or
     * a name ending in .localhost or .test.
     */
    public static function isLocal(string $host): bool
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:[0-9]+\z/', '', $host) ?? $host;

        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.localhost') || str_ends_with($host, '.test');
    }
}
