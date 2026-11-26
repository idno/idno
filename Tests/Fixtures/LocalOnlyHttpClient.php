<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Config\BootConfig;
use Idno\Http\Client\HttpClientFactory;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Test-environment only (config/services_test.php): wraps one of the site's HTTP clients so that a
 * test can never reach the network. A request goes through only when its host and port are the
 * site's own (the boot configuration's `url`) or listed as comma-separated host:port in the
 * environment variable IDNO_TEST_HTTP_ALLOW (read from $_SERVER at each request); any other throws a TransportException `No network in tests: <host>` (the host
 * alone: never a path, a query or credentials). Each request carries the header X-Idno-Test-Client
 * (`site` or `remote`), so that /_test/echo and the sinks show which client sent it.
 *
 * The remote client refuses private networks; its test wiring gives the wrapper a second client, the
 * site's, for the site's own origin only (an origin listed in IDNO_TEST_HTTP_ALLOW still meets the
 * refusal), so that this site's routes (on a loopback address) can stand
 * in for remote servers. The origins IDNO_TEST_REMOTE_STAND_IN lists (comma-separated host:port, read like IDNO_TEST_HTTP_ALLOW) are stand-ins for other
 * servers: a request to one is made to the same path under `/_test/remote` of the site's own origin (`RemoteDocumentController`), so a test can
 * serve a page that the site fetches from a public address, which no request may reach. Nothing in production can reach this: the class is in Tests/ and only
 * config/services_test.php wires it.
 */
final class LocalOnlyHttpClient implements HttpClientInterface, ResetInterface
{
    /** @var \WeakMap<ResponseInterface, HttpClientInterface> */
    private \WeakMap $madeBy;

    /**
     * @param \Closure(): list<string> $siteOrigins evaluated at each request: the site's own origin, lower-case "host:port"
     * @param ?HttpClientInterface $siteClient the client that talks to the site's own origin, when it
     *                                          must not be $client (the remote client's wrapper)
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $kind,
        private readonly \Closure $siteOrigins,
        private readonly ?HttpClientInterface $siteClient = null,
        private readonly int $maxRedirects = HttpClientFactory::MAX_REDIRECTS,
    )
    {
        $this->madeBy = new \WeakMap();
    }

    public static function forSite(HttpClientInterface $siteClient, BootConfig $config): self
    {
        return new self($siteClient, 'site', self::siteOrigins($config));
    }

    public static function forRemote(HttpClientInterface $remoteClient, HttpClientInterface $siteClient, BootConfig $config): self
    {
        return new self($remoteClient, 'remote', self::siteOrigins($config), $siteClient);
    }

    /**
     * The site's own origin, from the boot configuration's `url` (set in config.ini, and by
     * IDNO_URL for a server a test starts on another port).
     *
     * @return \Closure(): list<string>
     */
    public static function siteOrigins(BootConfig $config): \Closure
    {
        return static function () use ($config): array {
            $site = $config->get('url');
            $origin = is_string($site) ? self::origin($site) : null;

            return $origin === null ? [] : [$origin];
        };
    }

    /**
     * The origins IDNO_TEST_HTTP_ALLOW lists (comma-separated host:port), lower case.
     *
     * @return list<string>
     */
    private static function allowListed(): array
    {
        $allow = $_SERVER['IDNO_TEST_HTTP_ALLOW'] ?? '';
        $origins = [];
        foreach (explode(',', is_string($allow) ? $allow : '') as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry !== '') {
                $origins[] = $entry;
            }
        }

