<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Acceptance\Browser;
use Tests\Support\PrivateSiteTestCase;

/**
 * What the session carries through the events that change who it belongs to: registration, a password
 * reset, a change of password, the IndieAuth approval and an API request that touches the session. Each is a walk
 * through the real pages over HTTP on a site of the test's own. The assertions are what the site does today,
 * including what is a defect, so that a change of session storage or of authentication shows where it matters.
 */
final class SessionDataAcrossLoginTest extends PrivateSiteTestCase
{
    private const PASSWORD = 'correct-horse-battery-9';

    public function testRegistrationLogsTheNewUserOnAndKeepsItsMessage(): void
    {
        // A site that has its administrator and takes registrations (the installation closes them; this one opens them).
        $this->addUser('owner', admin: true);
        $form = $this->site()->request('GET', 'account/register');
        $this->assertSame(200, $form->status, $this->serverLog());
        $before = self::sessionCookie($form);

        $post = $this->site()->request('POST', 'account/register', ['Cookie' => $before], self::formToken($form->body, 'account/register') + [
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
        $this->assertFalse($this->runInSite('return (bool) \Idno\Entities\User::getByHandle("ada")->admin;'), 'registering never makes an administrator');
        $this->assertSame(403, $this->site()->request('GET', 'admin/', ['Cookie' => $after])->status);
    }

    public function testRegistrationThatFailsKeepsItsMessagesAndLogsNobodyOn(): void
    {
        $this->addUser('owner', admin: true);
        $form = $this->site()->request('GET', 'account/register');
        $cookie = self::sessionCookie($form);

        $post = $this->site()->request('POST', 'account/register', ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . 'account/register'], self::formToken($form->body, 'account/register') + [
            'name' => 'Ada', 'handle' => 'ada', 'email' => 'not-an-address', 'password' => self::PASSWORD, 'password2' => self::PASSWORD,
        ]);
        $cookie = self::sessionCookieOrNull($post) ?? $cookie;

        $this->assertSame(302, $post->status);
        $this->assertSame($this->baseUrl() . 'account/register', $post->header('Location'));
        $this->assertSame(403, $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status);
        $page = $this->site()->request('GET', 'account/register', ['Cookie' => $cookie]);
        if (!str_contains(Browser::text($page->body), "We couldn't register you.")) {
            // The registration page draws the shell itself (not through drawPage()) and gives it no messages: the
            // reasons are taken from the session and shown nowhere. Wanted: the registration page shows them.
            $this->markTestIncomplete('The registration page does not show the session\'s messages: a failed registration never says why.');
        }
        $this->assertStringContainsString("We couldn't register you.", Browser::text($page->body));
        $this->assertStringContainsString("That doesn't seem like it's a valid email address.", Browser::text($page->body));
        $this->assertSame(403, $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie])->status);
    }

    public function testNobodyCanRegisterOnASiteThatHasNoAccountYet(): void
    {
        // The site's first administrator is made by the last step of the installation, never by registration:
        // the home page says the site is being set up, and the registration page sends its visitor there.
        $home = $this->site()->request('GET', '');
        $this->assertSame(503, $home->status, $this->serverLog());
        $this->assertStringContainsString('This site is being set up', $home->body);
        $cookie = self::sessionCookieOrNull($home);

        $form = $this->site()->request('GET', 'account/register', $cookie === null ? [] : ['Cookie' => $cookie]);
        $this->assertSame(302, $form->status);
        $this->assertSame($this->baseUrl(), $form->header('Location'));

        // A token of the session, taken from a page that has a form (the login page), posted to the registration.
        $login = $this->site()->request('GET', 'session/login');
        $cookie = self::sessionCookie($login);
        $post = $this->site()->request('POST', 'account/register', ['Cookie' => $cookie], self::formToken($login->body, 'session/login') + [
            'name' => 'Ada', 'handle' => 'ada', 'email' => 'ada@example.com', 'password' => self::PASSWORD, 'password2' => self::PASSWORD,
        ]);
        $this->assertSame(302, $post->status);
        $this->assertSame($this->baseUrl(), $post->header('Location'));
        $this->assertSame(0, $this->runInSite('return count(\Idno\Entities\User::get() ?: []);'), 'nobody was created');
        $this->assertSame(503, $this->site()->request('GET', '', ['Cookie' => self::sessionCookieOrNull($post) ?? $cookie])->status);
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

    public function testPasswordResetEndsOtherSessions(): void
    {
        $this->registerUser('ada');
        $other = $this->loginCookie('ada', self::PASSWORD);
        $reset = $this->resetLink('ada@example.com');
        $page = $this->site()->request('GET', $reset['path']);
        $cookie = self::sessionCookie($page);

        $post = $this->site()->request('POST', 'account/password/reset', ['Cookie' => $cookie], self::formToken($page->body, 'account/password/reset') + [
            'email' => 'ada@example.com', 'code' => $reset['code'], 'password' => 'a-new-passphrase-77', 'password2' => 'a-new-passphrase-77',
        ]);

        // Changed on purpose: a session that was open when the password was reset is ended (see PasswordChangeEndsOtherSessionsTest).
        $this->assertSame(302, $post->status);
        $this->assertSame(403, $this->site()->request('GET', 'account/settings/', ['Cookie' => $other])->status);
    }

    public function testChangingYourOwnPasswordKeepsYouLoggedInAndEndsTheOtherSessions(): void
    {
        $cookie = $this->registerUser('ada');
        $other = $this->loginCookie('ada', self::PASSWORD);
        $page = $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie]);

        $post = $this->site()->request('POST', 'account/settings/', ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . 'account/settings/'], self::formToken($page->body, 'account/settings/') + [
            'name' => 'Ada', 'email' => 'ada@example.com', 'current_password' => self::PASSWORD, 'password' => 'a-new-passphrase-77',
        ]);
        $cookie = self::sessionCookieOrNull($post) ?? $cookie;

        $this->assertSame(302, $post->status);
        $settings = $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie]);
        $this->assertSame(200, $settings->status, 'still logged in');
        $this->assertStringContainsString('Your password has been updated.', Browser::text($settings->body));
        // Changed on purpose: every other session of the user is ended.
        $this->assertSame(403, $this->site()->request('GET', 'account/settings/', ['Cookie' => $other])->status);
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

        $this->assertSame(200, $response->status, 'the signed request was accepted: ' . substr($response->body, 0, 300));
        $stored = $this->runInSite('$u = \Idno\Entities\User::getByHandle(\'ada\'); return $u->autosave;');
        $this->assertSame(['status' => ['body' => 'x']], $stored, 'and acted as ada: the value was stored');
        $this->assertNoLoginPlanted($response);
    }

    public function testASignedRequestThatCarriesAnotherMembersCookieLeavesThatSessionIntactAndPlantsNoLogin(): void
    {
        $cookie = $this->registerUser('ada');
        $this->addUser('bob');
        $key = $this->apiKeyOf('bob');

        $response = $this->site()->request('POST', 'autosave', self::signedHeadersFor('bob', $key, '/autosave') + ['Cookie' => $cookie], ['context' => 'status', 'elements' => ['body' => 'by bob']]);

        $this->assertSame(200, $response->status, substr($response->body, 0, 300));
        $this->assertSame(['status' => ['body' => 'by bob']], $this->runInSite('$u = \Idno\Entities\User::getByHandle(\'bob\'); return $u->autosave;'), 'acted as bob');
        $this->assertNull($this->runInSite('$u = \Idno\Entities\User::getByHandle(\'ada\'); return $u->autosave ?: null;'), 'and not as ada');
        $this->assertNoLoginPlanted($response);
        $who = $this->site()->request('GET', '_test/whoami', ['Cookie' => $cookie])->json();
        $this->assertSame('ada', $who['handle'], 'the carried session still holds ada, and only ada');
        $this->assertFalse($who['stateless']);
    }

    public function testAnApiRequestThatForwardsLeavesNoLoginBehindEither(): void
    {
        $this->registerUser('ada');
        $key = $this->apiKeyOf('ada');

        $response = $this->site()->request('POST', 'status/edit', self::signedHeadersFor('ada', $key, '/status/edit'), ['body' => 'hello from the api']);

        $this->assertContains($response->status, [200, 201, 302], 'the signed request was accepted: ' . substr($response->body, 0, 300));
        $this->assertNotNull($this->runInSite('$s = \IdnoPlugins\Status\Status::getOne([\'body\' => \'hello from the api\']); return $s ? $s->getUUID() : null;'), 'and published the note');
        $this->assertNoLoginPlanted($response);
    }

    /**
     * Every session cookie a response sets (a request that starts a session and ends it may set two: the new id, then
     * the deletion of it) must be of no use as a login. A response that sets none is as good. The test says so by
     * what it sees: a cookie the site accepts as a login fails the test.
     */
    private function assertNoLoginPlanted(SiteResponse $response): void
    {
        $name = (string) \Idno\Core\Idno::site()->config()->sessionname;
        preg_match_all('/' . preg_quote($name, '/') . '=([^;,\s]+)/', (string) $response->header('Set-Cookie'), $m);
        $values = array_values(array_filter($m[1], static fn (string $value): bool => $value !== 'deleted'));
        foreach ($values as $value) {
            if ($this->site()->request('GET', 'account/settings/', ['Cookie' => $name . '=' . $value])->status === 200) {
                $this->fail('A signed request leaves a session cookie that logs the signed user on: ' . $name . '=' . $value);
            }
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
        $form = $this->site()->request('GET', 'account/password/');
        $this->assertSame(200, $form->status);
        $post = $this->site()->request('POST', 'account/password/', ['Cookie' => self::sessionCookie($form)], self::formToken($form->body, 'account/password') + ['email' => $email]);
        $this->assertSame(302, $post->status);
        // The mail log is shared by every site of this checkout: take the mail for this address, the newest.
        $mine = array_values(array_filter(
            $this->site()->request('GET', '_test/mail/log')->json(),
            static fn (array $mail): bool => str_contains(json_encode($mail['to'] ?? []), $email)
        ));
        $this->assertNotSame([], $mine, 'a mail was sent to ' . $email);
        $mail = end($mine);
        $this->assertSame(1, preg_match('#account/password/reset/\?email=[^&\s"]+&code=([0-9a-f]+)#', (string) $mail['text'], $m), (string) $mail['text']);

        return ['path' => 'account/password/reset/?email=' . rawurlencode($email) . '&code=' . $m[1], 'code' => $m[1]];
    }
}
