<?php

declare(strict_types=1);

namespace Idno\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The proxies a site trusts, and the one rule for whether a request was made over HTTPS.
 *
 * The boot setting `trusted_proxies` (a list in config.ini, or one comma-separated string in
 * `IDNO_TRUSTED_PROXIES`) names the reverse proxies whose `X-Forwarded-Proto` and `X-Forwarded-Port` headers
 * are believed; `X-Forwarded-Host` and `X-Forwarded-Prefix` never are (the configured `url` decides the site's
 * address). A site that names none keeps the rule it always had: `X-Forwarded-Proto: https` is believed from
 * anyone, so a site behind a proxy that has never heard of the setting keeps its secure cookie and its https
 * address.
 *
 * Static because Symfony's trusted proxies are.
 */
final class TrustedProxies
{
    private const HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT;

    private const READ = ['HTTPS', 'SERVER_PORT', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_PORT', 'REMOTE_ADDR'];

    private static ?Request $request = null;

    /** @var array<string, mixed> */
    private static array $seen = [];

    /** @var list<string> */
    private static array $proxies = [];

    /**
     * The entries of the setting: a list, or a comma-separated string, trimmed, empty entries dropped. Each is
     * an IP address, a CIDR range with a prefix above 0, `REMOTE_ADDR` or `PRIVATE_SUBNETS`.
     *
     * @param array<string, mixed> $bootConfig
     *
     * @return list<string>
     *
     * @throws \LogicException for an entry that is none of those
     */
    public static function fromConfig(array $bootConfig): array
    {
        $value = $bootConfig['trusted_proxies'] ?? null;
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if ($value === null || $value === []) {
            return [];
        }
        if (!is_array($value)) {
            throw new \LogicException(self::refusal(get_debug_type($value)));
        }

        $entries = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new \LogicException(self::refusal(get_debug_type($entry)));
            }
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (!self::isEntry($entry)) {
                throw new \LogicException(self::refusal($entry));
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Gives the kernel's requests the setting; none clears what an earlier kernel of the process set.
     *
     * @param array<string, mixed> $bootConfig
     */
    public static function apply(array $bootConfig): void
    {
        $proxies = self::fromConfig($bootConfig);
        if ($proxies === []) {
            Request::setTrustedProxies([], 0);

            return;
        }
        Request::setTrustedProxies($proxies, self::HEADERS);
    }

    public static function configured(): bool
    {
        return Request::getTrustedProxies() !== [];
    }

    /**
     * Whether the request was made over HTTPS. With the setting: the server's own word, or `X-Forwarded-Proto`
     * from a trusted proxy alone. Without it: `HTTPS` `1` or `on`, port 443, or `X-Forwarded-Proto: https` from
     * anyone.
     */
    public static function isSecure(Request $request): bool
    {
        if (self::configured()) {
            return $request->isSecure();
        }

        $server = $request->server;
        $https = $server->get('HTTPS');
        if ($https !== null) {
            if ($https == '1' || strtolower((string) $https) === 'on') {
                return true;
            }
        } elseif ($server->get('SERVER_PORT') == '443') {
            return true;
        }

        return strtolower((string) $server->get('HTTP_X_FORWARDED_PROTO', '')) === 'https';
    }

    /**
     * Whether a forwarded port is believed: from a trusted proxy alone when the setting is given, from anyone
     * when it is not.
     */
    public static function believesForwardedPort(Request $request): bool
    {
        return !self::configured() || $request->isFromTrustedProxy();
    }

    /**
     * The request the process's `$_SERVER` describes, for legacy code that has no `Request`. It is built once
     * and kept (a web request's server variables do not change), and built again only when one of the
     * variables the rules read has changed (a process that sets them: a test, a script).
     *
     * @param array<string, mixed> $server
     */
    public static function requestFor(array $server): Request
    {
        $seen = [];
        foreach (self::READ as $name) {
            $seen[$name] = $server[$name] ?? null;
        }
        if (self::$request === null || self::$seen !== $seen || self::$proxies !== Request::getTrustedProxies()) {
            self::$request = new Request([], [], [], [], [], $server);
            self::$seen = $seen;
            self::$proxies = Request::getTrustedProxies();
        }

        return self::$request;
    }

    private static function isEntry(string $entry): bool
    {
        if ($entry === 'REMOTE_ADDR' || $entry === 'PRIVATE_SUBNETS') {
            return true;
        }
        $parts = explode('/', $entry);
        if (count($parts) > 2) {
            return false;
        }
        $v6 = str_contains($parts[0], ':');
        if (filter_var($parts[0], FILTER_VALIDATE_IP, $v6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4) === false) {
            return false;
        }

        // A prefix of 0 would trust every address of the family.
        return count($parts) === 1 || (preg_match('/^[0-9]{1,3}$/', $parts[1]) === 1 && (int) $parts[1] >= 1 && (int) $parts[1] <= ($v6 ? 128 : 32));
    }

    private static function refusal(string $entry): string
    {
        $shown = substr((string) preg_replace('/[\x00-\x1f\x7f]/', '?', $entry), 0, 100);

        return 'The setting trusted_proxies has an entry that is not an IP address or range: ' . $shown;
    }
}
