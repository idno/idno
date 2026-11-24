<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Core\Webservice;
use Tests\Acceptance\Browser;
use Tests\Support\PrivateSiteTestCase;

/**
 * Logging in and out through the forms, over HTTP, on a site of the test's own: what the login page accepts, where
 * it sends the browser, what it says, what it does to the session and which events it fires. Every assertion is
 * what the site does today, including what is plainly a defect (a message that is never shown, an unsanitised
 * redirect); a change of any of them is made on purpose.
 */
final class LoginFlowTest extends PrivateSiteTestCase
{
    private const PASSWORD = 'correct-horse-battery-9';

    private string $eventFile = '';

    protected function siteEnvironment(): array
    {
        // Read again for every subprocess of the site (runInSite()): the file is made once.
        $this->eventFile = $this->eventFile !== '' ? $this->eventFile : sys_get_temp_dir() . '/idno-events-' . bin2hex(random_bytes(6));

        return ['IDNO_TEST_RECORD_EVENTS' => $this->eventFile];
    }

    protected function tearDown(): void
    {
        if ($this->eventFile !== '' && is_file($this->eventFile)) {
            unlink($this->eventFile);
        }
        parent::tearDown();
    }

    public function testLoginByHandleAndByEmail(): void
    {
        $this->registerUser('ada');
        $this->addUser('grace');

        $byHandle = $this->loginCookie('grace', self::PASSWORD);
        $byEmail = $this->loginCookie('grace@example.com', self::PASSWORD);

        $this->assertSame('Grace', $this->nameOf($byHandle));
        $this->assertSame('Grace', $this->nameOf($byEmail));
        $this->assertNotSame($byHandle, $byEmail, 'each login is a session of its own');
    }

    public function testWrongPasswordAndUnknownUserMessages(): void
    {
        $this->registerUser('ada');

        [$wrong, $wrongPage] = $this->failedLogin('ada', 'not the password');
        [$unknown, $unknownPage] = $this->failedLogin('nobody-here', self::PASSWORD);

        // The two differ today (an attacker can tell a real handle from an invented one), and so do the statuses.
        $this->assertSame(302, $wrong->status);
        $this->assertStringContainsString('/session/login/?fwd=', (string) $wrong->header('Location'));
        $this->assertSame(302, $unknown->status, 'the 401 the page sets for an unknown user is replaced by the redirect');
        $this->assertStringContainsString('/session/login/?fwd=', (string) $unknown->header('Location'));
        $this->assertStringContainsString("Oops! It looks like your password isn't correct. Please try again.", Browser::text($wrongPage->body));
        $this->assertStringNotContainsString("couldn't find your username", Browser::text($wrongPage->body));
        $this->assertStringContainsString("Oops! We couldn't find your username or email address. Please check you typed it correctly and try again.", Browser::text($unknownPage->body));
        $this->assertStringNotContainsString("password isn't correct", Browser::text($unknownPage->body));
    }

    /**
     * @return array<string, array{0: string, 1: string}> the fwd field of the login form, the path the browser ends up at
     */
    public static function forwards(): array
    {
        return [
            'a local path' => ['/x', '/x'],
            'a local path with a query' => ['/x?a=b&c=d', '/x?a=b&c=d'],
            'an absolute address on the site' => ['SITE/y', 'SITE/y'],
            'another site' => ['https://other.example/', 'SITE'],
            'a protocol-relative address' => ['//other.example', 'SITE'],
            'a scheme that is not http' => ['javascript:alert(1)', 'SITE'],
            'a backslash' => ['/\\other.example', 'SITE'],
            'a control character' => ["/x\r\nSet-Cookie: a=b", 'SITE'],
            'the site as a user-info trick' => ['http://127.0.0.1@other.example/', 'SITE'],
            'nothing' => ['', 'SITE'],
        ];
    }

    /**
     * @dataProvider forwards
     */
    public function testFwdIsFollowedWhenLocalAndReplacedWhenNot(string $fwd, string $ends): void
    {
        $this->registerUser('ada');
        $fwd = str_replace('SITE', rtrim($this->baseUrl(), '/'), $fwd);
        $ends = $ends === 'SITE' ? $this->baseUrl() : str_replace('SITE', rtrim($this->baseUrl(), '/'), $ends);
        $form = $this->site()->request('GET', 'session/login');
        $cookie = self::sessionCookie($form);

        $login = $this->site()->request('POST', 'session/login', ['Cookie' => $cookie], self::formToken($form->body, 'session/login') + ['email' => 'ada', 'password' => self::PASSWORD, 'fwd' => $fwd]);
        $cookie = self::sessionCookieOrNull($login) ?? $cookie;
        $this->assertSame(302, $login->status);
        $hop = (string) $login->header('Location');
        $this->assertStringStartsWith($this->baseUrl() . 'session/login/?fwd=', $hop, 'the login always goes back through the login page');
        $second = $this->site()->request('GET', substr($hop, strlen($this->baseUrl())), ['Cookie' => $cookie]);

        $this->assertSame(302, $second->status);
        $this->assertSame($ends, (string) $second->header('Location'));
    }

