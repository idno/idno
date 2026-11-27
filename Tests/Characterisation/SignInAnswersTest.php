<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the sign-in pages answer, recorded from the legacy pages over HTTP: the login page (`/session/login`) to a visitor and to a member in each
 * template type and for each way of asking, the login form's POST for a right password, a wrong one, a name nobody has, no form token, an array in
 * place of a name, the JSON type and the sixth failure in a window, the logout (`/session/logout`) and the current-user page (`/currentUser`), and
 * PUT and DELETE of each. The answers are `Tests/Characterisation/fixtures/answers/sign-in/`.
 *
 * The site is `ProfileSiteCase`'s (Ada the administrator, Bob a member, Carol), with the accounts' API keys fixed. A form token and a session id are
 * secrets of a session: no recording holds one. The address a login sends the browser to carries the page to go to as base64 (it holds the site's
 * address, which varies with the port): a recording holds it decoded, with the site's address as `{site}`. A scenario that signs in, out or fails is
 * run with the login throttle's counts emptied first, and twice when a capture is verified (each case is recorded twice).
 */
final class SignInAnswersTest extends SignInSiteCase
{
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    protected const GROUP = 'sign-in';

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    public function testTheLoginPageForAVisitor(): void
    {
        $s = $this->scrubViewers();
        $this->record('login-visitor-html', 'GET', '/session/login', [], 'visitor', null, $s);
        $this->record('login-visitor-json', 'GET', '/session/login?_t=json', [], 'visitor', null, $s);
        $this->record('login-visitor-jsonp', 'GET', '/session/login?_t=jsonp', [], 'visitor', null, $s);
        $this->record('login-visitor-rss', 'GET', '/session/login?_t=rss', [], 'visitor', null, $s);
        $this->record('login-visitor-markdown', 'GET', '/session/login?_t=markdown', [], 'visitor', null, $s);
        $this->record('login-visitor-head', 'HEAD', '/session/login', [], 'visitor', null, $s);
        $this->record('login-visitor-options', 'OPTIONS', '/session/login', [], 'visitor', null, $s);
        $this->record('login-visitor-xhr', 'GET', '/session/login', ['X-Requested-With' => 'XMLHttpRequest'], 'visitor', null, static fn (SiteResponse $r): SiteResponse => self::withoutLastModified($r));
        $this->record('login-visitor-malformed-host', 'GET', '/session/login', ['Host' => 'bad host!'], 'visitor', null, $s);
        $this->record('login-visitor-slash', 'GET', '/session/login/', [], 'visitor', null, $s);
        $this->record('login-visitor-double-slash', 'GET', '//session/login', [], 'visitor', null, $s);
        $this->addToAssertionCount(1);
    }

    public function testTheLoginPageForAVisitorWhoAsksToGoSomewhere(): void
    {
        $s = $this->scrubViewers();
        $encode = static fn (string $address): string => \Idno\Core\Webservice::base64UrlEncode($address);
        $this->record('login-visitor-fwd-local', 'GET', '/session/login?fwd=' . rawurlencode($encode('/account/settings/')), [], 'visitor', null, $s);
        $this->record('login-visitor-fwd-foreign', 'GET', '/session/login?fwd=' . rawurlencode($encode('https://evil.example/steal')), [], 'visitor', null, $s);
        $this->record('login-visitor-fwd-login-page', 'GET', '/session/login?fwd=' . rawurlencode($encode($this->baseUrl() . 'session/login')), [], 'visitor', null, $s);
        $this->addToAssertionCount(1);
    }

    public function testTheLoginPageForAMember(): void
    {
        $s = $this->scrubViewers();
        $encode = static fn (string $address): string => \Idno\Core\Webservice::base64UrlEncode($address);
        $this->record('login-bob-html', 'GET', '/session/login', [], 'owner', null, $s);
        $this->record('login-bob-fwd-local', 'GET', '/session/login?fwd=' . rawurlencode($encode('/account/settings/')), [], 'owner', null, $s);
        $this->record('login-bob-json', 'GET', '/session/login?_t=json', [], 'owner', null, $s);
        $this->record('login-bob-signed-json', 'GET', '/session/login?_t=json', self::signedHeadersFor('bob', self::KEY_BOB, '/session/login?_t=json'), 'visitor', null, $s);
        $this->addToAssertionCount(1);
    }

