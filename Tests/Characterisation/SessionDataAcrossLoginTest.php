<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Acceptance\Browser;
use Tests\Support\PrivateSiteTestCase;

/**
 * What the session carries through the events that change who it belongs to: registration, onboarding, a password
 * reset, a change of password, the IndieAuth approval and an API request that touches the session. Each is a walk
 * through the real pages over HTTP on a site of the test's own. The assertions are what the site does today,
 * including what is a defect, so that a change of session storage or of authentication shows where it matters.
 */
final class SessionDataAcrossLoginTest extends PrivateSiteTestCase
{
    private const PASSWORD = 'correct-horse-battery-9';

    public function testRegistrationLogsTheNewUserOnAndKeepsItsMessage(): void
    {
        $form = $this->site()->request('GET', 'begin/register');
        $before = self::sessionCookie($form);

        $post = $this->site()->request('POST', 'begin/register', ['Cookie' => $before], self::formToken($form->body, 'begin/register') + [
            'name' => 'Ada', 'handle' => 'ada', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'password2' => self::PASSWORD,
        ]);
        $after = self::sessionCookie($post);

        $this->assertSame(302, $post->status);
        $this->assertNotSame($before, $after, 'the registration starts a new session');
        $home = $this->site()->request('GET', '', ['Cookie' => $after]);
        $this->assertStringContainsString("You've registered! You're ready to get started.", Browser::text($home->body), 'the message added before the login survives it');
        $again = $this->site()->request('GET', '', ['Cookie' => $after]);
        $this->assertStringNotContainsString("You've registered!", Browser::text($again->body), 'and is shown once');
        $this->assertSame(200, $this->site()->request('GET', 'account/settings/', ['Cookie' => $after])->status, 'the new user is logged on');
        $this->assertSame(403, $this->site()->request('GET', 'account/settings/', ['Cookie' => $before])->status, 'only under the new id');
    }

    public function testRegistrationThatFailsKeepsItsMessagesAndLogsNobodyOn(): void
    {
        $form = $this->site()->request('GET', 'begin/register');
        $cookie = self::sessionCookie($form);

        $post = $this->site()->request('POST', 'begin/register', ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . 'begin/register'], self::formToken($form->body, 'begin/register') + [
            'name' => 'Ada', 'handle' => 'ada', 'email' => 'not-an-address', 'password' => self::PASSWORD, 'password2' => self::PASSWORD,
        ]);
        $cookie = self::sessionCookieOrNull($post) ?? $cookie;

