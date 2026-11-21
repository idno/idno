<?php

declare(strict_types=1);

namespace Idno\Http\Client;

use Idno\Config\BootConfig;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the site's HTTP clients. options() is pure: it maps the three boot-configuration settings
 * that concern outbound requests (proxy_string, proxy_type, disable_ssl_verify) and the fixed limits
 * to the client's options, so that it can be tested with arrays.
 */
final class HttpClientFactory
{
    public const USER_AGENT = 'Idno https://idno.co';

    /** Seconds without receiving anything after which a request fails. */
    public const IDLE_TIMEOUT = 10.0;

    /** Seconds after which a request fails, however much it is still receiving. */
    public const MAX_DURATION = 60.0;

    public const MAX_REDIRECTS = 5;

    /**
     * @param array<string, mixed> $config the boot configuration's values
     *
     * @return array<string, mixed>
     */
    public static function options(array $config): array
    {
        // Any non-empty value turns verification off, as the legacy empty() check did: the string
        // 'false' is non-empty, so it disables it too ('0' and '' do not).
        $verify = empty($config['disable_ssl_verify']);

        $options = [
            // No compression is asked for: the client decodes a compressed body a chunk at a time, and a
            // chunk of a few kilobytes on the wire can decode to megabytes, which the size limit cannot
            // bound. Naming an encoding also stops the client decoding a body a server compresses anyway.
            'headers' => ['User-Agent' => self::USER_AGENT, 'Accept-Encoding' => 'identity'],
            'timeout' => self::IDLE_TIMEOUT,
            'max_duration' => self::MAX_DURATION,
            'max_redirects' => self::MAX_REDIRECTS,
            'verify_peer' => $verify,
            'verify_host' => $verify,
        ];

        $proxy = $config['proxy_string'] ?? null;
        if (is_string($proxy) && trim($proxy) !== '') {
            $options['proxy'] = self::proxyUrl(trim($proxy), $config['proxy_type'] ?? null);
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $config the boot configuration's values
     */
    public static function create(array $config): HttpClientInterface
    {
        return HttpClient::create(self::options($config));
    }

    /**
     * The site's client, from the boot configuration (the container's factory: nothing from the
     * configuration is compiled into the container).
     */
    public static function fromBootConfig(BootConfig $config): HttpClientInterface
    {
        return self::create([
            'proxy_string' => $config->get('proxy_string'),
            'proxy_type' => $config->get('proxy_type'),
            'disable_ssl_verify' => $config->get('disable_ssl_verify'),
        ]);
    }

    /**
     * The remote client, from the boot configuration (the container's factory).
     */
    public static function remoteFromBootConfig(HttpClientInterface $client, BootConfig $config): HttpClientInterface
    {
        return self::createRemote($client, [
            'proxy_string' => $config->get('proxy_string'),
            'proxy_type' => $config->get('proxy_type'),
            'http_allow_private' => $config->get('http_allow_private'),
        ]);
    }

    /**
     * The client for a URL a remote party supplied: it refuses every request, and every redirect it
     * follows, whose host resolves to a loopback, private, link-local or reserved address, except the
     * addresses `http_allow_private` lists (IP addresses and CIDR ranges, as a list or one
     * comma-separated string). The single entry `*` returns the site's client itself: nothing is
     * refused. When `proxy_string` is set, or, with none, when the standard proxy environment variables
     * name a proxy the client would use (see proxyAddressesFromEnvironment()), the proxy's own addresses
     * (its host if that is an IP address, else what it resolves to now) are not refused as the address the client connected to,
     * which is the proxy's and would otherwise fail every request; the target's addresses are still
     * checked before each request, and a target at the proxy's own address is refused like any other.
     * An invalid entry throws a \LogicException.
     *
     * Each call builds a client with a DNS cache of its own; a long-running process fetches the service
     * afresh for each use.
     *
     * @param array<string, mixed>                $config  the boot configuration's values
     * @param (\Closure(string): list<string>)|null $resolve addresses of a host name, for the proxy's (null: the system resolver)
     * @param array<string, mixed>|null           $environment the proxy environment variables, as `$_SERVER` holds them (null: the real ones; tests pass an array)
     */
    public static function createRemote(HttpClientInterface $client, array $config = [], ?\Closure $resolve = null, ?array $environment = null): HttpClientInterface
    {
        $allowed = self::allowedPrivate($config['http_allow_private'] ?? null);
        if (in_array('*', $allowed, true)) {
            return $client;
        }

        $proxy = self::options($config)['proxy'] ?? null;
        $proxyAddresses = is_string($proxy)
            ? self::addressesOf($proxy, $resolve)
            : self::proxyAddressesFromEnvironment($environment ?? $_SERVER, in_array(PHP_SAPI, ['cli', 'phpdbg'], true), $resolve);

        return new NoPrivateNetworkHttpClient(
            $proxyAddresses === [] ? $client : new ProxyAddressMask($client, $proxyAddresses),
            null,
            $allowed
        );
    }

    /**
     * The addresses of the proxies the HTTP client uses when no `proxy` option is given, from the
     * environment variables it reads, by its rules (Symfony's HttpClientTrait::getProxyUrl(), which the
     * curl client applies too; curl itself is told its proxy explicitly and reads nothing): the http
     * proxy is `http_proxy`, else `HTTP_PROXY` (only on the command line: it is ignored elsewhere because
     * a web server turns a request's Proxy header into it), else `all_proxy`, else `ALL_PROXY`; the https
     * proxy is `https_proxy`, else `HTTPS_PROXY`, else the http proxy. The remote client fetches both
     * schemes, so both are returned. A variable that is set but empty is the proxy, as the client reads it:
     * it hides the later ones and names no address. `no_proxy` needs nothing here: a host it exempts is
     * connected to directly and reports its own address, which the private-network check judges as usual.
     * An address that cannot be read or resolved is left out, so the check refuses a connection to it.
     *
     * @param array<string, mixed>                   $environment variables by name, as `$_SERVER` holds them
     * @param (\Closure(string): list<string>)|null  $resolve     addresses of a host name (null: the system resolver)
     *
     * @return list<string>
     */
    public static function proxyAddressesFromEnvironment(array $environment, bool $commandLine, ?\Closure $resolve = null): array
    {
        $http = $environment['http_proxy'] ?? ($commandLine ? $environment['HTTP_PROXY'] ?? null : null) ?? $environment['all_proxy'] ?? $environment['ALL_PROXY'] ?? null;
        $https = $environment['https_proxy'] ?? $environment['HTTPS_PROXY'] ?? $http;

        $addresses = [];
        foreach ([$http, $https] as $proxy) {
            if (is_string($proxy) && $proxy !== '') {
                foreach (self::addressesOf($proxy, $resolve) as $address) {
                    $addresses[$address] = $address;
                }
            }
        }

        return array_values($addresses);
    }

    /**
     * @param (\Closure(string): list<string>)|null $resolve
     *
     * @return list<string>
     */
    private static function addressesOf(string $proxy, ?\Closure $resolve): array
    {
        $host = (parse_url($proxy) ?: [])['host'] ?? '';
        $host = is_string($host) ? trim($host, '[]') : '';
        if ($host === '') {
            return [];
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : array_values(($resolve ?? static fn (string $name): array => gethostbynamel($name) ?: [])($host));
    }

    /**
     * The entries of `http_allow_private`: a list, or a comma-separated string. Each is an IP address,
     * a CIDR range or `*`.
     *
     * @return list<string>
     */
    private static function allowedPrivate(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            throw new \LogicException('The setting http_allow_private must be a list of IP addresses and ranges, or "*": got ' . get_debug_type($value));
        }

        $entries = [];
        foreach ($value as $entry) {
            $text = is_string($entry) ? trim($entry) : (is_scalar($entry) ? (string) $entry : get_debug_type($entry));
            if ($text === '' && is_string($entry)) {
                continue;
            }
            if ($text !== '*' && !(is_string($entry) && self::isAddressOrRange($text))) {
                throw new \LogicException('The setting http_allow_private holds "' . $text . '", which is not an IP address or a CIDR range with a prefix length of 1 or more; use "*" to switch the refusal off');
            }
            $entries[] = $text;
        }

        return $entries;
    }

    private static function isAddressOrRange(string $entry): bool
    {
        $parts = explode('/', $entry);
        if (count($parts) > 2) {
            return false;
        }
        $v6 = str_contains($parts[0], ':');
        if (filter_var($parts[0], FILTER_VALIDATE_IP, $v6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4) === false) {
            return false;
        }

        // A prefix of 0 is every address of the family, which only `*` may say.
        return count($parts) === 1 || (preg_match('/^[0-9]{1,3}$/', $parts[1]) === 1 && (int) $parts[1] >= 1 && (int) $parts[1] <= ($v6 ? 128 : 32));
    }

    private static function proxyUrl(string $proxy, mixed $type): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $proxy) === 1) {
            return $proxy;
        }

        $scheme = match (is_string($type) ? $type : '') {
            'socks4', 'CURLPROXY_SOCKS4' => 'socks4',
            'socks5', 'CURLPROXY_SOCKS5' => 'socks5',
            'socks5-hostname', 'CURLPROXY_SOCKS5_HOSTNAME' => 'socks5h',
            default => 'http',
        };

        return $scheme . '://' . $proxy;
    }
}