    public function testTheLoginForm(): void
    {
        $this->twice(function (): void {
            // a right password: the answer, the page it sends the browser to, and the page that sends it on
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => self::PASSWORD]);
            $this->answer('login-post-right', $login, $s);
            $then = $this->follow($login, $cookie);
            $this->answer('login-post-right-then', $then, $s);
            $this->answer('login-post-right-home', $this->follow($then, self::sessionCookieOrNull($login) ?? $cookie), $s);

            // a right password by email address, with a page to go to
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob@example.com', 'password' => self::PASSWORD, 'fwd' => '/account/settings/']);
            $this->answer('login-post-right-email-fwd', $login, $s);
            $this->answer('login-post-right-email-fwd-then', $this->follow($login, $cookie), $s);

            // a foreign page to go to
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => self::PASSWORD, 'fwd' => 'https://evil.example/steal']);
            $this->answer('login-post-right-foreign-fwd', $login, $s);

            // a wrong password, and the page the browser lands on
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => 'not the password']);
            $this->answer('login-post-wrong', $login, $s);
            $this->answer('login-post-wrong-then', $this->follow($login, $cookie), $s);

            // a name nobody has
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'nobody-has-this-name', 'password' => self::PASSWORD]);
            $this->answer('login-post-unknown', $login, $s);
            $this->answer('login-post-unknown-then', $this->follow($login, $cookie), $s);

            // no form token
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['email' => 'bob', 'password' => self::PASSWORD]);
            $this->answer('login-post-no-token', $login, $s);
            $this->answer('login-post-no-token-then', $this->follow($login, $cookie), $s);

            // an array in place of the name
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => ['bob'], 'password' => self::PASSWORD]);
            $this->answer('login-post-array-email', $login, static fn (SiteResponse $r): SiteResponse => self::withoutTheTrace($s($r)));

            // the JSON type: the answer, the page the same browser loads next, and who is signed in
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            $login = $this->site()->request('POST', '/session/login?_t=json', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => 'not the password']);
            $this->answer('login-post-json-wrong', $login, $s);
            $this->answer('login-post-json-wrong-then', $this->follow($login, $cookie), $s);
            $this->answer('login-post-json-wrong-user', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => self::sessionCookieOrNull($login) ?? $cookie]), $s);
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            $login = $this->site()->request('POST', '/session/login?_t=json', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => self::PASSWORD]);
            $this->answer('login-post-json-right', $login, $s);
            $this->answer('login-post-json-right-then', $this->follow($login, $cookie), $s);
            $this->answer('login-post-json-right-user', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => self::sessionCookieOrNull($login) ?? $cookie]), $s);
        });
    }

    public function testTheSixthFailureInAWindow(): void
    {
        $this->twice(function (): void {
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            for ($n = 1; $n <= 5; $n++) {
                $failed = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => 'wrong ' . $n]);
                $this->assertSame(302, $failed->status);
                // the page it lands on shows (and uses up) the message that waits in the session
                $this->assertStringContainsString("That username or password isn't right", html_entity_decode($this->follow($failed, $cookie)->body, ENT_QUOTES), 'failure ' . $n . ' is counted, not refused');
            }
            $sixth = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => 'wrong 6']);
            $this->answer('login-post-sixth-failure', $sixth, $s);
            $this->answer('login-post-sixth-failure-then', $this->follow($sixth, $cookie), $s);
            // the right password is refused too while the window is full
            $right = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => self::PASSWORD]);
            $this->answer('login-post-right-while-throttled', $right, $s);
            $this->answer('login-post-right-while-throttled-then', $this->follow($right, $cookie), $s);
            $this->answer('login-post-right-while-throttled-user', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => self::sessionCookieOrNull($right) ?? $cookie]), $s);
            // the JSON type
            $json = $this->site()->request('POST', '/session/login?_t=json', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => 'wrong 7']);
            $this->answer('login-post-json-throttled', $json, $s);
            $this->answer('login-post-json-throttled-then', $this->follow($json, $cookie), $s);
        });
    }

    public function testLogout(): void
    {
        $this->twice(function (): void {
            // by Bob with a token: the answer, and the page he lands on
            $cookie = $this->bobsSession();
            $token = $this->tokenWith(['Cookie' => $cookie]);
            $s = $this->scrub($token);
            $out = $this->site()->request('POST', '/session/logout', ['Cookie' => $cookie], ['__bTk' => $token]);
            $this->answer('logout-post-bob', $out, $s);
            $this->answer('logout-post-bob-then', $this->follow($out, $cookie), $s);
            $this->answer('logout-post-bob-old-session', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $cookie]), $s);

            // with a Referer on the site, and one on another site
            $cookie = $this->bobsSession();
            $token = $this->tokenWith(['Cookie' => $cookie]);
            $s = $this->scrub($token);
            $out = $this->site()->request('POST', '/session/logout', ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . 'account/settings/'], ['__bTk' => $token]);
            $this->answer('logout-post-bob-referer', $out, $s);
            $cookie = $this->bobsSession();
            $token = $this->tokenWith(['Cookie' => $cookie]);
            $s = $this->scrub($token);
            $out = $this->site()->request('POST', '/session/logout', ['Cookie' => $cookie, 'Referer' => 'https://evil.example/steal'], ['__bTk' => $token]);
            $this->answer('logout-post-bob-foreign-referer', $out, $s);

            // without a token: nothing is signed out
            $cookie = $this->bobsSession();
            $token = $this->tokenWith(['Cookie' => $cookie]);
            $s = $this->scrub($token);
            $out = $this->site()->request('POST', '/session/logout', ['Cookie' => $cookie], []);
            $this->answer('logout-post-bob-no-token', $out, $s);
            $this->answer('logout-post-bob-no-token-then', $this->follow($out, $cookie), $s);
            $this->answer('logout-post-bob-no-token-session', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $cookie]), $s);

            // by a signed request that carries Bob's cookie: the browser's session is left as it was
            $cookie = $this->bobsSession();
            $token = $this->tokenWith(['Cookie' => $cookie]);
            $s = $this->scrub($token);
            $out = $this->site()->request('POST', '/session/logout', ['Cookie' => $cookie] + self::signedHeadersFor('bob', self::KEY_BOB, '/session/logout'));
            $this->answer('logout-post-signed-with-cookie', $out, $s);
            $this->answer('logout-post-signed-with-cookie-session', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $cookie]), $s);

            // by a visitor
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            $out = $this->site()->request('POST', '/session/logout', ['Cookie' => $cookie], ['__bTk' => $token]);
            $this->answer('logout-post-visitor', $out, $s);
            $this->answer('logout-post-visitor-then', $this->follow($out, $cookie), $s);
        });
    }

    public function testLogoutAndCurrentUserForRequestsThatChangeNothing(): void
    {
        $s = $this->scrubViewers();
        $this->record('logout-get-visitor', 'GET', '/session/logout', [], 'visitor', null, $s);
        $this->record('logout-get-bob', 'GET', '/session/logout', [], 'owner', null, $s);
        $this->record('logout-get-bob-json', 'GET', '/session/logout?_t=json', [], 'owner', null, $s);
        $this->record('current-user-visitor', 'GET', '/currentUser', [], 'visitor', null, $s);
        $this->record('current-user-visitor-json', 'GET', '/currentUser?_t=json', [], 'visitor', null, $s);
        $this->record('current-user-bob', 'GET', '/currentUser', [], 'owner', null, $s);
        $this->record('current-user-bob-slash', 'GET', '/currentUser/', [], 'owner', null, $s);
        $this->record('current-user-bob-json', 'GET', '/currentUser?_t=json', [], 'owner', null, $s);
        $this->record('current-user-bob-signed-json', 'GET', '/currentUser?_t=json', self::signedHeadersFor('bob', self::KEY_BOB, '/currentUser?_t=json'), 'visitor', null, $s);
        $this->record('current-user-bob-xhr', 'GET', '/currentUser', ['X-Requested-With' => 'XMLHttpRequest'], 'owner', null, static fn (SiteResponse $r): SiteResponse => self::withoutLastModified($r));
        $this->record('current-user-admin-json', 'GET', '/currentUser?_t=json', [], 'admin', null, $s);
        $this->addToAssertionCount(1);
    }

    public function testPostsToTheCurrentUserPage(): void
    {
        $this->twice(function (): void {
            $cookie = $this->bobsSession();
            $token = $this->tokenWith(['Cookie' => $cookie]);
            $s = $this->scrub($token);
            $this->answer('current-user-post-bob', $this->site()->request('POST', '/currentUser', ['Cookie' => $cookie], ['__bTk' => $token]), $s);
            $this->answer('current-user-post-bob-json', $this->site()->request('POST', '/currentUser?_t=json', ['Cookie' => $cookie], ['__bTk' => $token]), $s);
            $this->answer('current-user-post-bob-no-token', $this->site()->request('POST', '/currentUser', ['Cookie' => $cookie], []), $s);
            $this->answer('current-user-post-signed', $this->site()->request('POST', '/currentUser', self::signedHeadersFor('bob', self::KEY_BOB, '/currentUser')), $s);
            [$visitor, $visitorToken] = $this->visitorSession();
            $this->answer('current-user-post-visitor', $this->site()->request('POST', '/currentUser', ['Cookie' => $visitor], ['__bTk' => $visitorToken]), $this->scrub($visitorToken));
        });
    }

    public function testPutAndDeleteOfEachAddress(): void
    {
        $this->twice(function (): void {
            $cookie = $this->bobsSession();
            $token = $this->tokenWith(['Cookie' => $cookie]);
            $s = $this->scrub($token);
            $owner = ['Cookie' => $cookie];
            $x = ['body' => 'x'];
            $this->answer('login-put-visitor', $this->site()->request('PUT', '/session/login', [], $x), $s);
            $this->answer('login-put-bob-with-token', $this->site()->request('PUT', '/session/login?__bTk=' . $token, $owner, $x), $s);
            $this->answer('login-delete-visitor', $this->site()->request('DELETE', '/session/login', [], $x), $s);
            $this->answer('login-delete-bob-with-token', $this->site()->request('DELETE', '/session/login?__bTk=' . $token, $owner, $x), $s);
            $this->answer('logout-put-visitor', $this->site()->request('PUT', '/session/logout', [], $x), $s);
            $this->answer('logout-put-bob-with-token', $this->site()->request('PUT', '/session/logout?__bTk=' . $token, $owner, $x), $s);
            $this->answer('logout-delete-visitor', $this->site()->request('DELETE', '/session/logout', [], $x), $s);
            $this->answer('logout-delete-bob-with-token', $this->site()->request('DELETE', '/session/logout?__bTk=' . $token, $owner, $x), $s);
            $this->answer('current-user-put-visitor', $this->site()->request('PUT', '/currentUser', [], $x), $s);
            $this->answer('current-user-put-bob-with-token', $this->site()->request('PUT', '/currentUser?__bTk=' . $token, $owner, $x), $s);
            $this->answer('current-user-delete-visitor', $this->site()->request('DELETE', '/currentUser', [], $x), $s);
            $this->answer('current-user-delete-bob-with-token', $this->site()->request('DELETE', '/currentUser?__bTk=' . $token, $owner, $x), $s);
            // the session still stands after all of them
            $this->answer('login-put-delete-bob-session', $this->site()->request('GET', '/currentUser?_t=json', $owner), $s);
        });
    }

    /** Fails a login for each name, in one browser session, using up the message each leaves (so the counts are all that carry over). @param list<string> $names */
    private function failLogins(string $cookie, string $token, array $names): void
    {
        foreach ($names as $name) {
            $failed = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => $name, 'password' => 'not the password']);
            $this->assertSame(302, $failed->status);
            $this->assertStringContainsString("That username or password isn't right", html_entity_decode($this->follow($failed, $cookie)->body, ENT_QUOTES), 'a failure that is counted, not refused: ' . $name);
        }
    }

    public function testWhoseFailuresAreCountedTogether(): void
    {
        $this->twice(function (): void {
            // the handle and the email address of one account share one count
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            $this->failLogins($cookie, $token, ['bob', 'bob', 'bob', 'bob@example.com', 'bob@example.com']);
            $sixth = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => 'not the password']);
            $this->answer('login-throttle-handle-and-email-sixth', $sixth, $s);
            $this->answer('login-throttle-handle-and-email-sixth-then', $this->follow($sixth, $cookie), $s);

            // so do the spellings of the handle
            $this->clearTheThrottle();
            $this->failLogins($cookie, $token, ['bob', 'BOB', 'Bob', 'bOb', 'bob']);
            $sixth = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'BOB', 'password' => 'not the password']);
            $this->answer('login-throttle-upper-case-sixth', $sixth, $s);
            $this->answer('login-throttle-upper-case-sixth-then', $this->follow($sixth, $cookie), $s);

            // a name nobody has is counted by itself
            $this->clearTheThrottle();
            $this->failLogins($cookie, $token, array_fill(0, 5, 'nobody-has-this-name'));
            $sixth = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'nobody-has-this-name', 'password' => 'not the password']);
            $this->answer('login-throttle-unknown-sixth', $sixth, $s);
            $this->answer('login-throttle-unknown-sixth-then', $this->follow($sixth, $cookie), $s);
            $other = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'nobody-has-this-other-name', 'password' => 'not the password']);
            $this->answer('login-throttle-unknown-other-name', $other, $s);
            $this->answer('login-throttle-unknown-other-name-then', $this->follow($other, $cookie), $s);
            // and the account that has its own name is not held up by it
            $bob = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => 'not the password']);
            $this->answer('login-throttle-unknown-then-bob', $bob, $s);
            $this->answer('login-throttle-unknown-then-bob-then', $this->follow($bob, $cookie), $s);
        });
    }

    public function testWhereALoginSendsTheBrowser(): void
    {
        $this->twice(function (): void {
            $port = (int) parse_url($this->baseUrl(), PHP_URL_PORT);
            $cases = [
                'login-post-fwd-protocol-relative' => '//evil.example/steal',
                'login-post-fwd-backslash' => '/\\evil.example',
                'login-post-fwd-control-character' => "/x\x01y",
                'login-post-fwd-same-origin-absolute' => $this->baseUrl() . 'account/settings/',
                'login-post-fwd-other-port' => str_replace(':' . $port, ':' . ($port + 1), $this->baseUrl()) . 'account/settings/',
                'login-post-fwd-javascript' => 'javascript:alert(1)',
                'login-post-fwd-empty' => '',
            ];
            foreach ($cases as $case => $fwd) {
                [$cookie, $token] = $this->visitorSession();
                $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => self::PASSWORD, 'fwd' => $fwd]);
                $this->answer($case, $login, $this->scrub($token));
            }
        });
    }

    public function testTheLoginPageWithOddForwards(): void
    {
        $s = $this->scrubViewers();
        $encode = static fn (string $address): string => \Idno\Core\Webservice::base64UrlEncode($address);
        $this->record('login-visitor-fwd-invalid-base64', 'GET', '/session/login?fwd=' . rawurlencode('!!not base64!!'), [], 'visitor', null, $s);
        $this->record('login-visitor-fwd-empty', 'GET', '/session/login?fwd=', [], 'visitor', null, $s);
        $this->record('login-bob-fwd-foreign', 'GET', '/session/login?fwd=' . rawurlencode($encode('https://evil.example/steal')), [], 'owner', null, $s);
        $this->record('login-bob-fwd-protocol-relative', 'GET', '/session/login?fwd=' . rawurlencode($encode('//evil.example/steal')), [], 'owner', null, $s);
        $this->addToAssertionCount(1);
    }

    public function testSignedRequestsToTheSignInPages(): void
    {
        $this->twice(function (): void {
            $s = $this->scrubViewers();
            $this->answer('login-post-signed', $this->site()->request('POST', '/session/login', self::signedHeadersFor('bob', self::KEY_BOB, '/session/login'), ['email' => 'bob', 'password' => self::PASSWORD]), $s);
            $this->answer('login-post-signed-json', $this->site()->request('POST', '/session/login?_t=json', self::signedHeadersFor('bob', self::KEY_BOB, '/session/login?_t=json'), ['email' => 'bob', 'password' => self::PASSWORD]), $s);
            $this->answer('logout-post-signed-no-cookie', $this->site()->request('POST', '/session/logout', self::signedHeadersFor('bob', self::KEY_BOB, '/session/logout')), $s);
            $this->answer('login-bob-bad-signature-json', $this->site()->request('GET', '/session/login?_t=json', self::signedHeadersFor('bob', 'not the key', '/session/login?_t=json')), $s);
            $this->answer('current-user-bad-signature-json', $this->site()->request('GET', '/currentUser?_t=json', self::signedHeadersFor('bob', 'not the key', '/currentUser?_t=json')), $s);
            $this->answer('current-user-unknown-user-signature-json', $this->site()->request('GET', '/currentUser?_t=json', self::signedHeadersFor('nobody-has-this-name', self::KEY_BOB, '/currentUser?_t=json')), $s);

            // a signed request of Ada's that carries Bob's cookie acts as Ada and leaves Bob's session as it was
            $cookie = $this->bobsSession();
            $token = $this->tokenWith(['Cookie' => $cookie]);
            $s = $this->scrub($token);
            $this->answer('current-user-signed-other-cookie-json', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $cookie] + self::signedHeadersFor('ada', self::KEY_ADA, '/currentUser?_t=json')), $s);
            $out = $this->site()->request('POST', '/session/logout', ['Cookie' => $cookie] + self::signedHeadersFor('ada', self::KEY_ADA, '/session/logout'));
            $this->answer('logout-post-signed-other-cookie', $out, $s);
            $this->answer('logout-post-signed-other-cookie-session', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $cookie]), $s);
        });
    }

    public function testRequestsThatSayTheyAreXhrOrAskForJson(): void
    {
        $this->twice(function (): void {
            $xhr = ['X-Requested-With' => 'XMLHttpRequest'];
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie] + $xhr, ['__bTk' => $token, 'email' => 'bob', 'password' => 'not the password']);
            $this->answer('login-post-xhr-wrong', $login, static fn (SiteResponse $r): SiteResponse => self::withoutLastModified($s($r)));
            $this->answer('login-post-xhr-wrong-then', $this->site()->request('GET', '/session/login', ['Cookie' => self::sessionCookieOrNull($login) ?? $cookie]), $s);
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie] + $xhr, ['__bTk' => $token, 'email' => 'bob', 'password' => self::PASSWORD]);
            $this->answer('login-post-xhr-right', $login, static fn (SiteResponse $r): SiteResponse => self::withoutLastModified($s($r)));
            $this->answer('login-post-xhr-right-user', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => self::sessionCookieOrNull($login) ?? $cookie]), $s);

            $cookie = $this->bobsSession();
            $token = $this->tokenWith(['Cookie' => $cookie]);
            $s = $this->scrub($token);
            $out = $this->site()->request('POST', '/session/logout', ['Cookie' => $cookie] + $xhr, ['__bTk' => $token]);
            $this->answer('logout-post-xhr', $out, static fn (SiteResponse $r): SiteResponse => self::withoutLastModified($s($r)));
            $this->answer('logout-post-xhr-session', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $cookie]), $s);

            $json = ['Accept' => 'application/json'];
            $this->answer('login-visitor-accept-json', $this->site()->request('GET', '/session/login', $json), $s);
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie] + $json, ['__bTk' => $token, 'email' => 'bob', 'password' => 'not the password']);
            $this->answer('login-post-accept-json-wrong', $login, $s);
            $this->answer('login-post-accept-json-wrong-then', $this->site()->request('GET', '/session/login', ['Cookie' => self::sessionCookieOrNull($login) ?? $cookie] + $json), $s);
            $this->answer('current-user-bob-accept-json', $this->site()->request('GET', '/currentUser', $this->asViewer('owner', $json)), $this->scrubViewers());
        });
    }

    public function testHeadAndOptionsOfLogoutAndTheCurrentUserPage(): void
    {
        $s = $this->scrubViewers();
        $this->record('logout-head-visitor', 'HEAD', '/session/logout', [], 'visitor', null, $s);
        $this->record('logout-head-bob', 'HEAD', '/session/logout', [], 'owner', null, $s);
        $this->record('logout-options-visitor', 'OPTIONS', '/session/logout', [], 'visitor', null, $s);
        $this->record('current-user-head-visitor', 'HEAD', '/currentUser', [], 'visitor', null, $s);
        $this->record('current-user-head-bob', 'HEAD', '/currentUser', [], 'owner', null, $s);
        $this->record('current-user-options-visitor', 'OPTIONS', '/currentUser', [], 'visitor', null, $s);
        $this->record('current-user-options-bob', 'OPTIONS', '/currentUser', [], 'owner', null, $s);
        $this->record('current-user-bob-jsonp', 'GET', '/currentUser?_t=jsonp&callback=cb', [], 'owner', null, $s);
        $this->addToAssertionCount(1);
    }

    public function testEmptyFieldsAndALoginWhileSignedIn(): void
    {
        $this->twice(function (): void {
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => '', 'password' => self::PASSWORD]);
            $this->answer('login-post-empty-email', $login, $s);
            $this->answer('login-post-empty-email-then', $this->follow($login, $cookie), $s);
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => '']);
            $this->answer('login-post-empty-password', $login, $s);
            $this->answer('login-post-empty-password-then', $this->follow($login, $cookie), $s);

            // Bob signs in as Ada
            $cookie = $this->bobsSession();
            $token = $this->tokenWith(['Cookie' => $cookie]);
            $s = $this->scrub($token);
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'ada', 'password' => self::PASSWORD]);
            $this->answer('login-post-while-signed-in', $login, $s);
            $this->answer('login-post-while-signed-in-user', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => self::sessionCookieOrNull($login) ?? $cookie]), $s);
        });
    }
}