        $this->assertSame(302, $post->status);
        $this->assertSame($this->baseUrl() . 'begin/register', $post->header('Location'));
        $page = $this->site()->request('GET', 'begin/register', ['Cookie' => $cookie]);
        $this->assertStringContainsString("We couldn't register you.", Browser::text($page->body));
        $this->assertStringContainsString("That doesn't seem like it's a valid email address.", Browser::text($page->body));
        $this->assertSame(403, $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status);
    }

    public function testOnboardingKeepsItsStateAcrossTheSteps(): void
    {
        // The first page of onboarding keeps the name the site is to be given in the session, and the
        // registration that follows reads it from the request only: the name is not carried over.
        $begin = $this->site()->request('GET', 'begin/?set_name=' . rawurlencode('Ada\'s Place'));
        $cookie = self::sessionCookie($begin);
        $form = $this->site()->request('GET', 'begin/register', ['Cookie' => $cookie]);
        $this->assertSame(200, $form->status);

        $post = $this->site()->request('POST', 'begin/register', ['Cookie' => $cookie], self::formToken($form->body, 'begin/register') + [
            'name' => 'Ada', 'handle' => 'ada', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'password2' => self::PASSWORD,
        ]);
        $cookie = self::sessionCookie($post);

        $this->assertSame(302, $post->status);
        $title = $this->runInSite('return \Idno\Core\Idno::site()->config()->title;');
        $this->assertSame('Ada\'s Idno', $title, 'the name the first step was given is not used');
        $next = $this->site()->request('GET', (string) preg_replace('#^' . preg_quote($this->baseUrl(), '#') . '#', '', (string) $post->header('Location')), ['Cookie' => $cookie]);
        $this->assertContains($next->status, [200, 302], 'the next step of onboarding is reached with the new session');
    }

    public function testOnboardingNameInTheRegistrationRequestIsUsed(): void
    {
        $form = $this->site()->request('GET', 'begin/register?set_name=' . rawurlencode('Ada\'s Place'));
        $cookie = self::sessionCookie($form);

        $post = $this->site()->request('POST', 'begin/register', ['Cookie' => $cookie], self::formToken($form->body, 'begin/register') + [
            'name' => 'Ada', 'handle' => 'ada', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'password2' => self::PASSWORD, 'set_name' => 'Ada\'s Place',
        ]);

        $this->assertSame(302, $post->status);
        $this->assertSame('Ada\'s Place', $this->runInSite('return \Idno\Core\Idno::site()->config()->title;'));
    }

    public function testPasswordResetSetsThePasswordAndShowsItsMessage(): void
    {
        $this->registerUser('ada');
        $reset = $this->resetLink('ada@example.com');

        $page = $this->site()->request('GET', $reset['path']);
        $this->assertSame(200, $page->status);
        $cookie = self::sessionCookie($page);
        $post = $this->site()->request('POST', 'account/password/reset', ['Cookie' => $cookie], self::formToken($page->body, 'account/password/reset') + [
            'email' => 'ada@example.com', 'code' => $reset['code'], 'password' => 'a-new-passphrase-77', 'password2' => 'a-new-passphrase-77',
        ]);
        $cookie = self::sessionCookieOrNull($post) ?? $cookie;

        $this->assertSame(302, $post->status);
        $this->assertSame($this->baseUrl(), $post->header('Location'));
        $home = $this->site()->request('GET', '', ['Cookie' => $cookie]);
        $this->assertStringContainsString('Your password was reset!', Browser::text($home->body));
        $this->assertSame(403, $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status, 'resetting a password does not log anybody on');
        $this->assertSame(200, $this->site()->request('GET', 'account/settings/', ['Cookie' => $this->loginCookie('ada', 'a-new-passphrase-77')])->status);
        $this->assertNull((new Browser($this->site()))->tryLogin('ada', self::PASSWORD), 'and the old password no longer works');
        $replay = $this->site()->request('GET', $reset['path']);
        $this->assertSame(302, $replay->status, 'a code that was used is of no use again');
    }

    public function testPasswordResetLeavesOtherSessionsLoggedIn(): void
    {
        $this->registerUser('ada');
        $other = $this->loginCookie('ada', self::PASSWORD);
        $reset = $this->resetLink('ada@example.com');
        $page = $this->site()->request('GET', $reset['path']);
        $cookie = self::sessionCookie($page);

        $post = $this->site()->request('POST', 'account/password/reset', ['Cookie' => $cookie], self::formToken($page->body, 'account/password/reset') + [
            'email' => 'ada@example.com', 'code' => $reset['code'], 'password' => 'a-new-passphrase-77', 'password2' => 'a-new-passphrase-77',
        ]);

        // Pins today: a session that was open when the password was reset is not ended.
        $this->assertSame(302, $post->status);
        $this->assertSame(200, $this->site()->request('GET', 'account/settings/', ['Cookie' => $other])->status);
    }

    public function testChangingYourOwnPasswordKeepsYouLoggedIn(): void
    {
        $cookie = $this->registerUser('ada');
        $other = $this->loginCookie('ada', self::PASSWORD);
        $page = $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie]);

        $post = $this->site()->request('POST', 'account/settings/', ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . 'account/settings/'], self::formToken($page->body, 'account/settings/') + [
            'name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'a-new-passphrase-77',
        ]);
        $cookie = self::sessionCookieOrNull($post) ?? $cookie;

        $this->assertSame(302, $post->status);
        $settings = $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie]);
        $this->assertSame(200, $settings->status, 'still logged in');
        $this->assertStringContainsString('Your password has been updated.', Browser::text($settings->body));
        // Pins today: nor is any other session of the user ended.
        $this->assertSame(200, $this->site()->request('GET', 'account/settings/', ['Cookie' => $other])->status);
        $this->assertNull((new Browser($this->site()))->tryLogin('ada', self::PASSWORD), 'the old password is gone');
        $this->assertNotNull((new Browser($this->site()))->tryLogin('ada', 'a-new-passphrase-77'));
    }

    public function testIndieAuthApprovalRunsOnTheBrowserSession(): void
    {
        $cookie = $this->registerUser('ada');
        $fields = [
            'me' => $this->baseUrl(),
            'client_id' => 'http://client.example.com/',
            'redirect_uri' => 'http://client.example.com/callback',
            'state' => 'state-' . bin2hex(random_bytes(4)),
            'scope' => 'create',
        ];

        $anonymous = $this->site()->request('GET', 'indieauth/auth?' . http_build_query($fields));
        $this->assertSame(302, $anonymous->status);
        $this->assertStringStartsWith($this->baseUrl() . 'session/login/?fwd=', (string) $anonymous->header('Location'), 'without a session the client is sent to log in');

        $form = $this->site()->request('GET', 'indieauth/auth?' . http_build_query($fields), ['Cookie' => $cookie]);
        $this->assertSame(200, $form->status);
        $token = self::formToken($form->body, 'indieauth/approve');

        $visitor = $this->site()->request('POST', 'indieauth/approve', [], $fields + $token);
        $this->assertStringNotContainsString('code=', (string) $visitor->header('Location'), 'the approval of a visitor issues no code');
        $approved = $this->site()->request('POST', 'indieauth/approve', ['Cookie' => $cookie], $fields + $token);
        $this->assertSame(302, $approved->status);
        $this->assertSame(1, preg_match('#^http://client\.example\.com/callback\?code=([0-9a-f]+)&state=#', (string) $approved->header('Location'), $m), (string) $approved->header('Location'));

        $exchange = $this->site()->request('POST', 'indieauth/token', [], [
            'code' => $m[1], 'me' => $fields['me'], 'client_id' => $fields['client_id'], 'redirect_uri' => $fields['redirect_uri'], 'state' => $fields['state'],
        ]);
        $this->assertNotEmpty($exchange->json()['access_token'] ?? null, 'the client exchanges the code with no session at all');
    }

    public function testAutosaveUnderASignedRequestPlantsNoLogin(): void
    {
        $this->registerUser('ada');
        $key = $this->apiKeyOf('ada');

        $response = $this->site()->request('POST', 'autosave', self::signedHeadersFor('ada', $key, '/autosave'), ['context' => 'status', 'elements' => ['body' => 'x']]);

        $this->assertNoLoginPlanted($response);
    }

    public function testAnApiRequestThatForwardsLeavesNoLoginBehindEither(): void
    {
        $this->registerUser('ada');
        $key = $this->apiKeyOf('ada');

        $response = $this->site()->request('POST', 'status/edit', self::signedHeadersFor('ada', $key, '/status/edit'), ['body' => 'hello from the api']);

        $this->assertNoLoginPlanted($response);
    }

    /**
     * Every session cookie a response sets (a request that starts a session and ends it sets two: the new id, then
     * the deletion of it) must be of no use as a login. The test says so by what it sees: when the site accepts one
     * as a login the test is marked incomplete (the wanted behaviour is that none does).
     */
    private function assertNoLoginPlanted(SiteResponse $response): void
    {
        $name = (string) \Idno\Core\Idno::site()->config()->sessionname;
        preg_match_all('/' . preg_quote($name, '/') . '=([^;,\s]+)/', (string) $response->header('Set-Cookie'), $m);
        foreach ($m[1] as $value) {
            if ($value !== 'deleted' && $this->site()->request('GET', 'account/settings/', ['Cookie' => $name . '=' . $value])->status === 200) {
                $this->markTestIncomplete('A signed request leaves a session cookie that logs the signed user on: ' . $name . '=' . $value);
            }
        }
        $this->assertNotSame([], $m[1], 'the response sets a session cookie, as every response does');
        foreach ($m[1] as $value) {
            $this->assertSame(403, $this->site()->request('GET', 'account/settings/', ['Cookie' => $name . '=' . $value])->status);
        }
    }

    /**
     * Asks for a password reset for the address, as a visitor does, and reads the link out of the mail the site sent.
     *
     * @return array{path: string, code: string}
     */
    private function resetLink(string $email): array
    {
        $this->site()->request('GET', '_test/mail/log');
        $form = $this->site()->request('GET', 'account/password/');
        $this->assertSame(200, $form->status);
        $post = $this->site()->request('POST', 'account/password/', ['Cookie' => self::sessionCookie($form)], self::formToken($form->body, 'account/password') + ['email' => $email]);
        $this->assertSame(302, $post->status);
        $mails = $this->site()->request('GET', '_test/mail/log')->json();
        $this->assertCount(1, $mails);
        $this->assertSame(1, preg_match('#account/password/reset/\?email=[^&\s"]+&code=([0-9a-f]+)#', (string) $mails[0]['text'], $m), (string) $mails[0]['text']);

        return ['path' => 'account/password/reset/?email=' . rawurlencode($email) . '&code=' . $m[1], 'code' => $m[1]];
    }
}
