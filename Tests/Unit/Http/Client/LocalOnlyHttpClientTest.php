<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Client;

use Idno\Config\BootConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Fixtures\LocalOnlyHttpClient;

final class LocalOnlyHttpClientTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['IDNO_TEST_HTTP_ALLOW']);
    }

    /**
     * @param list<string> $seen
     */
    private function mock(array &$seen, string $body = 'ok'): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen, $body): MockResponse {
            $seen[] = $url . ' ' . json_encode($options['headers'] ?? [], JSON_UNESCAPED_SLASHES);

            return new MockResponse($body);
        });
    }

    private function config(string $url = 'http://127.0.0.1:8089/'): BootConfig
    {
        return new BootConfig(['url' => $url], false);
    }

    public function testTheSiteItselfIsReachedWithTheClientsKind(): void
    {
        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->mock($seen), $this->config());

        $this->assertSame('ok', $client->request('GET', 'http://127.0.0.1:8089/_test/echo')->getContent());
        $this->assertCount(1, $seen);
        $this->assertStringContainsString('X-Idno-Test-Client: site', $seen[0]);
    }

    public function testAnotherHostPortOrSchemeIsRefusedWithTheHostOnly(): void
    {
        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->mock($seen), $this->config());

        foreach ([
            'https://user:pass@example.com/secret/path?token=abc' => 'example.com',
            'http://127.0.0.1:8090/' => '127.0.0.1',
            'http://localhost:8089/' => 'localhost',
            'ftp://example.org/x' => 'example.org',
        ] as $url => $host) {
            try {
                $client->request('GET', $url);
                $this->fail($url . ' was sent');
            } catch (TransportException $e) {
                $this->assertSame('No network in tests: ' . $host, $e->getMessage());
            }
        }
        $this->assertSame([], $seen);
    }

    public function testTheSchemeIsNotPartOfTheOrigin(): void
    {
        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->mock($seen), $this->config());

        $client->request('GET', 'https://127.0.0.1:8089/')->getContent();
        $this->assertCount(1, $seen);
    }

    public function testDefaultPortsAreComparedByScheme(): void
    {
        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->mock($seen), $this->config('http://localhost/'));

        $client->request('GET', 'http://localhost:80/x')->getContent();
        $client->request('GET', 'HTTP://LOCALHOST/y')->getContent();
        $this->assertCount(2, $seen);

        $this->expectException(TransportException::class);
        $client->request('GET', 'https://localhost/');
    }

    public function testTheAllowListIsReadAtEachRequest(): void
    {
        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->mock($seen), $this->config());

        try {
            $client->request('GET', 'http://sink.test:9000/');
            $this->fail('not allowed yet');
        } catch (TransportException) {
        }

        $_SERVER['IDNO_TEST_HTTP_ALLOW'] = ' Sink.Test:9000 , other.test:443';
        $client->request('GET', 'http://sink.test:9000/')->getContent();
        $client->request('GET', 'https://other.test/')->getContent();
        $this->assertCount(2, $seen);

        unset($_SERVER['IDNO_TEST_HTTP_ALLOW']);
        $this->expectException(TransportException::class);
        $client->request('GET', 'http://sink.test:9000/');
    }

    public function testASiteWithNoUrlAllowsNothingButTheAllowList(): void
    {
        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->mock($seen), new BootConfig([], true));

        $this->expectException(TransportException::class);
        $client->request('GET', 'http://127.0.0.1:8089/');
    }

    public function testRequestHeadersAreKept(): void
    {
        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->mock($seen), $this->config());

        $client->request('GET', 'http://127.0.0.1:8089/', ['headers' => ['Accept' => 'text/html', 'X-Other: 1']])->getContent();

        $this->assertStringContainsString('Accept: text/html', $seen[0]);
        $this->assertStringContainsString('X-Other: 1', $seen[0]);
        $this->assertStringContainsString('X-Idno-Test-Client: site', $seen[0]);
    }

    public function testTheRemoteWrappersOwnClientServesOnlyTheSitesOrigin(): void
    {
        $remoteSeen = [];
        $siteSeen = [];
        $client = LocalOnlyHttpClient::forRemote($this->mock($remoteSeen, 'remote'), $this->mock($siteSeen, 'site'), $this->config());

        $this->assertSame('site', $client->request('GET', 'http://127.0.0.1:8089/a')->getContent());
        $this->assertStringContainsString('X-Idno-Test-Client: remote', $siteSeen[0]);

        // An origin only the allow list names still goes through the remote client (and its refusals).
        $_SERVER['IDNO_TEST_HTTP_ALLOW'] = 'sink.test:9000';
        $this->assertSame('remote', $client->request('GET', 'http://sink.test:9000/')->getContent());
        $this->assertCount(1, $remoteSeen);
    }

    public function testStreamingFollowsTheClientThatMadeTheResponse(): void
    {
        $remoteSeen = [];
        $siteSeen = [];
        $client = LocalOnlyHttpClient::forRemote($this->mock($remoteSeen, 'remote'), $this->mock($siteSeen, 'site'), $this->config());

        $response = $client->request('GET', 'http://127.0.0.1:8089/a');
        $body = '';
        foreach ($client->stream($response) as $chunk) {
            $body .= $chunk->getContent();
        }

        $this->assertSame('site', $body);
    }

    public function testWithOptionsKeepsTheRestrictions(): void
    {
        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->mock($seen), $this->config())->withOptions(['timeout' => 1.0]);

        $this->assertInstanceOf(LocalOnlyHttpClient::class, $client);
        $this->expectException(TransportException::class);
        $client->request('GET', 'https://example.com/');
    }

    /**
     * @param list<array{int, string}> $script status and Location (empty: none) of each answer in turn; the last repeats
     * @param list<string> $seen
     */
    private function redirecting(array $script, array &$seen): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use ($script, &$seen): MockResponse {
            $seen[] = $method . ' ' . $url . (isset($options['body']) && $options['body'] !== '' ? ' +body' : '');
            [$status, $location] = $script[min(count($seen), count($script)) - 1];

            return new MockResponse($status === 200 ? 'final' : '', ['http_code' => $status, 'response_headers' => $location === '' ? [] : ['Location: ' . $location]]);
        });
    }

    public function testARedirectFromTheSiteToAnotherHostIsRefusedBeforeASecondRequest(): void
    {
        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->redirecting([[302, 'https://example.com/'], [200, '']], $seen), $this->config());

        try {
            $client->request('GET', 'http://127.0.0.1:8089/a');
            $this->fail('the redirect left the machine');
        } catch (TransportException $e) {
            $this->assertSame('No network in tests: example.com', $e->getMessage());
        }
        $this->assertCount(1, $seen);
    }

    public function testTheSameThroughTheRemoteWrapperAndForAnAllowListedOrigin(): void
    {
        $seen = [];
        $site = $this->redirecting([[301, 'https://example.com/x'], [200, '']], $seen);
        $client = LocalOnlyHttpClient::forRemote($site, $site, $this->config());

        $_SERVER['IDNO_TEST_HTTP_ALLOW'] = 'sink.test:9000';
        foreach (['http://127.0.0.1:8089/a', 'http://sink.test:9000/a'] as $url) {
            $seen = [];
            try {
                $client->request('GET', $url);
                $this->fail($url . ' redirected out');
            } catch (TransportException $e) {
                $this->assertSame('No network in tests: example.com', $e->getMessage());
            }
            $this->assertCount(1, $seen);
        }
    }

    public function testARedirectWithinTheSiteIsFollowedAndTheCallerGetsTheFinalResponse(): void
    {
        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->redirecting([[302, '/b?x=1'], [301, 'c'], [200, '']], $seen), $this->config());

        $response = $client->request('GET', 'http://127.0.0.1:8089/dir/a');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('final', $response->getContent());
        $this->assertSame(['GET http://127.0.0.1:8089/dir/a', 'GET http://127.0.0.1:8089/b?x=1', 'GET http://127.0.0.1:8089/c'], $seen);
        $this->assertSame('http://127.0.0.1:8089/c', $response->getInfo('url'));
    }

    public function testOnTheExemptPathARedirectToAnotherLoopbackPortIsRefused(): void
    {
        $seen = [];
        $remoteSeen = [];
        $site = $this->redirecting([[302, 'http://127.0.0.1:1/'], [200, '']], $seen);
        $client = LocalOnlyHttpClient::forRemote($this->mock($remoteSeen), $site, $this->config());

        $this->expectExceptionObject(new TransportException('No network in tests: 127.0.0.1'));
        try {
            $client->request('GET', 'http://127.0.0.1:8089/a');
        } finally {
            $this->assertCount(1, $seen);
        }
    }

    public function testEachHopChoosesItsInnerClient(): void
    {
        $remoteSeen = [];
        $siteSeen = [];
        $_SERVER['IDNO_TEST_HTTP_ALLOW'] = 'sink.test:9000';
        $client = LocalOnlyHttpClient::forRemote(
            $this->redirecting([[200, '']], $remoteSeen),
            $this->redirecting([[302, 'http://sink.test:9000/z'], [200, '']], $siteSeen),
            $this->config()
        );

        $response = $client->request('GET', 'http://127.0.0.1:8089/a');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['GET http://127.0.0.1:8089/a'], $siteSeen);
        $this->assertSame(['GET http://sink.test:9000/z'], $remoteSeen);
    }

    public function testMaxRedirectsZeroReturnsTheRedirectItself(): void
    {
        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->redirecting([[302, '/b'], [200, '']], $seen), $this->config());

        $response = $client->request('GET', 'http://127.0.0.1:8089/a', ['max_redirects' => 0]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $seen);
    }

    public function testMoreHopsThanAllowedEndsWithTheLastRedirectResponse(): void
    {
        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->redirecting([[302, '/b']], $seen), $this->config());

        $response = $client->request('GET', 'http://127.0.0.1:8089/a', ['max_redirects' => 2]);

        $this->assertSame(302, $response->getStatusCode(), 'the last 3xx is returned, as curl does at its limit');
        $this->assertCount(3, $seen, 'the first request and two redirects');

        $seen = [];
        $default = LocalOnlyHttpClient::forSite($this->redirecting([[302, '/b']], $seen), $this->config());
        $default->request('GET', 'http://127.0.0.1:8089/a');
        $this->assertCount(6, $seen, 'the default is HttpClientFactory::MAX_REDIRECTS');

        $seen = [];
        $default->withOptions(['max_redirects' => 1])->request('GET', 'http://127.0.0.1:8089/a');
        $this->assertCount(2, $seen, 'withOptions changes the default');
    }

    public function testMethodAndBodyFollowTheRulesOfAClient(): void
    {
        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->redirecting([[302, '/b'], [307, '/c'], [200, '']], $seen), $this->config());
        $client->request('POST', 'http://127.0.0.1:8089/a', ['body' => 'data']);
        $this->assertSame(['POST http://127.0.0.1:8089/a +body', 'GET http://127.0.0.1:8089/b', 'GET http://127.0.0.1:8089/c'], $seen);

        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->redirecting([[307, '/b'], [308, '/c'], [200, '']], $seen), $this->config());
        $client->request('POST', 'http://127.0.0.1:8089/a', ['body' => 'data']);
        $this->assertSame(['POST http://127.0.0.1:8089/a +body', 'POST http://127.0.0.1:8089/b +body', 'POST http://127.0.0.1:8089/c +body'], $seen);

        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->redirecting([[303, '/b'], [200, '']], $seen), $this->config());
        $client->request('PUT', 'http://127.0.0.1:8089/a', ['body' => 'data']);
        $this->assertSame(['PUT http://127.0.0.1:8089/a +body', 'GET http://127.0.0.1:8089/b'], $seen);
    }

    public function testStreamingTheFinalResponseOfAFollowedRedirectWorks(): void
    {
        $seen = [];
        $client = LocalOnlyHttpClient::forSite($this->redirecting([[302, '/b'], [200, '']], $seen), $this->config());

        $response = $client->request('GET', 'http://127.0.0.1:8089/a');
        $body = '';
        foreach ($client->stream($response) as $chunk) {
            $body .= $chunk->getContent();
        }

        $this->assertSame('final', $body);
    }
}
