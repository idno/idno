<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Webservice;
use Idno\Core\WebserviceFile;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Tests\IdnoTestCase;

/**
 * Webservice on a replaceable HTTP client: every test hands it a MockHttpClient (Webservice::useClient()),
 * so nothing here touches the network. What the real client puts on the wire is pinned, against the
 * site's own test routes, by Tests/Characterisation/WebserviceWireTest.
 */
final class WebserviceAdapterTest extends IdnoTestCase
{
    private string $previousErrorLog = '';
    private string $errorLog = '';

    /** @var mixed */
    private $previousMaxDownload;

    /** @var list<string> */
    private array $tempFiles = [];

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'idno-adapter-log');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
        $this->previousMaxDownload = \Idno\Core\Idno::site()->config()->webservice_max_download;
        $this->seen = [];
    }

    protected function tearDown(): void
    {
        Webservice::useClient(null);
        \Idno\Core\Idno::site()->config()->webservice_max_download = $this->previousMaxDownload;
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * A client that answers each request with the next response of $responses (a response, a callable
     * given the method, URL and options, or an exception to throw), and remembers what it was sent.
     *
     * @param list<MockResponse|\Throwable|callable> $responses
     */
    private function client(array $responses): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use (&$responses) {
            $this->seen[] = [$method, $url, $options];
            $next = array_shift($responses) ?? new MockResponse('', ['http_code' => 200]);
            if ($next instanceof \Throwable) {
                throw $next;
            }
            if (is_callable($next)) {
                $next = $next($method, $url, $options);
            }

            return $next;
        });
    }

    private function log(): string
    {
        return (string) file_get_contents($this->errorLog);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function headerOf(array $options, string $name): ?string
    {
        foreach ($options['normalized_headers'] ?? [] as $lower => $lines) {
            if ($lower === strtolower($name)) {
                return substr((string) $lines[0], strlen($name) + 2);
            }
        }

        return null;
    }

    private function tempFile(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'idno-adapter-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function testAnAddressWithItsPathAsTypedReachesTheClientEncodedNotCorrupted(): void
    {
        Webservice::useClient($this->client([new MockResponse('ok')]));

        // "р" is the bytes D1 80: the client's own URL parsing would turn 0x80 into an underscore
        $typed = "https://example.com/2026/\xD0\xBF\xD1\x80\xD0\xB8-caf\xC3\xA9?q=\xD1\x80";
        $result = Webservice::get($typed);

        $this->assertSame(200, $result['response']);
        $this->assertSame('https://example.com/2026/%D0%BF%D1%80%D0%B8-caf%C3%A9?q=%D1%80', $this->seen[0][1]);
        $this->assertSame($typed, $result['effective_url'], 'the address the caller gave is the one reported');
    }

    public function testAnInternationalisedHostIsLeftAloneWhenThePathIsEncoded(): void
    {
        Webservice::useClient($this->client([new MockResponse('ok')]));

        $typed = "https://b\xC3\xBCcher.example/\xD0\xBF\xD1\x80?q=\xD1\x80";
        $result = Webservice::get($typed);

        $this->assertSame(200, $result['response']);
        $host = (string) parse_url($this->seen[0][1], PHP_URL_HOST);
        $this->assertSame('xn--bcher-kva.example', $host, 'the client, not the adapter, turns the host into punycode');
        $this->assertStringEndsWith('/%D0%BF%D1%80?q=%D1%80', $this->seen[0][1]);
    }

    public function testOnlyWhatFollowsTheAuthorityIsEncoded(): void
    {
        Webservice::useClient($this->client([new MockResponse('ok')]));

        Webservice::get("https://user:p\xC3\xA9@example.com:8443/caf\xC3\xA9");

        $this->assertSame('https://user:p%C3%A9@example.com:8443/caf%C3%A9', $this->seen[0][1]);
    }

    public function testATransportFailureIsAnErrorResultNotAnException(): void
    {
        Webservice::useClient($this->client([new TransportException('Could not resolve host: example.invalid')]));

        $result = Webservice::get('https://example.invalid/page');

        $this->assertSame(['header', 'content', 'response', 'effective_url', 'error'], array_keys($result));
        $this->assertSame(0, $result['response']);
        $this->assertSame('', $result['content']);
        $this->assertSame('', $result['header']);
        $this->assertSame('https://example.invalid/page', $result['effective_url']);
        $this->assertStringContainsString('Could not resolve host', $result['error']);
        $this->assertSame('', Webservice::getLastResponse());
    }

    public function testAFailureThatComesWhileTheBodyIsReadIsAnErrorResultToo(): void
    {
        $body = (static function () {
            yield 'partial';
            throw new TransportException('Connection reset by peer');
        })();
        Webservice::useClient($this->client([new MockResponse($body, ['http_code' => 200])]));

        $result = Webservice::get('https://example.invalid/page');

        $this->assertSame(0, $result['response']);
        $this->assertSame('', $result['content']);
        $this->assertStringContainsString('Connection reset', $result['error']);
    }

    public function testAnAddressThatIsNotAUrlIsAnErrorResult(): void
    {
        Webservice::useClient(null);

        $result = Webservice::get('not a url');

        $this->assertSame(0, $result['response']);
        $this->assertNotSame('', $result['error']);
    }

    public function testACertificateFailureSaysSoInError(): void
    {
        Webservice::useClient($this->client([new TransportException('SSL certificate problem: self-signed certificate')]));

        $result = Webservice::get('https://self-signed.example.invalid/');

        $this->assertSame(0, $result['response']);
        $this->assertStringContainsStringIgnoringCase('certificate', $result['error']);
    }

    public function testAnUndeclaredLargeBodyIsCutAtTheLimit(): void
    {
        \Idno\Core\Idno::site()->config()->webservice_max_download = 1024 * 1024;
        $chunks = (static function () {
            for ($i = 0; $i < 48; ++$i) {
                yield str_repeat('a', 64 * 1024);
            }
        })();
        Webservice::useClient($this->client([new MockResponse($chunks, ['http_code' => 200])]));

        $result = Webservice::get('https://example.invalid/big');

        $this->assertSame(200, $result['response']);
        $this->assertLessThanOrEqual(1024 * 1024 + 64 * 1024, strlen($result['content']));
        $this->assertSame(1024 * 1024, strlen($result['content']));
        $this->assertNotSame('', $result['error']);
        $this->assertSame($result['content'], Webservice::getLastResponse());
    }

    public function testABodyUnderTheLimitWithNoDeclaredLengthIsWhole(): void
    {
        \Idno\Core\Idno::site()->config()->webservice_max_download = 1024;
        Webservice::useClient($this->client([new MockResponse([str_repeat('b', 500), str_repeat('c', 524)], ['http_code' => 200])]));

        $result = Webservice::get('https://example.invalid/exact');

        $this->assertSame(1024, strlen($result['content']));
        $this->assertSame('', $result['error']);
    }

    public function testADeclaredLengthOverTheLimitIsRefused(): void
    {
        \Idno\Core\Idno::site()->config()->webservice_max_download = 1000;
        Webservice::useClient($this->client([new MockResponse(str_repeat('x', 1001), ['http_code' => 200, 'response_headers' => ['Content-Length: 1001']])]));

        $result = Webservice::get('https://example.invalid/declared');

        $this->assertSame(200, $result['response']);
        $this->assertNotSame('', $result['error']);
        $this->assertSame('', $result['header']);
        $this->assertSame('', $result['content']);
    }

    public function testAHeadOfALargeFileIsNotRefusedForTheLengthItDeclares(): void
    {
        \Idno\Core\Idno::site()->config()->webservice_max_download = 1000;
        Webservice::useClient($this->client([new MockResponse('', ['http_code' => 200, 'response_headers' => ['Content-Length: 5000000', 'Content-Type: video/mp4']])]));

        $result = Webservice::head('https://example.invalid/big.mp4');

        $this->assertSame(200, $result['response']);
        $this->assertSame('', $result['error']);
        $this->assertSame('video/mp4', http_parse_headers($result['header'])['Content-Type']);
        $this->assertSame('', $result['content']);
    }

    public function testAnUndeclaredLargeBodyFromTheRealRouteIsCutAtTheLimit(): void
    {
        \Idno\Core\Idno::site()->config()->webservice_max_download = 1000;
        $base = \Idno\Core\Idno::site()->config()->getURL();

        $result = Webservice::get($base . '_test/stream?bytes=5000');

        $this->assertSame(200, $result['response']);
        $this->assertSame(str_repeat('a', 1000), $result['content']);
        $this->assertNotSame('', $result['error']);

        $result = Webservice::get($base . '_test/stream?bytes=1000');
        $this->assertSame(1000, strlen($result['content']));
        $this->assertSame('', $result['error']);
    }

    public function testTheContainersClientsAreTheOnesTheRealRouteSees(): void
    {
        $base = \Idno\Core\Idno::site()->config()->getURL();

        $site = json_decode(Webservice::get($base . '_test/echo')['content'], true);
        $remote = json_decode(Webservice::safeSend('get', $base . '_test/echo')['content'], true);

        $this->assertSame('site', $site['headers']['x-idno-test-client']);
        $this->assertSame('remote', $remote['headers']['x-idno-test-client']);
    }

    public function testSendUsesTheSiteClient(): void
    {
        $site = $this->client([new MockResponse('from the site client', ['http_code' => 200])]);
        $remote = $this->client([new MockResponse('from the remote client', ['http_code' => 200])]);
        Webservice::useClient($site, $remote);

        $this->assertSame('from the site client', Webservice::send('get', 'https://example.invalid/')['content']);
    }

    public function testSendToRemoteUsesTheRemoteClient(): void
    {
        $site = $this->client([new MockResponse('from the site client', ['http_code' => 200])]);
        $remote = $this->client([new MockResponse('from the remote client', ['http_code' => 200])]);
        Webservice::useClient($site, $remote);

        $this->assertSame('from the remote client', Webservice::safeSend('get', 'https://example.invalid/')['content']);
        $this->assertSame('from the site client', Webservice::send('get', 'https://example.invalid/')['content']);
    }

    public function testUseClientNullRestoresTheContainersClients(): void
    {
        Webservice::useClient($this->client([new MockResponse('replaced', ['http_code' => 200])]));
        $this->assertSame('replaced', Webservice::get('https://example.invalid/')['content']);

        Webservice::useClient(null);

        // The container's client in the test environment refuses every host but the site's own.
        $result = Webservice::get('https://example.invalid/');
        $this->assertSame(0, $result['response']);
        $this->assertStringContainsString('No network in tests', $result['error']);
    }

    public function testSafeSendInTheTestContainerRefusesAnAddressThatIsNotTheSitesOwnBeforeAnyPrivateNetworkCheck(): void
    {
        Webservice::useClient(null);

        // The test wrapper, outermost, lets only the site's own origin through: this shows that
        // layer. The private-network refusal is shown by testAPrivateSecondHopIsRefusedByTheRemoteClient.
        $result = Webservice::safeSend('get', 'http://10.0.0.1/');

        $this->assertSame(0, $result['response']);
        $this->assertStringContainsString('No network in tests: 10.0.0.1', $result['error']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privateTargets(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/'];
        yield 'private' => ['http://10.0.0.5/'];
        yield 'link-local' => ['http://169.254.169.254/'];
        yield 'IPv6 loopback' => ['http://[::1]/'];
    }

    /**
     * The adapter follows redirects itself, one request per hop, so the private-network client sees
     * each hop as a request of its own and refuses the second one: the real remote client built by the
     * factory, over a mock, with the site's client never used.
     *
     * @dataProvider privateTargets
     */
    public function testAPrivateSecondHopIsRefusedByTheRemoteClient(string $target): void
    {
        $remote = $this->client([new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: ' . $target]])]);
        $siteSeen = 0;
        $site = new MockHttpClient(static function () use (&$siteSeen): MockResponse {
            $siteSeen++;

            return new MockResponse('site');
        });
        Webservice::useClient($site, \Idno\Http\Client\HttpClientFactory::createRemote($remote));

        $result = Webservice::safeSend('get', 'https://93.184.216.34/start');

        $this->assertCount(1, $this->seen, 'only the first, public request reached the network client');
        $this->assertSame('https://93.184.216.34/start', $this->seen[0][1]);
        $this->assertSame(0, $siteSeen);
        $this->assertSame(0, $result['response']);
        $this->assertMatchesRegularExpression('/is blocked/', $result['error']);
        $this->assertSame('', $result['content']);
    }

    public function testHstsRewritesHttpToHttpsForAKnownHost(): void
    {
        $cache = \Idno\Core\Idno::site()->cache();
        $this->assertNotEmpty($cache, 'the test site has a cache');
        $host = 'hsts-adapter.example.invalid';
        try {
            Webservice::checkForHSTSHeader('https://' . $host . '/', ["HTTP/1.1 200 OK", 'Strict-Transport-Security: max-age=31536000']);
            $this->assertTrue(Webservice::isHSTS('http://' . $host . '/'));
            Webservice::useClient($this->client([new MockResponse('secure', ['http_code' => 200])]));

            $result = Webservice::get('http://' . $host . '/path', ['q' => '1']);

            $this->assertSame('https://' . $host . '/path?q=1', $this->seen[0][1]);
            $this->assertSame('https://' . $host . '/path?q=1', $result['effective_url']);
        } finally {
            $cache->delete($host);
        }
    }

    public function testAResponseWithAnHstsHeaderIsRemembered(): void
    {
        $cache = \Idno\Core\Idno::site()->cache();
        $host = 'hsts-learned.example.invalid';
        try {
            Webservice::useClient($this->client([new MockResponse('', ['http_code' => 200, 'response_headers' => ['Strict-Transport-Security: max-age=600']])]));
            $this->assertFalse(Webservice::isHSTS('https://' . $host . '/'));

            Webservice::get('https://' . $host . '/');

            $this->assertTrue(Webservice::isHSTS('https://' . $host . '/'));
        } finally {
            $cache->delete($host);
        }
    }

    public function testNoLogLineHoldsAQueryStringOrAnAuthorizationHeader(): void
    {
        $url = 'https://user:hunter2@example.invalid/some/path?access_token=SECRETTOKEN&x=1';
        Webservice::useClient($this->client([
            new TransportException('Idle timeout reached for "' . $url . '".'),
            new MockResponse('', ['http_code' => 200, 'response_headers' => ['Content-Length: 99999999999']]),
        ]));

        Webservice::get($url, null, ['Authorization: Bearer SECRETBEARER', 'Cookie: session=SECRETCOOKIE']);
        Webservice::get($url, null, ['Authorization: Bearer SECRETBEARER']);

        $log = $this->log();
        $this->assertStringContainsString('https://example.invalid/some/path', $log);
        foreach (['SECRETTOKEN', 'SECRETBEARER', 'SECRETCOOKIE', 'hunter2', 'access_token'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesCarryingSecrets(): iterable
    {
        yield 'no scheme, token in the query' => ['example.com/x?token=secret-token-value'];
        yield 'ftp with userinfo' => ['ftp://user:secret-pass@host.example.invalid/x?token=secret-token-value'];
        yield 'https with userinfo failing in transport' => ['https://user:secret-pass@host.example.invalid/x?token=secret-token-value'];
        yield 'a backslash and a leading space' => [' https:\\\\user:secret-pass@host.example.invalid\\x?token=secret-token-value'];
    }

    /**
     * @dataProvider addressesCarryingSecrets
     */
    public function testNoLogLineHoldsTextTakenFromAnAddressOrAnExceptionMessage(string $address): void
    {
        $quoting = new TransportException('Could not connect to "' . $address . '".');
        foreach (['send', 'safeSend'] as $method) {
            Webservice::useClient($this->client([$quoting]), $this->client([$quoting]));

            $result = Webservice::$method('get', $address);

            $this->assertSame(0, $result['response']);
            $this->assertNotSame('', $result['error']);
        }

        $log = $this->log();
        $this->assertStringContainsString('Webservice request failed', $log);
        $this->assertStringNotContainsString('secret', $log);
        $this->assertStringNotContainsString('token=', $log);
    }

    public function testAnAddressTheOldClientToleratedIsAnErrorResultNotAnException(): void
    {
        Webservice::useClient(null);

        foreach (['example.com/page', 'ftp://example.com/file', ' https://example.com/'] as $address) {
            $result = Webservice::get($address);
            $this->assertSame(0, $result['response'], $address);
            $this->assertNotSame('', $result['error'], $address);
        }
    }

    public function testAFailureIsLoggedOnceWithControlCharactersReplacedAndBounded(): void
    {
        $path = '/a' . str_repeat('b', 400);
        Webservice::useClient($this->client([new TransportException("boom\r\nInjected: line" . str_repeat('z', 400))]));

        Webservice::get('https://example.invalid' . $path . '?q=1');

        $lines = array_values(array_filter(explode("\n", $this->log()), static fn (string $line): bool => str_contains($line, 'Webservice')));
        $this->assertCount(1, $lines);
        $this->assertStringNotContainsString("\r", $lines[0]);
        $this->assertStringNotContainsString('Injected: line' . str_repeat('z', 400), $lines[0]);
        $this->assertStringNotContainsString('?q=1', $lines[0]);
        $this->assertLessThan(700, strlen($lines[0]));
    }

    public function testGetLastRequestIsTheRequestLineAndHeadersWithSecretsRedacted(): void
    {
        Webservice::useClient($this->client([new MockResponse('ok', ['http_code' => 200])]));

        Webservice::get('https://example.invalid/p?a=1', ['b' => '2'], [
            'Authorization: Bearer SECRETBEARER',
            'Cookie: a=SECRETCOOKIE',
            'Signature: keyId="x",signature="SECRETSIG"',
            'X-Visible: yes',
        ]);

        $request = Webservice::getLastRequest();
        $this->assertStringStartsWith("GET /p?a=1&b=2 HTTP/1.1\r\n", $request);
        $this->assertStringContainsString("\r\nHost: example.invalid\r\n", $request);
        $this->assertStringContainsString("\r\nX-Visible: yes\r\n", $request);
        $this->assertStringContainsString("\r\nAuthorization: [redacted]\r\n", $request);
        $this->assertStringContainsString("\r\nCookie: [redacted]\r\n", $request);
        $this->assertStringContainsString("\r\nSignature: [redacted]\r\n", $request);
        $this->assertStringEndsWith("\r\n\r\n", $request);
        foreach (['SECRETBEARER', 'SECRETCOOKIE', 'SECRETSIG'] as $secret) {
            $this->assertStringNotContainsString($secret, $request);
        }
        $this->assertSame('ok', Webservice::getLastResponse());
    }

    public function testAnAssociativeHeaderIsSentAndAListEntryWithoutAColonIsNot(): void
    {
        Webservice::useClient($this->client([]));

        Webservice::post('https://example.invalid/', 'a=1', ['Content-Type' => 'application/json', 'X-One: 1', 'no colon here', 'Expect:']);

        $options = $this->seen[0][2];
        $this->assertSame('application/json', $this->headerOf($options, 'Content-Type'));
        $this->assertSame('1', $this->headerOf($options, 'X-One'));
        $this->assertNull($this->headerOf($options, 'no colon here'));
        $this->assertNull($this->headerOf($options, 'Expect'));
    }

    public function testRedirectsAreFollowedOneRequestPerHopAndEveryHopsHeadersAreKept(): void
    {
        Webservice::useClient($this->client([
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location: /two']]),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://other.example.invalid/three?x=1']]),
            new MockResponse('done', ['http_code' => 200, 'response_headers' => ['Content-Type: text/plain']]),
        ]));

        $result = Webservice::get('https://example.invalid/one', null, ['Authorization: Bearer T']);

        $this->assertCount(3, $this->seen);
        $this->assertSame(['https://example.invalid/one', 'https://example.invalid/two', 'https://other.example.invalid/three?x=1'], array_column($this->seen, 1));
        foreach ($this->seen as [, , $options]) {
            $this->assertSame(0, $options['max_redirects']);
        }
        $this->assertSame('Bearer T', $this->headerOf($this->seen[1][2], 'Authorization'));
        $this->assertNull($this->headerOf($this->seen[2][2], 'Authorization'), 'a credential does not follow a redirect to another host');
        $this->assertSame(200, $result['response']);
        $this->assertSame('done', $result['content']);
        $this->assertSame('https://other.example.invalid/three?x=1', $result['effective_url']);
        $this->assertSame('', $result['error']);
        $this->assertSame(3, substr_count($result['header'], 'HTTP/1.1 '));
        $this->assertStringContainsString("HTTP/1.1 301 Moved Permanently\r\nLocation: /two\r\n\r\n", $result['header']);
    }

    public function testSigningHeadersDoNotFollowARedirectToAnotherOrigin(): void
    {
        $headers = ['Authorization: Bearer T', 'cookie: a=b', 'Signature: keyId="k"', 'x-idno-service-signature: abc', 'DPoP: proof', 'Accept: text/html'];
        Webservice::useClient($this->client([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: /same']]),
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://other.example.invalid/away']]),
            new MockResponse('done', ['http_code' => 200]),
        ]));

        Webservice::get('https://example.invalid/start', null, $headers);

        $this->assertCount(3, $this->seen);
        foreach (['Authorization', 'Cookie', 'Signature', 'X-Idno-Service-Signature', 'DPoP', 'Accept'] as $name) {
            $this->assertNotNull($this->headerOf($this->seen[1][2], $name), $name . ' stays on a same-origin redirect');
        }
        foreach (['Authorization', 'Cookie', 'Signature', 'X-Idno-Service-Signature', 'DPoP'] as $name) {
            $this->assertNull($this->headerOf($this->seen[2][2], $name), $name . ' does not follow a redirect to another origin');
        }
        $this->assertSame('text/html', $this->headerOf($this->seen[2][2], 'Accept'));
    }

    public function testASixthRedirectIsNotFollowed(): void
    {
        $responses = [];
        for ($i = 0; $i < 8; ++$i) {
            $responses[] = new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: /next' . $i]]);
        }
        Webservice::useClient($this->client($responses));

        $result = Webservice::get('https://example.invalid/start');

        $this->assertCount(6, $this->seen);
        $this->assertSame(302, $result['response']);
        $this->assertSame('https://example.invalid/next4', $result['effective_url']);
        $this->assertNotSame('', $result['error']);
        $this->assertSame('', $result['header']);
        $this->assertSame('', $result['content']);
    }

    public function testFileGetContentsExReturnsTheFailedResultAndFileGetContentsFalseAfterTooManyRedirects(): void
    {
        $responses = [];
        for ($i = 0; $i < 16; ++$i) {
            $responses[] = new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: /next' . $i]]);
        }
        Webservice::useClient($this->client($responses));

        $result = Webservice::file_get_contents_ex('https://example.invalid/start');
        $this->assertSame(302, $result['response']);
        $this->assertNotSame('', $result['error']);

        $this->assertFalse(Webservice::file_get_contents('https://example.invalid/start'));
    }

    public function testAPostBecomesAGetOnA302AndKeepsItsMethodOnA307(): void
    {
        Webservice::useClient($this->client([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: /two']]),
            new MockResponse('ok', ['http_code' => 200]),
            new MockResponse('', ['http_code' => 307, 'response_headers' => ['Location: /two']]),
            new MockResponse('ok', ['http_code' => 200]),
        ]));

        Webservice::post('https://example.invalid/one', 'a=1');
        $this->assertSame('GET', $this->seen[1][0]);
        $this->assertSame('', (string) ($this->seen[1][2]['body'] ?? ''));
        $this->assertNull($this->headerOf($this->seen[1][2], 'Content-Type'));

        Webservice::post('https://example.invalid/one', 'a=1');
        $this->assertSame('POST', $this->seen[3][0]);
        $this->assertSame('a=1', $this->seen[3][2]['body']);
        $this->assertSame('application/x-www-form-urlencoded', $this->headerOf($this->seen[3][2], 'Content-Type'));
    }

    public function testAnArrayBodyIsMultipartAndAWebserviceFileIsUploadedWithItsNameAndType(): void
    {
        $path = $this->tempFile('file contents');
        Webservice::useClient($this->client([]));

        Webservice::post('https://example.invalid/', ['caption' => 'hi', 'upload' => new WebserviceFile($path, 'text/plain', 'hello.txt')]);

        $options = $this->seen[0][2];
        $this->assertStringStartsWith('multipart/form-data; boundary=', (string) $this->headerOf($options, 'Content-Type'));
        $body = (string) $options['body'];
        $this->assertStringContainsString('name="caption"', $body);
        $this->assertStringContainsString('name="upload"; filename="hello.txt"', $body);
        $this->assertStringContainsString('Content-Type: text/plain', $body);
        $this->assertStringContainsString('file contents', $body);
    }

    public function testAnUnreadableUploadIsAnErrorResultAndNothingIsSent(): void
    {
        Webservice::useClient($this->client([]));

        $result = Webservice::post('https://example.invalid/', ['upload' => new WebserviceFile('/nonexistent/file', 'text/plain', 'x.txt')]);

        $this->assertSame(0, $result['response']);
        $this->assertNotSame('', $result['error']);
        $this->assertSame([], $this->seen);
    }

    public function testTheWebserviceFileStillGivesCurlParameters(): void
    {
        $path = $this->tempFile('x');
        $file = new WebserviceFile($path, 'text/plain', 'x.txt');

        $this->assertInstanceOf(\CURLFile::class, $file->getCurlParameters());
    }

    public function testPutAndDeleteDefaultsAreAsTheyWere(): void
    {
        Webservice::useClient($this->client([]));

        Webservice::put('https://example.invalid/', 'hello');
        $this->assertSame('multipart/form-data', $this->headerOf($this->seen[0][2], 'Content-Type'));
        $this->assertSame('hello', $this->seen[0][2]['body']);

        Webservice::delete('https://example.invalid/', ['a' => '1']);
        $this->assertSame('DELETE', $this->seen[1][0]);
        $this->assertSame('https://example.invalid/?a=1', $this->seen[1][1]);
        $this->assertStringStartsWith('multipart/form-data; boundary=', (string) $this->headerOf($this->seen[1][2], 'Content-Type'));

        Webservice::delete('https://example.invalid/');
        $this->assertSame('multipart/form-data', $this->headerOf($this->seen[2][2], 'Content-Type'));
    }

    public function testTheWebserviceHeadersListenerIsStillAskedAndMerged(): void
    {
        $active = true;
        $seen = [];
        \Idno\Core\Idno::site()->events()->addListener('webservice:headers', function (\Idno\Core\Event $event) use (&$active, &$seen): void {
            if ($active) {
                $seen[] = $event->data();
                $event->setResponse(['X-From-Listener' => 'yes']);
            }
        });
        Webservice::useClient($this->client([]));

        try {
            Webservice::get('https://example.invalid/', null, ['X-Caller: mine']);
        } finally {
            $active = false;
        }

        $this->assertSame('yes', $this->headerOf($this->seen[0][2], 'X-From-Listener'));
        $this->assertSame('mine', $this->headerOf($this->seen[0][2], 'X-Caller'));
        $this->assertSame('get', $seen[0]['verb']);
    }

    public function testNoCurlFunctionIsCalledAnywhereInTheFile(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/Idno/Core/Webservice.php');

        $this->assertSame(0, preg_match('/\bcurl_[a-z_]+\s*\(/', $source));
        $this->assertFalse(method_exists(Webservice::class, 'webservice_exec'));
    }

    public function testTheClientIsFetchedAtCallTimeNotKept(): void
    {
        $first = $this->client([new MockResponse('first', ['http_code' => 200])]);
        $second = $this->client([new MockResponse('second', ['http_code' => 200])]);

        Webservice::useClient($first);
        $this->assertSame('first', Webservice::get('https://example.invalid/')['content']);
        Webservice::useClient($second);
        $this->assertSame('second', Webservice::get('https://example.invalid/')['content']);
        $this->assertInstanceOf(HttpClientInterface::class, \Idno\Core\Idno::container()->get('idno.http_client'));
    }

    public function testSafeFileGetContentsReadsThroughTheRemoteClientOnly(): void
    {
        $site = $this->client([new MockResponse('site', ['http_code' => 200])]);
        $remote = $this->client([new MockResponse('remote body', ['http_code' => 200])]);

        Webservice::useClient($site, $remote);
        $body = Webservice::safeFileGetContents('https://example.invalid/page');

        $this->assertSame('remote body', $body);
        $this->assertCount(1, $this->seen);
        $this->assertSame('GET', $this->seen[0][0]);
        $this->assertSame('https://example.invalid/page', $this->seen[0][1]);
    }

    public function testSafeFileGetContentsDoesNotTouchTheSiteClient(): void
    {
        $siteSeen = [];
        $site = new MockHttpClient(function (string $method, string $url) use (&$siteSeen): MockResponse {
            $siteSeen[] = $url;

            return new MockResponse('site', ['http_code' => 200]);
        });
        $remote = $this->client([new MockResponse('remote', ['http_code' => 200])]);

        Webservice::useClient($site, $remote);
        $this->assertSame('remote', Webservice::safeFileGetContents('https://example.invalid/'));
        $this->assertSame([], $siteSeen);
    }

    public function testSafeFileGetContentsIsFalseOnATransportFailure(): void
    {
        $remote = $this->client([new TransportException('Could not resolve host')]);

        Webservice::useClient($this->client([]), $remote);

        $this->assertFalse(Webservice::safeFileGetContents('https://example.invalid/'));
    }

    public function testSafeFileGetContentsIsFalseForARefusedAddress(): void
    {
        $inner = $this->client([new MockResponse('secret', ['http_code' => 200])]);
        $remote = new \Symfony\Component\HttpClient\NoPrivateNetworkHttpClient($inner);

        Webservice::useClient($this->client([]), $remote);

        $this->assertFalse(Webservice::safeFileGetContents('http://127.0.0.1/private'));
        $this->assertSame([], $this->seen, 'nothing was sent');
    }
}
