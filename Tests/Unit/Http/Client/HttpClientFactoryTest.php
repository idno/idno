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
        $this->assertSame('identity', $options['headers']['Accept-Encoding'], 'no compression: a body that is small on the wire is not decoded in one piece');
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

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $info what the mock reports about the connection
     * @param array<string, mixed> $options the request's options
     * @param array<string, mixed> $environment the proxy environment variables the client is built with (never the real ones)
     *
     * @return array{string, int} what the request did: 'sent' or 'refused: <message>', and how many requests the mock got
     */
    private static function attempt(array $config, string $url, array $info = [], array $options = [], array $environment = []): array
    {
        $made = 0;
        $mock = new MockHttpClient(static function () use (&$made, $info): MockResponse {
            $made++;

            return new MockResponse('ok', $info);
        });
        try {
            HttpClientFactory::createRemote($mock, $config, static fn (string $host): array => [], $environment)->request('GET', $url, $options)->getContent();

            return ['sent', $made];
        } catch (TransportException $e) {
            return ['refused: ' . $e->getMessage(), $made];
        }
    }

    public function testBehindAProxyTheProxysOwnAddressIsAllowedAndPublicTargetsAreFetched(): void
    {
        $config = ['proxy_string' => '10.0.0.5:3128'];

        $this->assertSame(['sent', 1], self::attempt($config, 'https://93.184.216.34/', ['primary_ip' => '10.0.0.5']));
    }

    public function testBehindAProxyAPrivateTargetIsStillRefusedBeforeARequestIsMade(): void
    {
        $config = ['proxy_string' => '10.0.0.5:3128'];

        foreach (['http://127.0.0.1/', 'http://10.0.0.9/', 'http://169.254.169.254/', 'http://[::1]/'] as $url) {
            [$result, $made] = self::attempt($config, $url, ['primary_ip' => '10.0.0.5']);
            $this->assertStringStartsWith('refused: ', $result, $url);
            $this->assertSame(0, $made, $url);
        }
    }

    public function testBehindAProxyOnLoopbackATargetAtTheProxysOwnAddressIsStillRefused(): void
    {
        $config = ['proxy_string' => '127.0.0.1:3128'];

        $this->assertSame(['sent', 1], self::attempt($config, 'https://93.184.216.34/', ['primary_ip' => '127.0.0.1']));
        foreach (['http://127.0.0.1/', 'http://localhost.test/'] as $url) {
            [$result, $made] = self::attempt($config, $url, ['primary_ip' => '127.0.0.1'], ['resolve' => ['localhost.test' => '127.0.0.1']]);
            $this->assertStringStartsWith('refused: ', $result, $url);
            $this->assertSame(0, $made, $url);
        }
        $this->assertSame(0, self::attempt($config, 'http://10.0.0.9/', ['primary_ip' => '127.0.0.1'])[1]);
    }

    public function testBehindAnIpv6ProxyItsAddressIsAllowedInAnyWrittenForm(): void
    {
        $config = ['proxy_string' => '[::1]:3128'];

        $this->assertSame(['sent', 1], self::attempt($config, 'https://93.184.216.34/', ['primary_ip' => '0:0:0:0:0:0:0:1']));
        $this->assertSame(0, self::attempt($config, 'http://[::1]/', ['primary_ip' => '::1'])[1]);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function environmentsWithAProxyAt10005(): iterable
    {
        yield 'https_proxy' => [['https_proxy' => 'http://10.0.0.5:3128']];
        yield 'HTTPS_PROXY' => [['HTTPS_PROXY' => 'http://10.0.0.5:3128']];
        yield 'http_proxy' => [['http_proxy' => 'http://10.0.0.5:3128']];
        yield 'all_proxy' => [['all_proxy' => 'http://10.0.0.5:3128']];
        yield 'ALL_PROXY' => [['ALL_PROXY' => 'http://10.0.0.5:3128']];
        yield 'HTTP_PROXY on the command line' => [['HTTP_PROXY' => 'http://10.0.0.5:3128']];
        yield 'no scheme' => [['https_proxy' => '10.0.0.5:3128']];
        yield 'credentials' => [['https_proxy' => 'http://user:p%40ss@10.0.0.5:3128']];
        yield 'a socks scheme' => [['all_proxy' => 'socks5h://10.0.0.5:1080']];
    }

    /**
     * @dataProvider environmentsWithAProxyAt10005
     *
     * @param array<string, string> $environment
     */
    public function testAProxyFromTheEnvironmentIsAllowedAndTargetsAtItAreRefused(array $environment): void
    {
        $this->assertSame(['sent', 1], self::attempt([], 'https://93.184.216.34/', ['primary_ip' => '10.0.0.5'], [], $environment));
        $this->assertSame(0, self::attempt([], 'http://10.0.0.5:3128/', ['primary_ip' => '10.0.0.5'], [], $environment)[1]);
        $this->assertSame(0, self::attempt([], 'http://127.0.0.1/', ['primary_ip' => '10.0.0.5'], [], $environment)[1]);
    }

    public function testAProxyFromTheEnvironmentMasksOnlyItsOwnAddress(): void
    {
        $this->assertStringContainsString('IP "10.0.0.6" is blocked', self::attempt([], 'https://93.184.216.34/', ['primary_ip' => '10.0.0.6'], [], ['https_proxy' => 'http://10.0.0.5:3128'])[0]);
    }

    public function testBothTheHttpAndTheHttpsProxyAreMasked(): void
    {
        $environment = ['http_proxy' => 'http://10.0.0.5:3128', 'https_proxy' => 'http://10.0.0.6:3128'];

        $this->assertSame(['sent', 1], self::attempt([], 'http://93.184.216.34/', ['primary_ip' => '10.0.0.5'], [], $environment));
        $this->assertSame(['sent', 1], self::attempt([], 'https://93.184.216.34/', ['primary_ip' => '10.0.0.6'], [], $environment));
    }

    public function testTheEnvironmentIsReadAsTheVendorClientReadsIt(): void
    {
        $address = static fn (array $environment, bool $cli): array => HttpClientFactory::proxyAddressesFromEnvironment($environment, $cli, static fn (string $host): array => []);

        // The http proxy: http_proxy, then HTTP_PROXY (the command line only), then all_proxy, then ALL_PROXY.
        $this->assertSame(['10.0.0.1'], $address(['http_proxy' => 'http://10.0.0.1:1', 'HTTP_PROXY' => 'http://10.0.0.2:1', 'all_proxy' => 'http://10.0.0.3:1'], true));
        $this->assertSame(['10.0.0.2'], $address(['HTTP_PROXY' => 'http://10.0.0.2:1', 'all_proxy' => 'http://10.0.0.3:1'], true));
        $this->assertSame(['10.0.0.3'], $address(['HTTP_PROXY' => 'http://10.0.0.2:1', 'all_proxy' => 'http://10.0.0.3:1'], false), 'HTTP_PROXY is ignored outside the command line');
        $this->assertSame(['10.0.0.4'], $address(['ALL_PROXY' => 'http://10.0.0.4:1'], false));
        // The https proxy: https_proxy, then HTTPS_PROXY, then the http one; and both are masked.
        $this->assertEqualsCanonicalizing(['10.0.0.1', '10.0.0.7'], $address(['http_proxy' => 'http://10.0.0.1:1', 'https_proxy' => 'http://10.0.0.7:1', 'HTTPS_PROXY' => 'http://10.0.0.8:1'], true));
        $this->assertEqualsCanonicalizing(['10.0.0.1', '10.0.0.8'], $address(['http_proxy' => 'http://10.0.0.1:1', 'HTTPS_PROXY' => 'http://10.0.0.8:1'], true));
        // A variable that is set but empty is the proxy, as the vendor code reads it: it hides the later ones and adds nothing.
        $this->assertSame([], $address(['http_proxy' => '', 'all_proxy' => 'http://10.0.0.3:1'], true));
    }

    public function testAnEmptyOrMalformedProxyVariableAddsNothing(): void
    {
        $address = static fn (array $environment): array => HttpClientFactory::proxyAddressesFromEnvironment($environment, true, static fn (string $host): array => []);

        $this->assertSame([], $address([]));
        $this->assertSame([], $address(['https_proxy' => '', 'http_proxy' => '']));
        $this->assertSame([], $address(['https_proxy' => 'http://', 'http_proxy' => ':::']));
        $this->assertSame([], $address(['https_proxy' => 'http://unresolvable.test:3128']));
        $this->assertSame([], $address(['https_proxy' => ['not', 'a', 'string'], 'http_proxy' => 5]));
        $this->assertStringContainsString('IP "10.0.0.5" is blocked', self::attempt([], 'https://93.184.216.34/', ['primary_ip' => '10.0.0.5'], [], ['https_proxy' => 'http://'])[0]);
    }

    public function testAProxyHostNameInTheEnvironmentIsResolvedWithTheGivenResolver(): void
    {
        $addresses = HttpClientFactory::proxyAddressesFromEnvironment(['https_proxy' => 'http://proxy.example:3128'], true, static fn (string $host): array => $host === 'proxy.example' ? ['10.1.2.3', '10.1.2.4'] : []);

        $this->assertEqualsCanonicalizing(['10.1.2.3', '10.1.2.4'], $addresses);
    }

    public function testTheProxyStringWinsAndTheEnvironmentIsNotConsulted(): void
    {
        $environment = ['https_proxy' => 'http://10.0.0.6:3128'];

        $this->assertSame(['sent', 1], self::attempt(['proxy_string' => '10.0.0.5:3128'], 'https://93.184.216.34/', ['primary_ip' => '10.0.0.5'], [], $environment));
        $this->assertStringContainsString('IP "10.0.0.6" is blocked', self::attempt(['proxy_string' => '10.0.0.5:3128'], 'https://93.184.216.34/', ['primary_ip' => '10.0.0.6'], [], $environment)[0]);
    }

    public function testNoProxyNeedsNoHandling(): void
    {
        // A host in no_proxy connects directly and reports its own address: the check applies as for any request.
        $environment = ['https_proxy' => 'http://10.0.0.5:3128', 'no_proxy' => 'intranet.test'];

        $this->assertStringContainsString('IP "10.0.0.9" is blocked', self::attempt([], 'https://93.184.216.34/', ['primary_ip' => '10.0.0.9'], [], $environment)[0]);
    }

    public function testBehindAProxyAHostTheServerCannotResolveIsRefused(): void
    {
        // The `resolve` option is read by the private-network client before it looks the name up, so
        // naming the host as its own "address" gives a host that resolves to nothing, with no DNS query.
        [$result, $made] = self::attempt(['proxy_string' => '10.0.0.5:3128'], 'http://nowhere.test/', ['primary_ip' => '10.0.0.5'], ['resolve' => ['nowhere.test' => 'nowhere.test']]);

        $this->assertStringStartsWith('refused: Host "nowhere.test" is blocked', $result);
        $this->assertSame(0, $made);
    }

    public function testBehindAProxyAHostThatResolvesToAPrivateAddressIsRefused(): void
    {
        [$result, $made] = self::attempt(['proxy_string' => '10.0.0.5:3128'], 'http://internal.test/', ['primary_ip' => '10.0.0.5'], ['resolve' => ['internal.test' => '10.0.0.9']]);

        $this->assertStringStartsWith('refused: ', $result);
        $this->assertSame(0, $made);
    }

    public function testWithoutAProxyAConnectionToAPrivateAddressIsRefused(): void
    {
        [$result, $made] = self::attempt([], 'https://93.184.216.34/', ['primary_ip' => '10.0.0.5']);

        $this->assertStringContainsString('IP "10.0.0.5" is blocked', $result);
    }

    public function testAProxyHostNameIsResolvedWhenTheClientIsBuiltAndItsAddressesAreAllowed(): void
    {
        $made = 0;
        $mock = new MockHttpClient(static function () use (&$made): MockResponse {
            $made++;

            return new MockResponse('ok', ['primary_ip' => '10.1.2.3']);
        });
        $remote = HttpClientFactory::createRemote($mock, ['proxy_string' => 'user:pw@proxy.example:3128'], static fn (string $host): array => $host === 'proxy.example' ? ['10.1.2.3'] : []);

        $this->assertSame('ok', $remote->request('GET', 'https://93.184.216.34/')->getContent());
    }

    public function testAProxyHostNameThatDoesNotResolveAddsNothing(): void
    {
        $mock = new MockHttpClient(new MockResponse('ok', ['primary_ip' => '10.1.2.3']));
        $remote = HttpClientFactory::createRemote($mock, ['proxy_string' => 'proxy.example:3128'], static fn (string $host): array => []);

        $this->expectException(TransportException::class);
        $remote->request('GET', 'https://93.184.216.34/')->getContent();
    }

    public function testAllowedRangesLetPrivateAddressesThroughAndNothingElse(): void
    {
        $config = ['http_allow_private' => ['192.168.1.0/24']];

        $this->assertSame(['sent', 1], self::attempt($config, 'http://192.168.1.20/'));
        $this->assertSame(['sent', 1], self::attempt($config, 'http://intranet.test/', [], ['resolve' => ['intranet.test' => '192.168.1.77']]));
        foreach (['http://192.168.2.20/', 'http://127.0.0.1/', 'http://10.0.0.9/'] as $url) {
            $this->assertSame(0, self::attempt($config, $url)[1], $url);
            $this->assertStringStartsWith('refused: ', self::attempt($config, $url)[0], $url);
        }
    }

    public function testAnAllowedSingleAddressAndAnIpv6RangeAreHonoured(): void
    {
        $this->assertSame(['sent', 1], self::attempt(['http_allow_private' => ['10.0.0.9']], 'http://10.0.0.9/'));
        $this->assertSame(0, self::attempt(['http_allow_private' => ['10.0.0.9']], 'http://10.0.0.10/')[1]);

        $config = ['http_allow_private' => ['fd00::/8']];
        $this->assertSame(['sent', 1], self::attempt($config, 'http://[fd12:3456::1]/'));
        $this->assertSame(0, self::attempt($config, 'http://[::1]/')[1]);
        $this->assertSame(0, self::attempt($config, 'http://127.0.0.1/')[1]);
    }

    public function testTheSettingAsOneCommaSeparatedStringIsReadTheSame(): void
    {
        $config = ['http_allow_private' => ' 192.168.1.0/24 , fd00::/8,, 10.0.0.9 '];

        $this->assertSame(['sent', 1], self::attempt($config, 'http://192.168.1.20/'));
        $this->assertSame(['sent', 1], self::attempt($config, 'http://[fd12::1]/'));
        $this->assertSame(['sent', 1], self::attempt($config, 'http://10.0.0.9/'));
        $this->assertSame(0, self::attempt($config, 'http://192.168.2.20/')[1]);
    }

    public function testAStarReturnsTheSiteClientItselfAndRefusesNothing(): void
    {
        $mock = new MockHttpClient(new MockResponse('ok'));

        $this->assertSame($mock, HttpClientFactory::createRemote($mock, ['http_allow_private' => ['*']]));
        $this->assertSame($mock, HttpClientFactory::createRemote($mock, ['http_allow_private' => '*']));
        $this->assertSame($mock, HttpClientFactory::createRemote($mock, ['http_allow_private' => '192.168.1.0/24, *']));
        $this->assertSame('ok', HttpClientFactory::createRemote($mock, ['http_allow_private' => '*'])->request('GET', 'http://127.0.0.1/')->getContent());
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidEntries(): iterable
    {
        yield 'a word' => [['everything'], 'everything'];
        yield 'a host name' => [['intranet.example'], 'intranet.example'];
        yield 'a prefix too long' => [['10.0.0.0/33'], '10.0.0.0/33'];
        yield 'an IPv6 prefix too long' => [['fd00::/129'], 'fd00::/129'];
        yield 'a prefix that is no number' => [['10.0.0.0/x'], '10.0.0.0/x'];
        yield 'a bad address' => [['10.0.0/8'], '10.0.0/8'];
        yield 'one bad entry among good ones' => ['192.168.1.0/24, nope', 'nope'];
        yield 'a zero prefix' => [['0.0.0.0/0'], '0.0.0.0/0'];
        yield 'an IPv6 zero prefix' => [['::/0'], '::/0'];
        yield 'a zero prefix with leading zeros' => [['10.0.0.0/00'], '10.0.0.0/00'];
        yield 'a number' => [[5], '5'];
        yield 'neither a list nor a string' => [5, 'int'];
    }

    /**
     * @dataProvider invalidEntries
     */
    public function testAnInvalidEntryIsRefusedByName(mixed $value, string $entry): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('http_allow_private');
        $this->expectExceptionMessage($entry);
        HttpClientFactory::createRemote(new MockHttpClient(new MockResponse('')), ['http_allow_private' => $value]);
    }

    public function testAZeroPrefixIsRefusedAndTheMessageNamesTheStar(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('use "*"');
        HttpClientFactory::createRemote(new MockHttpClient(new MockResponse('')), ['http_allow_private' => ['0.0.0.0/0']]);
    }

    public function testNothingConfiguredAllowsNothing(): void
    {
        foreach ([[], ['http_allow_private' => []], ['http_allow_private' => ''], ['http_allow_private' => null]] as $config) {
            $this->assertSame(0, self::attempt($config, 'http://127.0.0.1/')[1]);
        }
    }

    public function testTwoCallsBuildTwoSeparateDecorators(): void
    {
        $mock = new MockHttpClient(new MockResponse('ok'));

        $this->assertNotSame(HttpClientFactory::createRemote($mock), HttpClientFactory::createRemote($mock));
    }

    public function testTheRemoteClientIsBuiltFromTheBootConfiguration(): void
    {
        $mock = new MockHttpClient(new MockResponse('ok'));
        $config = new \Idno\Config\BootConfig(['http_allow_private' => '*'], false);

        $this->assertSame($mock, HttpClientFactory::remoteFromBootConfig($mock, $config));
        $this->assertInstanceOf(\Symfony\Component\HttpClient\NoPrivateNetworkHttpClient::class, HttpClientFactory::remoteFromBootConfig($mock, new \Idno\Config\BootConfig([], false)));
    }

    public function testOptionsStayPure(): void
    {
        $this->assertSame(
            HttpClientFactory::options(['proxy_string' => '10.0.0.5:3128']),
            HttpClientFactory::options(['proxy_string' => '10.0.0.5:3128', 'http_allow_private' => ['*', 'junk']])
        );
    }
}
