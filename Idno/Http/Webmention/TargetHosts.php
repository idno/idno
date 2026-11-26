<?php

declare(strict_types=1);

namespace Idno\Http\Webmention;

/**
 * Which addresses are this site's, for a webmention aimed at one: those on the host of the site's address, and on no other. A value object built from the
 * site's address; not a service.
 *
 * An address is the site's when:
 * - it holds no control character (a NUL, a tab, a line break) or space anywhere, no user information, and no `.` or `..` path segment;
 * - its scheme is http or https (either is accepted) and its host is the host of the site's address, compared ignoring case and ONE trailing dot, an
 *   internationalised name in its punycode form (with the `intl` extension; a name that cannot be turned into ASCII is no host). Not the `www.` or bare
 *   counterpart, not a subdomain, not a former domain;
 * - its port, explicit or its scheme's default, is the site's address's when that names a port that is not its scheme's default, else its scheme's default;
 * - for a site in a subdirectory (the site's address has a path), its path is under that directory.
 * A site whose address has no host has no address: nothing is the site's.
 */
final class TargetHosts
{
    private const HOSTNAME = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/';

    private ?string $siteHost = null;

    private ?int $sitePort = null;

    private string $directory = '';

    public function __construct(string $siteUrl)
    {
        $site = parse_url($siteUrl);
        if (is_array($site) && !empty($site['host']) && in_array(strtolower($site['scheme'] ?? ''), ['http', 'https'], true)) {
            $this->siteHost = self::normalise($site['host']);
            $port = $site['port'] ?? null;
            $this->sitePort = $port !== null && (int) $port !== self::defaultPort(strtolower($site['scheme'])) ? (int) $port : null;
            $this->directory = rtrim($site['path'] ?? '', '/');
        }
    }

    /**
     * The address, when it is this site's: its scheme, host (as compared: lower case, punycode), explicit port, path and query, and the directory the site is
     * served from.
     *
     * @return array{scheme: string, host: string, port: int|null, path: string, query: string|null, directory: string}|null
     */
    public function locate(string $target): ?array
    {
        // (`parse_url()` turns a control character into an underscore: an address that holds one is not the address it would be read as)
        if ($this->siteHost === null || preg_match('/[\x00-\x20\x7f]/', $target) === 1) {
            return null;
        }
        $parts = parse_url($target);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = self::normalise($parts['host']);
        if (!in_array($scheme, ['http', 'https'], true) || $host !== $this->siteHost) {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (($port ?? self::defaultPort($scheme)) !== ($this->sitePort ?? self::defaultPort($scheme))) {
            return null;
        }

        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        if ($this->directory !== '' && $path !== $this->directory && !str_starts_with($path, $this->directory . '/')) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if (in_array(strtolower($segment), ['.', '..', '%2e', '.%2e', '%2e.', '%2e%2e'], true)) {
                return null;
            }
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'path' => $path, 'query' => isset($parts['query']) && $parts['query'] !== '' ? $parts['query'] : null, 'directory' => $this->directory];
    }

    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }

    /**
     * A host as it is compared: lower case, without one trailing dot, an internationalised name in punycode. Null for what is no host name or IP address.
     */
    private static function normalise(string $host): ?string
    {
        $host = preg_replace('/\.$/', '', $host) ?? $host;
        if ($host === '') {
            return null;
        }
        if (preg_match('/[^\x00-\x7f]/', $host) !== 1) {
            $host = strtolower($host);
        }
        if (str_starts_with($host, '[')) {
            $inner = substr($host, 1, -1);

            return str_ends_with($host, ']') && filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . (inet_ntop((string) inet_pton($inner)) ?: $inner) . ']' : null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }
        if (preg_match('/[^\x00-\x7f]/', $host) === 1) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return null;
            }
            $host = strtolower($ascii);
        }

        return preg_match(self::HOSTNAME, $host) === 1 ? $host : null;
    }
}
