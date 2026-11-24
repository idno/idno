<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Core\Idno;
use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * The session cookie and its headers as the site sends them today: the cookie's name (from the site's
 * configuration), its flags and its lifetime, when it is marked Secure, its SameSite attribute (Lax), and that no P3P
 * header is sent.
 */
final class SessionCookieTest extends PrivateSiteTestCase
{
    use LogsIn;

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

    public function testNoP3PHeaderIsSent(): void
    {
        $response = $this->site()->request('GET', 'session/login');

        $this->assertNull($response->header('P3P'));
    }

    public function testSameSiteIsLax(): void
    {
        $response = $this->site()->request('GET', 'session/login');

        $this->assertMatchesRegularExpression('/;\s*SameSite=Lax\b/i', $this->sessionCookieHeader($response));
    }

    public function testASessionWrittenByAnEarlierReleaseStillLogsItsUserIn(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9');
        $cookie = $this->loginCookie('ada', 'correct-horse-battery-9');
        $id = substr($cookie, strpos($cookie, '=') + 1);

        // What a session written by an earlier release holds: `secure|b:0;` after the rest.
        $this->runInSite(
            '$id = ' . $this->literal($id) . ';
            $client = \Idno\Core\Idno::site()->db()->getClient();
            if ($client instanceof \Pdo\Mysql) {
                $client->prepare("update idno_sessions set session_value = concat(session_value, ?) where session_id = ?")->execute(["secure|b:0;", $id]);
            } else {
                $path = (string) (\Idno\Core\Idno::site()->config()->session_path ?: sys_get_temp_dir());
                $file = rtrim($path, "/") . "/sess_" . $id;
                file_put_contents($file, "secure|b:0;", FILE_APPEND);
            }
            return true;'
        );

        $this->assertSame(200, $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status, 'the user is still logged in');
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