        return $origins;
    }

    /**
     * "host:port" of an http or https URL (the port defaults by scheme), lower case; null for any
     * other URL.
     */
    public static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        return strtolower($parts['host']) . ':' . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    /**
     * A relative URL (one that relies on a `base_uri` option) is refused by design: the wrapper
     * cannot know where it would go, and the site's clients set no `base_uri`.
     *
     * Redirects are followed here, not by the inner client (which is sent `max_redirects` 0), so that
     * every hop meets the origin check and chooses its inner client: a redirect from an allowed origin
     * to any other host is refused (`No network in tests: <host>`) before a second request is made.
     * The caller's `max_redirects` (else the client's default) is honoured; when the redirects it
     * allows are used up, the last 3xx response is returned, as curl does at its limit. 303, and 301
     * and 302 after a POST, become a GET without a body; 307 and 308 send the request again.
     * `redirect_count` is not kept. The following is synchronous: it reads each status.
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $max = max(0, (int) ($options['max_redirects'] ?? $this->maxRedirects));
        $options['max_redirects'] = 0;
        $firstOrigin = self::origin($url);

        for ($hop = 0;; $hop++) {
            $url = $this->standIn($url);
            [$origin, $client] = $this->admit($url);

            $headers = $options['headers'] ?? [];
            $headers = is_array($headers) ? $headers : [];
            $headers['X-Idno-Test-Client'] = $this->kind;
            $options['headers'] = $headers;

            $response = $client->request($method, $url, $options);
            $status = $response->getStatusCode();
            $location = in_array($status, [301, 302, 303, 307, 308], true) ? ($response->getHeaders(false)['location'][0] ?? '') : '';
            if ($location === '' || $hop >= $max) {
                $this->madeBy[$response] = $client;

                return $response;
            }

            $response->cancel();
            $url = self::resolve($url, $location);
            unset($options['query']);

            if ($status === 303 || ($method === 'POST' && ($status === 301 || $status === 302))) {
                $method = $method === 'HEAD' ? 'HEAD' : 'GET';
                unset($options['body'], $options['json']);
                $options['headers'] = self::withoutHeaders($options['headers'], ['content-length', 'content-type', 'transfer-encoding']);
            }

            if (self::origin($url) !== $firstOrigin) {
                unset($options['auth_basic'], $options['auth_bearer']);
                $options['headers'] = self::withoutHeaders($options['headers'], ['authorization', 'cookie']);
            }
        }
    }

    /**
     * The URL, or where a stand-in for the server it names is: the same path (and query) under `/_test/remote` of the site's own origin.
     */
    private function standIn(string $url): string
    {
        $stand = $_SERVER['IDNO_TEST_REMOTE_STAND_IN'] ?? '';
        $origin = self::origin($url);
        $site = ($this->siteOrigins)()[0] ?? null;
        if ($origin === null || $site === null || !in_array($origin, array_map('trim', explode(',', strtolower(is_string($stand) ? $stand : ''))), true)) {
            return $url;
        }
        $parts = parse_url($url);

        return 'http://' . $site . '/_test/remote' . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * @return array{string, HttpClientInterface} the URL's origin and the inner client for it
     */
    private function admit(string $url): array
    {
        $origin = self::origin($url);
        $siteOrigins = ($this->siteOrigins)();
        if ($origin === null || !in_array($origin, [...$siteOrigins, ...self::allowListed()], true)) {
            $host = parse_url($url, PHP_URL_HOST);
            throw new TransportException('No network in tests: ' . (is_string($host) && $host !== '' ? $host : 'unknown host'));
        }

        return [$origin, $this->siteClient !== null && in_array($origin, $siteOrigins, true) ? $this->siteClient : $this->client];
    }

    private static function resolve(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = (string) ($parts['scheme'] ?? 'http');
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        $authority = (string) ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if ($location[0] === '/') {
            return $scheme . '://' . $authority . $location;
        }

        $path = (string) ($parts['path'] ?? '/');

        return $scheme . '://' . $authority . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }

    /**
     * @param array<array-key, mixed> $headers option-style headers: "Name: value" lines or name => value
     * @param list<string> $names lower-case names to drop
     *
     * @return array<array-key, mixed>
     */
    private static function withoutHeaders(array $headers, array $names): array
    {
        $kept = [];
        foreach ($headers as $key => $value) {
            $name = strtolower(trim(is_int($key) ? explode(':', (string) $value, 2)[0] : $key));
            if (!in_array($name, $names, true)) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $responses = $responses instanceof ResponseInterface ? [$responses] : (is_array($responses) ? $responses : iterator_to_array($responses, false));

        $client = null;
        foreach ($responses as $response) {
            $madeBy = $this->madeBy[$response] ?? $this->client;
            if ($client !== null && $client !== $madeBy) {
                throw new \LogicException('Cannot stream responses made by different clients together.');
            }
            $client = $madeBy;
        }

        return ($client ?? $this->client)->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new self(
            $this->client->withOptions($options),
            $this->kind,
            $this->siteOrigins,
            $this->siteClient?->withOptions($options),
            isset($options['max_redirects']) ? (int) $options['max_redirects'] : $this->maxRedirects,
        );
    }

    public function reset(): void
    {
        foreach ([$this->client, $this->siteClient] as $client) {
            if ($client instanceof ResetInterface) {
                $client->reset();
            }
        }
    }
}
