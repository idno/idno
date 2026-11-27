<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Idno\Http\TrustedProxies;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        TrustedProxies::apply([]);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);
    }

    /**
     * @param array<string, string> $server
     */
    private static function request(array $server): Request
    {
        return new Request([], [], [], [], [], $server + ['REMOTE_ADDR' => '203.0.113.9']);
    }

    /**
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function todaysRule(): iterable
    {
        yield 'HTTPS=1' => [['HTTPS' => '1'], true];
        yield 'HTTPS=on in any case' => [['HTTPS' => 'ON'], true];
        yield 'port 443' => [['SERVER_PORT' => '443'], true];
        yield 'X-Forwarded-Proto https from anyone, in any case' => [['HTTP_X_FORWARDED_PROTO' => 'HTTPS'], true];
        // the old rule read the whole header: a comma list is not the word https
        yield 'a proxy chain written https, http' => [['HTTP_X_FORWARDED_PROTO' => 'https, http'], false];
        yield 'HTTPS=off beside a forwarded https is still https' => [['HTTPS' => 'off', 'HTTP_X_FORWARDED_PROTO' => 'https'], true];
        yield 'http' => [['SERVER_PORT' => '80', 'HTTP_X_FORWARDED_PROTO' => 'http'], false];
        yield 'HTTPS=off with port 443 is not asked about the port' => [['HTTPS' => 'off', 'SERVER_PORT' => '443'], false];
    }

    /**
     * @dataProvider todaysRule
     *
     * @param array<string, string> $server
     */
    public function testWithoutTheSettingTodaysRuleStands(array $server, bool $secure): void
    {
        // A site behind a proxy that sets nothing keeps believing the forwarded header, as it always did.
        $this->assertFalse(TrustedProxies::configured());
        $this->assertSame($secure, TrustedProxies::isSecure(self::request($server)));
    }

    public function testWithTheSettingAStrangersForwardedProtoIsIgnored(): void
    {
        // Catches: a visitor claiming https for their own request (a Secure cookie and an API key over plain http).
        TrustedProxies::apply(['trusted_proxies' => ['192.0.2.1']]);

        $this->assertTrue(TrustedProxies::configured());
        $this->assertFalse(TrustedProxies::isSecure(self::request(['HTTP_X_FORWARDED_PROTO' => 'https'])));
        $this->assertTrue(TrustedProxies::isSecure(self::request(['HTTPS' => 'on'])), 'the server\'s own word still counts');
    }

    public function testWithTheSettingATrustedProxysForwardedProtoIsBelieved(): void
    {
        // Catches: a site behind its own proxy losing its https detection once it names the proxy.
        TrustedProxies::apply(['trusted_proxies' => ['192.0.2.0/24']]);

        $this->assertTrue(TrustedProxies::isSecure(self::request(['REMOTE_ADDR' => '192.0.2.77', 'HTTP_X_FORWARDED_PROTO' => 'https'])));
        $this->assertFalse(TrustedProxies::isSecure(self::request(['REMOTE_ADDR' => '192.0.2.77', 'HTTP_X_FORWARDED_PROTO' => 'http'])));
    }

    public function testRemoteAddrAndPrivateSubnetsAreUnderstood(): void
    {
        // Catches: PRIVATE_SUBNETS expanded to ranges that include documentation and IPv4-mapped addresses, which a
        // remote client can present as its own (203.0.113.9 must not be a trusted proxy).
        $before = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.4';
        try {
            TrustedProxies::apply(['trusted_proxies' => 'REMOTE_ADDR']);
            $this->assertTrue(TrustedProxies::isSecure(self::request(['REMOTE_ADDR' => '198.51.100.4', 'HTTP_X_FORWARDED_PROTO' => 'https'])));
        } finally {
            if ($before === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $before;
            }
        }

        TrustedProxies::apply(['trusted_proxies' => ['PRIVATE_SUBNETS']]);
        $this->assertTrue(TrustedProxies::isSecure(self::request(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_PROTO' => 'https'])));
        $this->assertFalse(TrustedProxies::isSecure(self::request(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https'])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badEntries(): iterable
    {
        yield 'IPv4 prefix 0 is every address' => ['10.0.0.0/0'];
        yield 'IPv6 prefix 0 is every address' => ['::/0'];
        yield 'a host name' => ['example.com'];
    }

    /**
     * @dataProvider badEntries
     */
    public function testABadEntryIsRefusedNamingTheSetting(string $entry): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The setting trusted_proxies has an entry that is not an IP address or range: ' . $entry);

        TrustedProxies::apply(['trusted_proxies' => ['192.0.2.1', $entry]]);
    }

    public function testABadEntryIsShownWithoutControlCharactersAndCut(): void
    {
        try {
            TrustedProxies::fromConfig(['trusted_proxies' => ["a\nb" . str_repeat('x', 200)]]);
            $this->fail('a bad entry must be refused');
        } catch (\LogicException $e) {
            $this->assertStringNotContainsString("\n", $e->getMessage());
            $this->assertLessThan(200, strlen($e->getMessage()));
        }
    }

    public function testACommaSeparatedStringIsAList(): void
    {
        // IDNO_TRUSTED_PROXIES is one string: entries trimmed, empty ones dropped.
        $this->assertSame(['10.0.0.1', '192.0.2.0/24', 'REMOTE_ADDR'], TrustedProxies::fromConfig(['trusted_proxies' => ' 10.0.0.1, ,192.0.2.0/24,REMOTE_ADDR,']));
        $this->assertSame([], TrustedProxies::fromConfig([]));
        $this->assertSame([], TrustedProxies::fromConfig(['trusted_proxies' => '']));
    }

    public function testForwardedHostPrefixAndRfc7239AreNeverBelievedFromATrustedProxy(): void
    {
        // Catches: widening the trusted header set, which would let a client behind the proxy choose the scheme,
        // host or port the site believes (the configured url decides the site's address).
        TrustedProxies::apply(['trusted_proxies' => ['192.0.2.0/24']]);
        $request = self::request([
            'REMOTE_ADDR' => '192.0.2.77',
            'HTTP_HOST' => 'site.example',
            'SERVER_PORT' => '80',
            'HTTP_FORWARDED' => 'proto=https;host=evil.example:8443',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
            'HTTP_X_FORWARDED_PREFIX' => '/evil',
        ]);

        $this->assertFalse(TrustedProxies::isSecure($request));
        $this->assertSame('http', $request->getScheme());
        $this->assertSame('site.example', $request->getHost());
        $this->assertSame(80, $request->getPort());
        $this->assertSame('', $request->getBaseUrl());
    }

    public function testAForwardedPortIsBelievedFromAListedProxyAndNotFromAnUnlistedPeer(): void
    {
        // Catches: an unlisted peer's forwarded port being believed (the answer Config::detectBaseURL() asks before it builds the site's address with a port).
        $forwarded = ['HTTP_X_FORWARDED_PORT' => '8443'];
        $this->assertTrue(TrustedProxies::believesForwardedPort(self::request($forwarded)), 'with no setting, as before: anyone');

        TrustedProxies::apply(['trusted_proxies' => ['192.0.2.1']]);
        $this->assertFalse(TrustedProxies::believesForwardedPort(self::request($forwarded)));
        $this->assertTrue(TrustedProxies::believesForwardedPort(self::request($forwarded + ['REMOTE_ADDR' => '192.0.2.1'])));
    }

    public function testApplyingNothingClearsWhatAnEarlierKernelSet(): void
    {
        // A second kernel in one process starts clean.
        TrustedProxies::apply(['trusted_proxies' => ['192.0.2.1']]);
        TrustedProxies::apply([]);

        $this->assertFalse(TrustedProxies::configured());
    }
}
