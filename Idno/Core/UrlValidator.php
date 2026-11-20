<?php

namespace Idno\Core;

/**
 * Validates URLs to prevent Server-Side Request Forgery (SSRF).
 *
 * A URL that was supplied by a remote party (webmention sources, ActivityPub
 * actors, Micropub media URLs, etc) must pass isSafeUrl() before it is fetched,
 * and so must every URL it redirects to: fetch it with Webservice::safeSend(),
 * which does both.
 */
class UrlValidator
{
    /**
     * Check whether a URL is safe for server-side fetching.
     * Blocks non-HTTP schemes, embedded credentials, and any host that is,
     * or resolves to, a loopback, private, link-local or reserved address.
     *
     * This checks one URL. A redirect from it can lead anywhere, so fetch the URL
     * with \Idno\Core\Webservice::safeSend(), which checks every hop.
     *
     * @param mixed $url The URL to validate
     * @return bool True if the URL is safe to fetch
     */
    public static function isSafeUrl($url): bool
    {
        return !empty(self::safeAddresses($url));
    }

    /**
     * The IP addresses that the host of a URL resolves to, if the URL is safe for
     * server-side fetching (see isSafeUrl()).
     *
     * @param mixed $url The URL to validate
     * @return string[] The addresses, or an empty array if the URL is not safe to fetch
     */
    public static function safeAddresses($url): array
    {
        if (!is_string($url) || $url === '') {
            return [];
        }

        // Must be a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            self::reject('Invalid URL', $url);
            return [];
        }

        $parts = parse_url($url);

        // Only allow http and https schemes
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            self::reject('Non-HTTP scheme', $url);
            return [];
        }

        // Must have a host, and must not smuggle credentials
        $host = $parts['host'] ?? '';
        if ($host === '') {
            self::reject('Missing host', $url);
            return [];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            self::reject('Embedded credentials', $url);
            return [];
        }

        $ips = self::resolveHost($host);
        if (empty($ips)) {
            self::reject('Unresolvable host', $url);
            return [];
        }

        // Every address the host resolves to must be public
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                self::reject('Private/reserved IP (' . $ip . ')', $url);
                return [];
            }
        }

        return $ips;
    }

    /**
     * Is this IP address publicly routable? (ie not loopback, private,
     * link-local or otherwise reserved)
     *
     * @param string $ip IPv4 or IPv6 address
     * @return bool
     */
    public static function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Unwrap IPv4 addresses embedded in IPv6 (::ffff:127.0.0.1, ::127.0.0.1)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if ($packed !== false && strlen($packed) === 16) {
                $prefix = substr($packed, 0, 12);
                if ($prefix === str_repeat("\0", 10) . "\xff\xff" || $prefix === str_repeat("\0", 12)) {
                    $embedded = inet_ntop(substr($packed, 12));
                    // ::  and ::1 are covered by the reserved-range check below
                    if ($embedded !== false && !in_array($ip, ['::', '::1'], true)) {
                        return self::isPublicIp($embedded);
                    }
                }
            }
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // Carrier-grade NAT range (100.64.0.0/10), which the filter flags above don't cover
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long !== false && ($long & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a URL host to every IP address it points at.
     *
     * @param string $host Hostname, IPv4 literal or bracketed IPv6 literal
     * @return string[]
     */
    protected static function resolveHost(string $host): array
    {
        // IPv6 literals arrive bracketed from parse_url()
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            return [$literal];
        }

        $ips = [];

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = $ipv4;
        }

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (!empty($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @param string $reason
     * @param string $url
     * @return void
     */
    private static function reject(string $reason, string $url): void
    {
        Idno::site()->logging()->warning('UrlValidator: ' . $reason . ' rejected: ' . $url);
    }
}