    public function testTheLoginPageFollowsAnEncodedFwdOnlyWhenLocal(): void
    {
        $cookie = $this->registerUser('ada');
        $site = rtrim($this->baseUrl(), '/');

        foreach (['/x' => '/x', $site . '/y' => $site . '/y', 'https://other.example/' => $site . '/', '//other.example' => $site . '/', 'javascript:alert(1)' => $site . '/'] as $wanted => $ends) {
            $response = $this->site()->request('GET', 'session/login/?fwd=' . Webservice::encodeValue($wanted), ['Cookie' => $cookie]);

            $this->assertSame(302, $response->status, $wanted);
            $this->assertSame($ends, (string) $response->header('Location'), $wanted);
        }
        // An unencoded value is not an address the page can read.
        $plain = $this->site()->request('GET', 'session/login/?fwd=%2Fx', ['Cookie' => $cookie]);
        $this->assertSame($site . '/', (string) $plain->header('Location'));
    }

    public function testJsonLoginKeepsTheTemplateType(): void
    {
        $this->registerUser('ada');
        $form = $this->site()->request('GET', 'session/login');
        $cookie = self::sessionCookie($form);

        $login = $this->site()->request('POST', 'session/login?_t=json', ['Cookie' => $cookie], self::formToken($form->body, 'session/login') + ['email' => 'ada', 'password' => self::PASSWORD]);
        $cookie = self::sessionCookieOrNull($login) ?? $cookie;

        $this->assertSame(302, $login->status);
        $hop = (string) $login->header('Location');
        $this->assertStringContainsString('_t=json', $hop);
        $second = $this->site()->request('GET', substr($hop, strlen($this->baseUrl())), ['Cookie' => $cookie]);
        // A logged-in visitor of the login page in any other template type is not forwarded: the page answers itself.
        $this->assertSame(200, $second->status);
        $this->assertArrayHasKey('fwd', $second->json());
    }

    public function testLoginPostToBothSpellingsOfTheAddress(): void
    {
        $this->registerUser('ada');

        foreach (['session/login', 'session/login/'] as $address) {
            $form = $this->site()->request('GET', 'session/login');
            $cookie = self::sessionCookie($form);
            $login = $this->site()->request('POST', $address, ['Cookie' => $cookie], self::formToken($form->body, 'session/login') + ['email' => 'ada', 'password' => self::PASSWORD]);
            $cookie = self::sessionCookieOrNull($login) ?? $cookie;

            $this->assertSame(302, $login->status, $address);
            $this->assertSame(200, $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status, $address . ' logs in');
        }
    }

    public function testSessionIdChangesOnLogin(): void
    {
        $this->registerUser('ada');
        $form = $this->site()->request('GET', 'session/login');
        $before = self::sessionCookie($form);

        $login = $this->site()->request('POST', 'session/login', ['Cookie' => $before], self::formToken($form->body, 'session/login') + ['email' => 'ada', 'password' => self::PASSWORD]);

        $after = self::sessionCookie($login);
        $this->assertNotSame($before, $after, 'a login starts a new session');
        $this->assertSame(200, $this->site()->request('GET', 'account/settings/', ['Cookie' => $after])->status);
        $this->assertSame(403, $this->site()->request('GET', 'account/settings/', ['Cookie' => $before])->status, 'and the old session id is of no use');
    }

    public function testALoginWithAnUnknownSessionIdStartsASessionOfTheSitesOwn(): void
    {
        $this->registerUser('ada');
        $name = (string) \Idno\Core\Idno::site()->config()->sessionname;
        $planted = $name . '=' . str_repeat('a', 32);
        $form = $this->site()->request('GET', 'session/login', ['Cookie' => $planted]);

        $this->assertNotSame($planted, self::sessionCookie($form), 'an id the site never issued is not adopted (strict mode)');
    }

    public function testLogoutEndsTheSessionAndWhereItRedirects(): void
    {
        $cookie = $this->registerUser('ada');
        $token = $this->logoutToken($cookie);

        $logout = $this->site()->request('POST', 'session/logout', ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . 'content/all'], $token);

