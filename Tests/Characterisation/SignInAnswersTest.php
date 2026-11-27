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

    private const METHOD_WHY = 'a method the page does not take is the legacy router\'s 404 page, as for every page that moved to a controller (the legacy page answered an empty page, or the redirect home of a page with no handler, or the 403 of a missing token)';

    private const HOST_WHY = 'a request with a malformed Host is served by the legacy router, which has no page for the address now: its 404 page (the legacy page drew the answer for the request)';

    private const XHR_WHY = 'an XHR GET gets the headers of the same request that is not XHR';

    private const JSON_WHY = 'the JSON {"location": ...} that tells a signed request where to go is application/json (the legacy page sent it as text/html)';

    /**
     * The cases the pages that replaced the legacy ones answer differently ON PURPOSE, with why: the recordings are the legacy pages' and are kept as they
     * were, and the test of each asserts the answer of the page that serves it now.
     */
    private const CHANGED = [
        'login-visitor-xhr' => self::XHR_WHY,
        'login-visitor-malformed-host' => self::HOST_WHY,
        'current-user-bob-xhr' => self::XHR_WHY,
        'current-user-put-visitor' => self::METHOD_WHY,
        'current-user-put-bob-with-token' => self::METHOD_WHY,
        'current-user-delete-visitor' => self::METHOD_WHY,
        'current-user-delete-bob-with-token' => self::METHOD_WHY,
        'login-post-array-email' => 'a login field that is not text (`email[]=bob`) is an empty value: the ordinary failed login, where the legacy page was the 500 page of a PHP error',
        'logout-get-visitor' => self::METHOD_WHY,
        'logout-get-bob' => self::METHOD_WHY,
        'logout-get-bob-json' => self::METHOD_WHY,
        'logout-head-visitor' => self::METHOD_WHY,
        'logout-head-bob' => self::METHOD_WHY,
        'login-put-visitor' => self::METHOD_WHY,
        'login-put-bob-with-token' => self::METHOD_WHY,
        'login-delete-visitor' => self::METHOD_WHY,
        'login-delete-bob-with-token' => self::METHOD_WHY,
        'logout-put-visitor' => self::METHOD_WHY,
        'logout-put-bob-with-token' => self::METHOD_WHY,
        'logout-delete-visitor' => self::METHOD_WHY,
        'logout-delete-bob-with-token' => self::METHOD_WHY,
        'login-post-signed' => self::JSON_WHY,
        'login-post-signed-json' => self::JSON_WHY,
        'logout-post-signed-no-cookie' => self::JSON_WHY,
        'logout-post-signed-with-cookie' => self::JSON_WHY,
        'logout-post-signed-other-cookie' => self::JSON_WHY,
        'logout-post-xhr' => 'an XHR logout is sent the caching lines of every XHR POST, as the XHR login is (the legacy logout\'s new session replaced them with the session\'s one line)',
    ];

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

    /** The recorded answer of a case, as a list of its lines up to the blank line that ends the headers, and its body. @return array{string, string} */
    private function recorded(string $case): array
    {
        [$headers, $body] = explode("\n\n", (string) file_get_contents(self::recordedAnswersDirectory() . '/' . self::GROUP . '/' . $case . '.txt'), 2);

        return [$headers, $body];
    }

    private function assertNotFoundPage(SiteResponse $answer, string $case): void
    {
        $this->assertSame(404, $answer->status, $case);
        $this->assertStringContainsString("This page can't be found", html_entity_decode($answer->body, ENT_QUOTES), $case);
        $this->assertNull($answer->header('Location'), $case);
    }

    /**
     * Catches: the login form of an XHR request answered with the legacy router's lines for it (two `Cache-Control` lines, a 1997 `Expires`, a JSON type)
     * where every page a controller serves gets the lines of the same request that is not XHR; and the body changing with it.
     */
    public function testAnXhrGetOfTheLoginPageGetsTheHeadersOfTheSameRequestThatIsNotXhr(): void
    {
        $answer = $this->site()->request('GET', '/session/login', ['X-Requested-With' => 'XMLHttpRequest']);
        $plain = $this->site()->request('GET', '/session/login');
        [$answeredHeaders, $answeredBody] = explode("\n\n", self::normalise(self::withoutLastModified($answer), $this->baseUrl()), 2);
        [$plainHeaders] = explode("\n\n", self::normalise(self::withoutLastModified($plain), $this->baseUrl()), 2);
        $this->assertSame($plainHeaders, $answeredHeaders);
        $this->assertSame($this->recorded('login-visitor-xhr')[1], $answeredBody, 'the body is what it was');
        $this->assertCount(1, $answer->lines('cache-control'));
    }

    /**
     * Catches: the current-user page of an XHR request answered with the legacy router's lines for it (two `Cache-Control` lines, a 1997 `Expires`, a JSON
     * type) where every page a controller serves gets the lines of the same request that is not XHR; and the member's page changing with it.
     */
    public function testAnXhrGetOfTheCurrentUserPageGetsTheHeadersOfTheSameRequestThatIsNotXhr(): void
    {
        $bob = ['Cookie' => $this->bobsSession()];
        $answer = $this->site()->request('GET', '/currentUser', $bob + ['X-Requested-With' => 'XMLHttpRequest']);
        $plain = $this->site()->request('GET', '/currentUser', $bob);
        [$answeredHeaders, $answeredBody] = explode("\n\n", self::normalise(self::withoutLastModified($answer), $this->baseUrl()), 2);
        [$plainHeaders] = explode("\n\n", self::normalise(self::withoutLastModified($plain), $this->baseUrl()), 2);
        $this->assertSame($plainHeaders, $answeredHeaders);
        $this->assertSame($this->recorded('current-user-bob-xhr')[1], $answeredBody, 'the body is what it was');
        $this->assertCount(1, $answer->lines('cache-control'));
    }

    /**
     * Catches: the XHR logout losing the caching lines every XHR POST has (both `Cache-Control` lines, as the XHR login sends them): the answer is the
     * recording but for the second `Cache-Control` line.
     */
    public function testAnXhrLogoutIsSentTheCachingLinesOfEveryXhrPost(): void
    {
        $cookie = $this->bobsSession();
        $token = $this->tokenWith(['Cookie' => $cookie]);
        $out = $this->site()->request('POST', '/session/logout', ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest'], ['__bTk' => $token]);
        $this->assertSame(['no-store, no-cache, must-revalidate', 'post-check=0, pre-check=0'], $out->lines('cache-control'));
        [$headers, $body] = $this->recorded('logout-post-xhr');
        $answered = self::normalise(self::withoutLastModified($this->scrub($token)($out)), $this->baseUrl());
        $this->assertSame(str_replace("cache-control: no-store, no-cache, must-revalidate\n", "cache-control: no-store, no-cache, must-revalidate\ncache-control: post-check=0, pre-check=0\n", $headers) . "\n\n" . $body, $answered);
    }

    /**
     * Catches: a method the two pages do not take (a GET or HEAD of the logout, a PUT or DELETE of either) or a request with a malformed Host being given
     * a page of its own again: the answer is the site's 404 page, as for every address no page takes.
     */
    public function testOtherMethodsAndAMalformedHostAreTheRoutersNotFoundPage(): void
    {
        $bob = ['Cookie' => $this->bobsSession()];
        $token = $this->tokenWith($bob);
        $x = ['body' => 'x'];
        $this->assertNotFoundPage($this->site()->request('GET', '/session/login', ['Host' => 'bad host!']), 'login-visitor-malformed-host');
        foreach (['/session/login', '/session/logout'] as $path) {
            foreach (['PUT', 'DELETE'] as $method) {
                $this->assertNotFoundPage($this->site()->request($method, $path, [], $x), $method . ' ' . $path);
                $this->assertNotFoundPage($this->site()->request($method, $path . '?__bTk=' . $token, $bob, $x), $method . ' ' . $path . ' with a token');
            }
        }
        $this->assertNotFoundPage($this->site()->request('GET', '/session/logout'), 'logout-get-visitor');
        $this->assertNotFoundPage($this->site()->request('GET', '/session/logout', $bob), 'logout-get-bob');
        $this->assertNotFoundPage($this->site()->request('GET', '/session/logout?_t=json', $bob), 'logout-get-bob-json');
        $this->assertSame(404, $this->site()->request('HEAD', '/session/logout')->status, 'logout-head-visitor');
        $this->assertSame(404, $this->site()->request('HEAD', '/session/logout', $bob)->status, 'logout-head-bob');
        foreach (['PUT', 'DELETE'] as $method) {
            $this->assertNotFoundPage($this->site()->request($method, '/currentUser', [], $x), $method . ' /currentUser');
            $this->assertNotFoundPage($this->site()->request($method, '/currentUser?__bTk=' . $token, $bob, $x), $method . ' /currentUser with a token');
        }
        // and none of them signed Bob out
        $this->assertSame('Bob Member', $this->site()->request('GET', '/currentUser?_t=json', $bob)->json()['user']['displayName']);
    }

    /**
     * Catches: the JSON `{"location": ...}` that tells a signed request where to go being sent as `text/html` again (any other site's script could read
     * the answer as the page it is): it is `application/json`, and the answer is the recording but for that line.
     */
    public function testASignedRequestIsToldWhereToGoAsJson(): void
    {
        $this->clearTheThrottle();
        $scrub = $this->scrubViewers();
        $cookie = $this->bobsSession();
        $cases = [
            'login-post-signed' => $this->site()->request('POST', '/session/login', self::signedHeadersFor('bob', self::KEY_BOB, '/session/login'), ['email' => 'bob', 'password' => self::PASSWORD]),
            'login-post-signed-json' => $this->site()->request('POST', '/session/login?_t=json', self::signedHeadersFor('bob', self::KEY_BOB, '/session/login?_t=json'), ['email' => 'bob', 'password' => self::PASSWORD]),
            'logout-post-signed-no-cookie' => $this->site()->request('POST', '/session/logout', self::signedHeadersFor('bob', self::KEY_BOB, '/session/logout')),
            'logout-post-signed-with-cookie' => $this->site()->request('POST', '/session/logout', ['Cookie' => $cookie] + self::signedHeadersFor('bob', self::KEY_BOB, '/session/logout')),
            'logout-post-signed-other-cookie' => $this->site()->request('POST', '/session/logout', ['Cookie' => $this->bobsSession()] + self::signedHeadersFor('ada', self::KEY_ADA, '/session/logout')),
        ];
        foreach ($cases as $case => $answer) {
            $this->assertSame('application/json', $answer->header('Content-Type'), $case);
            [$headers, $body] = $this->recorded($case);
            $this->assertSame(str_replace('content-type: text/html; charset=UTF-8', 'content-type: application/json', $headers) . "\n\n" . $body, self::normalise($scrub($answer), $this->baseUrl()), $case . ': the recording but for the type');
        }
        $this->clearTheThrottle();
    }

    /**
     * Catches: a login field that is not text (`email[]=bob`) being an error again (the legacy page was the 500 page of a PHP error, and on a site
     * without `pedantic_mode` a login attempt as "Array"): it is an empty value, so a failed login like any other that signs nobody in.
     */
    public function testALoginFieldThatIsNotTextIsAFailedLogin(): void
    {
        $this->clearTheThrottle();
        [$cookie, $token] = $this->visitorSession();
        foreach (['email' => ['bob'], 'password' => ['x']] as $field => $value) {
            $fields = ['__bTk' => $token, 'email' => 'bob', 'password' => self::PASSWORD, $field => $value];
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], $fields);
            $this->assertSame(302, $login->status, $field);
            $this->assertStringContainsString("That username or password isn't right", html_entity_decode($this->follow($login, $cookie)->body, ENT_QUOTES), $field);
            $this->assertSame(404, $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => self::sessionCookieOrNull($login) ?? $cookie])->status, $field . ': nobody is signed in');
        }
        $this->clearTheThrottle();
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
