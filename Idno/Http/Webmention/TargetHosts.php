<?php

declare(strict_types=1);

namespace Idno\Http\Webmention;

/**
 * Which addresses are this site's, for a webmention aimed at one. A value object built from the site's address and the entries of the boot configuration's
 * `webmention_target_hosts`; not a service.
 *
 * An address is the site's when:
 * - its scheme is http or https (either is accepted for any accepted host), it has a host and no user information;
 * - its host is the host of the site's address, that host's `www.` or bare counterpart, or a listed host. Hosts are compared ignoring case and a trailing dot, and an
 *   internationalised name in its punycode form (with the `intl` extension; a name that cannot be turned into ASCII is no host);
 * - its port, explicit or its scheme's default, is the site's address's when that names a port that is not its scheme's default, else its scheme's default. A listed
 *   host takes its scheme's default, unless the entry is written `host:port`, which is the port it must have;
 * - for a site in a subdirectory (the site's address has a path), its path is under that directory.
 * A site with no host in its address has only the listed hosts.
 */
final class TargetHosts
{
    private const SETTING = 'webmention_target_hosts';

    private const HOSTNAME = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/';

    private ?string $siteHost = null;

    private ?string $sitePort = null;

    private string $directory = '';

    /**
     * @param list<array{host: string, port: int|null}> $listed the entries of `entries()`
     */
    public function __construct(string $siteUrl, private readonly array $listed)
    {
        $site = parse_url($siteUrl);
        if (is_array($site) && !empty($site['host']) && in_array(strtolower($site['scheme'] ?? ''), ['http', 'https'], true)) {
            $this->siteHost = self::normalise($site['host']);
            $scheme = strtolower($site['scheme']);
            $port = $site['port'] ?? null;
            $this->sitePort = $port !== null && $port !== self::defaultPort($scheme) ? (string) $port : null;
            $this->directory = rtrim($site['path'] ?? '', '/');
        }
    }

    /**
     * The entries of the setting: a list, or one comma-separated string. Each is a host name or IP address, with a port (`host:port`, `[v6]:port`) if the host is
     * to be reached on one.
     *
     * @return list<array{host: string, port: int|null}>
     *
     * @throws \LogicException for an entry that is no host name or IP address, naming the setting and the entry
     */
    public static function entries(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            throw new \LogicException('The setting ' . self::SETTING . ' must be a list of host names, or one comma-separated string: got ' . get_debug_type($value));
        }

        $entries = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new \LogicException('The setting ' . self::SETTING . ' holds ' . get_debug_type($entry) . ', which is not a host name');
            }
            $text = trim($entry);
            if ($text === '') {
                continue;
            }
            $parsed = self::parseEntry($text);
            if ($parsed === null) {
                throw new \LogicException('The setting ' . self::SETTING . ' holds "' . $text . '", which is not a host name or an IP address (with a port, if one: host:port)');
            }
            $entries[] = $parsed;
        }

        return $entries;
    }

    /**
     * The address, when it is this site's: its scheme, host (as compared: lower case, punycode), explicit port, path and query, and the directory the site is served from.
     *
     * @return array{scheme: string, host: string, port: int|null, path: string, query: string|null, directory: string}|null
     */
    public function locate(string $target): ?array
    {
        $parts = parse_url($target);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = self::normalise($parts['host']);
        if (!in_array($scheme, ['http', 'https'], true) || $host === null) {
            return null;
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $effective = $port ?? self::defaultPort($scheme);

        if (!$this->accepts($host, $effective, $scheme)) {
            return null;
        }
        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        if ($this->directory !== '' && $path !== $this->directory && !str_starts_with($path, $this->directory . '/')) {
            return null;
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port, 'path' => $path, 'query' => isset($parts['query']) && $parts['query'] !== '' ? $parts['query'] : null, 'directory' => $this->directory];
    }

    private function accepts(string $host, int $port, string $scheme): bool
    {
        if ($this->siteHost !== null && in_array($host, self::counterparts($this->siteHost), true)) {
            if ($port === ($this->sitePort !== null ? (int) $this->sitePort : self::defaultPort($scheme))) {
                return true;
            }
        }
        foreach ($this->listed as $entry) {
            if ($host === $entry['host'] && $port === ($entry['port'] ?? self::defaultPort($scheme))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A host and its `www.` or bare counterpart (an address is no host name that has one).
     *
     * @return list<string>
     */
    private static function counterparts(string $host): array
    {
        if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return [$host, str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host];
    }

    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }

    /**
     * @return array{host: string, port: int|null}|null
     */
    private static function parseEntry(string $text): ?array
    {
        $port = null;
        if (str_starts_with($text, '[')) {
            if (preg_match('/^(\[[^\]]+\])(?::(\d{1,5}))?$/', $text, $found) !== 1) {
                return null;
            }
            [$host, $port] = [$found[1], $found[2] ?? null];
        } elseif (filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $text . ']';
        } elseif (substr_count($text, ':') === 1) {
            [$host, $port] = explode(':', $text);
            if (preg_match('/^\d{1,5}$/', $port) !== 1) {
                return null;
            }
        } else {
            $host = $text;
        }
        if ($port !== null && ((int) $port < 1 || (int) $port > 65535)) {
            return null;
        }
        $normal = self::normalise($host);

        return $normal === null ? null : ['host' => $normal, 'port' => $port !== null ? (int) $port : null];
    }

    /**
     * A host as it is compared: lower case, no trailing dot, an internationalised name in punycode. Null for what is no host name or IP address.
     */
    private static function normalise(string $host): ?string
    {
        $host = rtrim($host, '.');
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
