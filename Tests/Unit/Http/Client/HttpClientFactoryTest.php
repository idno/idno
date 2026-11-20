<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Client;

use Idno\Http\Client\HttpClientFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpClientFactoryTest extends TestCase
{
    public function testTheDefaultsAreBounded(): void
    {
        $options = HttpClientFactory::options([]);

        $this->assertSame(10.0, $options['timeout']);
        $this->assertSame(60.0, $options['max_duration']);
        $this->assertSame(5, $options['max_redirects']);
        $this->assertTrue($options['verify_peer']);
        $this->assertTrue($options['verify_host']);
        $this->assertSame('Idno https://idno.co', $options['headers']['User-Agent']);
        $this->assertArrayNotHasKey('proxy', $options);
    }

    public function testDisableSslVerifyTurnsBothChecksOff(): void
    {
        $options = HttpClientFactory::options(['disable_ssl_verify' => '1']);

        $this->assertFalse($options['verify_peer']);
        $this->assertFalse($options['verify_host']);

        foreach ([null, '', '0', 0, false] as $empty) {
            $options = HttpClientFactory::options(['disable_ssl_verify' => $empty]);
            $this->assertTrue($options['verify_peer'], json_encode($empty));
            $this->assertTrue($options['verify_host'], json_encode($empty));
        }
    }

    public function testANonEmptyStringSuchAsFalseTurnsVerificationOff(): void
    {
        $options = HttpClientFactory::options(['disable_ssl_verify' => 'false']);

        $this->assertFalse($options['verify_peer']);
        $this->assertFalse($options['verify_host']);
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function proxies(): iterable
    {
        yield 'no type' => ['proxy.example:8080', null, 'http://proxy.example:8080'];
        yield 'empty type' => ['proxy.example:8080', '', 'http://proxy.example:8080'];
        yield 'http' => ['proxy.example:8080', 'http', 'http://proxy.example:8080'];
        yield 'unknown type' => ['proxy.example:8080', 'ftp', 'http://proxy.example:8080'];
        yield 'socks5' => ['proxy.example:1080', 'socks5', 'socks5://proxy.example:1080'];
        yield 'CURLPROXY_SOCKS5' => ['proxy.example:1080', 'CURLPROXY_SOCKS5', 'socks5://proxy.example:1080'];
        yield 'socks5-hostname' => ['proxy.example:1080', 'socks5-hostname', 'socks5h://proxy.example:1080'];
        yield 'CURLPROXY_SOCKS5_HOSTNAME' => ['proxy.example:1080', 'CURLPROXY_SOCKS5_HOSTNAME', 'socks5h://proxy.example:1080'];
        yield 'socks4' => ['proxy.example:1080', 'socks4', 'socks4://proxy.example:1080'];
        yield 'CURLPROXY_SOCKS4' => ['proxy.example:1080', 'CURLPROXY_SOCKS4', 'socks4://proxy.example:1080'];
        yield 'a scheme is kept' => ['socks5://p:1080', 'http', 'socks5://p:1080'];
        yield 'an http scheme is kept' => ['http://p:3128', 'socks5', 'http://p:3128'];
    }

    /**
     * @dataProvider proxies
     */
    public function testProxySettingsBecomeClientOptions(string $proxy, ?string $type, string $expected): void
    {
        $config = ['proxy_string' => $proxy];
        if ($type !== null) {
            $config['proxy_type'] = $type;
        }

        $this->assertSame($expected, HttpClientFactory::options($config)['proxy']);
    }

    public function testNoProxyStringMeansNoProxyWhateverTheType(): void
    {
        $this->assertArrayNotHasKey('proxy', HttpClientFactory::options(['proxy_type' => 'socks5']));
        $this->assertArrayNotHasKey('proxy', HttpClientFactory::options(['proxy_string' => '']));
    }

    public function testCreateAppliesTheOptions(): void
    {
        $client = HttpClientFactory::create(['disable_ssl_verify' => '1']);

        $this->assertInstanceOf(\Symfony\Contracts\HttpClient\HttpClientInterface::class, $client);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function privateAddresses(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/'];
        yield 'private' => ['http://10.0.0.5/'];
        yield 'link-local' => ['http://169.254.169.254/'];
        yield 'IPv6 loopback' => ['http://[::1]/'];
    }

    /**
     * @dataProvider privateAddresses
     */
    public function testARedirectToAPrivateAddressIsRefused(string $target): void
    {
        $requests = [];
        $mock = new MockHttpClient(static function (string $method, string $url) use (&$requests, $target): MockResponse {
            $requests[] = $url;

            return count($requests) === 1
                ? new MockResponse('', ['http_code' => 302, 'redirect_url' => $target, 'response_headers' => ['Location: ' . $target]])
                : new MockResponse('secret');
        });
        $remote = HttpClientFactory::createRemote($mock);

        $threw = false;
        try {
            $remote->request('GET', 'https://93.184.216.34/')->getContent();
        } catch (TransportException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'the redirect to ' . $target . ' must be refused');
        $this->assertSame(['https://93.184.216.34/'], $requests, 'the second request is never made');
    }

    /**
     * @dataProvider privateAddresses
     */
    public function testAPrivateAddressIsRefusedDirectly(string $url): void
    {
        $made = 0;
        $remote = HttpClientFactory::createRemote(new MockHttpClient(static function () use (&$made): MockResponse {
            $made++;

            return new MockResponse('secret');
        }));

        $this->expectException(TransportException::class);
        try {
            $remote->request('GET', $url)->getContent();
        } finally {
            $this->assertSame(0, $made);
        }
    }

    public function testAPublicAddressIsFetched(): void
    {
        $remote = HttpClientFactory::createRemote(new MockHttpClient(new MockResponse('hello')));

        $this->assertSame('hello', $remote->request('GET', 'https://93.184.216.34/')->getContent());
    }

    public function testARedirectToAPublicAddressIsFollowed(): void
    {
        $requests = [];
        $mock = new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $url;

            return count($requests) === 1
                ? new MockResponse('', ['http_code' => 301, 'redirect_url' => 'https://93.184.216.35/next', 'response_headers' => ['Location: https://93.184.216.35/next']])
                : new MockResponse('arrived');
        });

        $this->assertSame('arrived', HttpClientFactory::createRemote($mock)->request('GET', 'https://93.184.216.34/')->getContent());
        $this->assertSame(['https://93.184.216.34/', 'https://93.184.216.35/next'], $requests);
    }
}
