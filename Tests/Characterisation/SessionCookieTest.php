<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Core\Idno;
use Tests\Support\PrivateSiteTestCase;

/**
 * The session cookie and its headers as the site sends them today: the cookie's name (from the site's
 * configuration), its flags and its lifetime, when it is marked Secure, and the P3P header that goes with it. The
 * missing SameSite attribute is pinned as it is.
 */
final class SessionCookieTest extends PrivateSiteTestCase
{
    public function testCookieNameHttpOnlyAndLifetime(): void
    {
        $response = $this->site()->request('GET', 'session/login');

        $cookie = $this->sessionCookieHeader($response);
        $name = (string) Idno::site()->config()->sessionname;
        $this->assertMatchesRegularExpression('/^' . preg_quote($name, '/') . '=[0-9a-f]{32,}(;|$)/', $cookie, 'the name is the configured `sessionname`');
        $this->assertMatchesRegularExpression('/;\s*HttpOnly\b/i', $cookie);
        $this->assertMatchesRegularExpression('/;\s*path=\/(;|$)/i', $cookie);
        $this->assertMatchesRegularExpression('/;\s*Max-Age=604800\b/i', $cookie, 'a persistent cookie of seven days');
        $this->assertMatchesRegularExpression('/;\s*expires=/i', $cookie);
        $this->assertDoesNotMatchRegularExpression('/;\s*domain=/i', $cookie, 'no domain: the cookie is for the host that set it');
    }

    public function testARequestThatCarriesALiveSessionIsNotGivenAnotherCookie(): void
    {
        $first = $this->site()->request('GET', 'session/login');
        $second = $this->site()->request('GET', 'session/login', ['Cookie' => self::sessionCookie($first)]);

        $this->assertNull(self::sessionCookieOrNull($second), 'a request that carries a live session is not given another cookie');
    }

    public function testSecureOnlyOverHttps(): void
    {
        $plain = $this->site()->request('GET', 'session/login');
        $secureUrl = $this->startExtraServer(['IDNO_TEST_SERVER_HTTPS' => '1']);
        $secure = (new HttpSiteClient($secureUrl))->request('GET', 'session/login');

        $this->assertDoesNotMatchRegularExpression('/;\s*secure\b/i', $this->sessionCookieHeader($plain), 'over http the cookie is not marked Secure');
        $this->assertMatchesRegularExpression('/;\s*secure\b/i', $this->sessionCookieHeader($secure), 'over https it is');
    }

    public function testP3PHeaderIsSent(): void
    {
        $response = $this->site()->request('GET', 'session/login');

        $this->assertSame('CP="CAO PSA OUR"', $response->header('P3P'));
    }

    public function testNoSameSiteAttribute(): void
    {
        $response = $this->site()->request('GET', 'session/login');

        // Pins today: PHP's default for session.cookie_samesite is empty and the site never sets one.
        $this->assertDoesNotMatchRegularExpression('/samesite/i', $this->sessionCookieHeader($response));
    }

    /**
     * The Set-Cookie header of the session cookie, from its name on (the site sets no other cookie, and the client
     * joins repeated headers with a comma, which the cookie's own date holds too).
     */
    private function sessionCookieHeader(SiteResponse $response): string
    {
        $name = (string) Idno::site()->config()->sessionname;
        $header = (string) $response->header('Set-Cookie');
        $start = strpos($header, $name . '=');
        $this->assertNotFalse($start, 'a session cookie in: ' . $header);

        return substr($header, (int) $start);
    }
}
