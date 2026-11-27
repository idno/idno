<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Core\Idno;
use Tests\Support\PrivateSiteTestCase;

/**
 * Who is believed when a request says it was made over https: a site that names no trusted proxies believes
 * `X-Forwarded-Proto` from anyone (so a site behind a proxy that has never heard of the setting keeps its
 * secure session cookie), and a site that names them believes it from those proxies alone. The site's own
 * address is https, as a site behind a TLS terminator has it; the requests arrive over plain http from 127.0.0.1.
 */
final class TrustedProxiesSiteTest extends PrivateSiteTestCase
{
    protected function configuredScheme(): string
    {
        return 'https';
    }

    /**
     * The session cookie the login page sets, asked through a server of the test with the given trusted proxies.
     *
     * @param array<string, string> $headers
     */
    private function loginPageCookie(string $trustedProxies, array $headers): string
    {
        $url = $this->startExtraServer($trustedProxies === '' ? ['IDNO_TEST_NOTHING' => '1'] : ['IDNO_TRUSTED_PROXIES' => $trustedProxies]);
        $header = (string) (new HttpSiteClient($url))->request('GET', 'session/login', $headers)->header('Set-Cookie');
        $name = (string) Idno::site()->config()->sessionname;
        $start = strpos($header, $name . '=');
        $this->assertNotFalse($start, 'a session cookie in: ' . $header);

        return substr($header, (int) $start);
    }

    public function testWithoutTheSettingAForwardedHttpsRequestIsSecureAsBefore(): void
    {
        // An upgraded site behind a proxy sets nothing: its members must keep the Secure cookie.
        $this->assertMatchesRegularExpression('/;\s*secure\b/i', $this->loginPageCookie('', ['X-Forwarded-Proto' => 'https']));
    }

    public function testWithTheSettingAForwardedHeaderFromAStrangerIsIgnored(): void
    {
        $this->assertDoesNotMatchRegularExpression('/;\s*secure\b/i', $this->loginPageCookie('192.0.2.1', ['X-Forwarded-Proto' => 'https']));
    }

    public function testWithTheSettingTheTrustedProxyIsBelieved(): void
    {
        $this->assertMatchesRegularExpression('/;\s*secure\b/i', $this->loginPageCookie('127.0.0.1', ['X-Forwarded-Proto' => 'https']));
    }

    public function testABadSettingStopsTheSiteWithAMessageNamingIt(): void
    {
        [$exit, , $stderr] = $this->consoleWith(['IDNO_TRUSTED_PROXIES' => '10.0.0.0/0'], 'list');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('trusted_proxies', $stderr);
    }
}