        $this->assertSame(302, $logout->status);
        $this->assertSame($this->baseUrl() . 'content/all', $logout->header('Location'), 'back to the page the browser came from');
        $this->assertSame(403, $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status, 'the session is over');
    }

    public function testLogoutWithoutARefererGoesToTheHomePage(): void
    {
        $cookie = $this->registerUser('ada');

        $logout = $this->site()->request('POST', 'session/logout', ['Cookie' => $cookie], $this->logoutToken($cookie));

        $this->assertSame(302, $logout->status);
        $this->assertSame($this->baseUrl(), $logout->header('Location'));
    }

    public function testLogoutRedirectsToAForeignRefererAsItIs(): void
    {
        $cookie = $this->registerUser('ada');

        $logout = $this->site()->request('POST', 'session/logout', ['Cookie' => $cookie, 'Referer' => 'https://other.example/phish'], $this->logoutToken($cookie));

        // Pins today: the redirect is not made same-origin (sanitizeRedirectUrl() is not applied).
        $this->assertSame(302, $logout->status);
        $this->assertSame('https://other.example/phish', $logout->header('Location'));
    }

    public function testLogoutClearsTheSessionCookie(): void
    {
        $cookie = $this->registerUser('ada');

        $logout = $this->site()->request('POST', 'session/logout', ['Cookie' => $cookie, 'Referer' => $this->baseUrl()], $this->logoutToken($cookie));

        $name = (string) \Idno\Core\Idno::site()->config()->sessionname;
        $this->assertMatchesRegularExpression('/' . preg_quote($name, '/') . '=deleted; expires=/', (string) $logout->header('Set-Cookie'));
    }

    public function testLogoutMessage(): void
    {
        $cookie = $this->registerUser('ada');
        $logout = $this->site()->request('POST', 'session/logout', ['Cookie' => $cookie, 'Referer' => $this->baseUrl()], $this->logoutToken($cookie));
        $this->assertSame(302, $logout->status);

        $afterOld = $this->site()->request('GET', '', ['Cookie' => $cookie]);
        $new = self::sessionCookieOrNull($afterOld);
        $afterNew = $this->site()->request('GET', '', $new === null ? [] : ['Cookie' => $new]);

        // Pins today: the message is added after the session was destroyed, so nobody is shown it.
        $this->assertStringNotContainsString('signed out', Browser::text($afterOld->body));
        $this->assertStringNotContainsString('signed out', Browser::text($afterNew->body));
    }

    public function testLogoutWithoutTokenDoesNothing(): void
    {
        $cookie = $this->registerUser('ada');

        $logout = $this->site()->request('POST', 'session/logout', ['Cookie' => $cookie, 'Referer' => $this->baseUrl()], []);

        $this->assertSame(302, $logout->status);
        $this->assertSame(200, $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status, 'still logged in');
    }

    public function testLogoutByGetDoesNothing(): void
    {
        $cookie = $this->registerUser('ada');

        $this->site()->request('GET', 'session/logout', ['Cookie' => $cookie, 'Referer' => $this->baseUrl()]);

        $this->assertSame(200, $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status, 'still logged in');
    }

    public function testBlockedEmailCannotLogIn(): void
    {
        $this->registerUser('ada');
        $this->addUser('grace');
        $this->runInSite('$c = \Idno\Core\Idno::site()->config(); $c->config["blocked_emails"] = ["grace@example.com"]; $c->blocked_emails = ["grace@example.com"]; $c->save(); return true;');
        $form = $this->site()->request('GET', 'session/login');
        $cookie = self::sessionCookie($form);

        $login = $this->site()->request('POST', 'session/login', ['Cookie' => $cookie], self::formToken($form->body, 'session/login') + ['email' => 'grace', 'password' => self::PASSWORD]);
        $cookie = self::sessionCookieOrNull($login) ?? $cookie;

        $this->assertSame(302, $login->status);
        $this->assertSame(403, $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status, 'not logged in');
        $this->loginCookie('ada', self::PASSWORD);
    }

    public function testEventsFire(): void
    {
        $this->registerUser('ada');
        $this->events();

        $this->failedLogin('ada', 'not the password');
        $afterWrong = $this->events();
        $this->failedLogin('nobody-here', self::PASSWORD);
        $afterUnknown = $this->events();
        $cookie = $this->loginCookie('ada', self::PASSWORD);
        $afterLogin = $this->events();
        $this->site()->request('POST', 'session/logout', ['Cookie' => $cookie, 'Referer' => $this->baseUrl()], $this->logoutToken($cookie));
        $afterLogout = $this->events();

        $this->assertSame([['login/failure', 'ada']], $this->namesAndUsers($afterWrong, ['login/failure', 'login/failure/nouser', 'login/success', 'user/logon', 'user/logoff', 'logout/success']));
        $this->assertSame([['login/failure/nouser', null]], $this->namesAndUsers($afterUnknown, ['login/failure', 'login/failure/nouser', 'login/success', 'user/logon', 'user/logoff', 'logout/success']));
        $this->assertSame([['login/success', 'ada'], ['user/logon', 'ada']], $this->namesAndUsers($afterLogin, ['login/failure', 'login/failure/nouser', 'login/success', 'user/logon', 'user/logoff', 'logout/success']));
        $this->assertSame([['logout/success', 'ada'], ['user/logoff', 'ada']], $this->namesAndUsers($afterLogout, ['login/failure', 'login/failure/nouser', 'login/success', 'user/logon', 'user/logoff', 'logout/success']));
    }

    public function testTheAuthenticationEventsOfARequestBySession(): void
    {
        $cookie = $this->registerUser('ada');
        $this->events();

        $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie]);
        $member = $this->events();
        $this->site()->request('GET', 'account/settings/');
        $anonymous = $this->events();

        $this->assertSame([['user/auth/success', 'ada', false]], array_map(static fn (array $e): array => [$e['event'], $e['user'], $e['api']], $member));
        $this->assertSame([['user/auth/failure', null, false]], array_map(static fn (array $e): array => [$e['event'], $e['user'], $e['api']], $anonymous));
    }

    public function testTheAuthenticationEventsOfASignedRequest(): void
    {
        $this->registerUser('ada');
        $key = $this->apiKeyOf('ada');
        $this->events();

        $response = $this->site()->request('GET', 'currentUser?_t=json', self::signedHeadersFor('ada', $key, '/currentUser?_t=json'));
        $events = $this->events();

        $this->assertSame(200, $response->status);
        $this->assertSame([['user/auth/success', 'ada', true]], array_map(static fn (array $e): array => [$e['event'], $e['user'], $e['api']], array_values(array_filter($events, static fn (array $e): bool => str_starts_with($e['event'], 'user/auth/')))), 'the event says it was an API request');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Posts the login form with credentials the site refuses, as a browser would, and loads the page it sends the
     * browser to, with the session it started.
     *
     * @return array{0: SiteResponse, 1: SiteResponse} the answer to the post and the page it redirects to
     */
    private function failedLogin(string $handle, string $password): array
    {
        $form = $this->site()->request('GET', 'session/login');
        $cookie = self::sessionCookie($form);
        $post = $this->site()->request('POST', 'session/login', ['Cookie' => $cookie], self::formToken($form->body, 'session/login') + ['email' => $handle, 'password' => $password]);
        $cookie = self::sessionCookieOrNull($post) ?? $cookie;
        $page = $this->site()->request('GET', substr((string) $post->header('Location'), strlen($this->baseUrl())), ['Cookie' => $cookie]);
        $this->assertSame(200, $page->status);

        return [$post, $page];
    }

    /**
     * The signed token of the logout form of a page a logged-in member sees.
     *
     * @return array<string, string>
     */
    private function logoutToken(string $cookie): array
    {
        $page = $this->site()->request('GET', '', ['Cookie' => $cookie]);
        $this->assertSame(200, $page->status);

        return self::formToken($page->body, 'session/logout');
    }

    private function nameOf(string $cookie): string
    {
        $page = $this->site()->request('GET', 'currentUser', ['Cookie' => $cookie]);
        $this->assertSame(200, $page->status);
        preg_match('#<title>([^<]*?)(?: &mdash;| —| -|</title>)#u', $page->body, $m);

        return $this->site()->request('GET', 'currentUser?_t=json', ['Cookie' => $cookie])->json()['user']['name'] ?? ($m[1] ?? '');
    }

    /**
     * The events the site recorded since the last call.
     *
     * @return list<array{event: string, user: ?string, api: ?bool}>
     */
    private function events(): array
    {
        if (!is_file($this->eventFile)) {
            return [];
        }
        $lines = array_filter(explode("\n", (string) file_get_contents($this->eventFile)));
        file_put_contents($this->eventFile, '');

        return array_values(array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines));
    }

    /**
     * @param list<array{event: string, user: ?string, api: ?bool}> $events
     * @param list<string>                                         $only
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private function namesAndUsers(array $events, array $only): array
    {
        return array_values(array_map(static fn (array $e): array => [$e['event'], $e['user']], array_filter($events, static fn (array $e): bool => in_array($e['event'], $only, true))));
    }
}
