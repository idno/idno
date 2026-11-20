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
            'headers' => ['User-Agent' => self::USER_AGENT],
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
     * The client for a URL a remote party supplied: it refuses every request, and every redirect it
     * follows, whose host resolves to a loopback, private, link-local or reserved address.
     */
    public static function createRemote(HttpClientInterface $client): HttpClientInterface
    {
        return new NoPrivateNetworkHttpClient($client);
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
