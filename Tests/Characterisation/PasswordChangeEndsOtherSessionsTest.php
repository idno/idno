<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * A password change ends the user's other sessions. A session carries a fingerprint of the user's password marker (a
 * random value only a real change of the password writes, never the replacement of the same password's hash by a new
 * one), taken at login and checked where the session's user is loaded. Each is a walk through the real pages over HTTP.
 */
final class PasswordChangeEndsOtherSessionsTest extends PrivateSiteTestCase
{
    private const PASSWORD = 'correct-horse-battery-9';
    private const NEW_PASSWORD = 'a-new-passphrase-77';

    public function testChangingYourPasswordEndsTheOtherSessionsAndKeepsThisOne(): void
    {
        $cookie = $this->registerUser('ada');
        $other = $this->loginCookie('ada', self::PASSWORD);
        $third = $this->loginCookie('ada', self::PASSWORD);
        $this->assertSame(200, $this->settings($other)->status);

        $page = $this->settings($cookie);
        $post = $this->site()->request('POST', 'account/settings/', ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . 'account/settings/'], self::formToken($page->body, 'account/settings/') + [
            'name' => 'Ada', 'email' => 'ada@example.com', 'current_password' => self::PASSWORD, 'password' => self::NEW_PASSWORD,
        ]);
        $cookie = self::sessionCookieOrNull($post) ?? $cookie;

        $this->assertSame(302, $post->status);
        $this->assertSame(200, $this->settings($cookie)->status, 'the session that made the change stays logged in');
        $this->assertSame(200, $this->settings($cookie)->status, 'and on the request after');
        $this->assertSame(403, $this->settings($other)->status, 'another session is over');
        $this->assertSame(403, $this->settings($third)->status, 'every other session is over');
        $this->assertSame(403, $this->settings($other)->status, 'and stays over');
    }

    public function testAChangeThatLeavesThePasswordAloneEndsNothing(): void
    {
        $cookie = $this->registerUser('ada');
        $other = $this->loginCookie('ada', self::PASSWORD);

        $page = $this->settings($cookie);
        $post = $this->site()->request('POST', 'account/settings/', ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . 'account/settings/'], self::formToken($page->body, 'account/settings/') + [
            'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'password' => '',
        ]);

        $this->assertSame(302, $post->status);
        $this->assertSame(200, $this->settings($other)->status, 'a name change is not a password change');
        $this->assertSame(200, $this->settings($cookie)->status);
    }

    public function testAResetThroughTheMailLinkEndsEverySession(): void
    {
        $this->registerUser('ada');
        $one = $this->loginCookie('ada', self::PASSWORD);
        $two = $this->loginCookie('ada', self::PASSWORD);
        $reset = $this->resetLink('ada@example.com');
        $page = $this->site()->request('GET', $reset['path']);
        $cookie = self::sessionCookie($page);

        $post = $this->site()->request('POST', 'account/password/reset', ['Cookie' => $cookie], self::formToken($page->body, 'account/password/reset') + [
            'email' => 'ada@example.com', 'code' => $reset['code'], 'password' => self::NEW_PASSWORD, 'password2' => self::NEW_PASSWORD,
        ]);

        $this->assertSame(302, $post->status);
        $this->assertSame(403, $this->settings($one)->status);
        $this->assertSame(403, $this->settings($two)->status);
        $this->assertSame(200, $this->settings($this->loginCookie('ada', self::NEW_PASSWORD))->status, 'a login with the new password works');
    }

    public function testASessionFromBeforeTheFingerprintStaysLoggedInWhenTheUserHasNoMarkerAndIsThenBoundToIt(): void
    {
        $this->addUserWithoutMarker('old');
        $session = $this->sessionWithoutFingerprint('old');
        $this->assertSame(200, $this->settings($session)->status, 'adopted: the user has never changed the password since markers began');
        $this->assertSame(200, $this->settings($session)->status);

        $this->runInSite('$u = \Idno\Entities\User::getByHandle("old"); $u->setPassword("a-new-passphrase-77"); $u->save(true); return true;');

        $this->assertSame(403, $this->settings($session)->status, 'once adopted it is bound as every other session is');
    }

    public function testASessionFromBeforeTheFingerprintOfAUserWhoHasAMarkerIsEnded(): void
    {
        $this->registerUser('ada');
        $stolen = $this->sessionWithoutFingerprint('ada');

        $this->assertSame(403, $this->settings($stolen)->status, 'it cannot say it came after the last change of the password, so it is over');
    }

    public function testAnEndedSessionLeavesTheBrowserWithAFreshSessionAMessageAndALoginFormThatWorks(): void
    {
        $this->registerUser('ada');
        $old = $this->loginCookie('ada', self::PASSWORD);
        $this->runInSite('$u = \Idno\Entities\User::getByHandle("ada"); $u->setPassword("a-new-passphrase-77"); $u->save(true); return true;');

        $page = $this->site()->request('GET', 'session/login', ['Cookie' => $old]);
        $fresh = self::sessionCookie($page);

        $this->assertSame(200, $page->status);
        $this->assertNotSame($old, $fresh, 'a new session');
        $this->assertStringContainsString('Your password was changed, so you have been signed out here. Please sign in again.', \Tests\Acceptance\Browser::text($page->body));
        $login = $this->site()->request('POST', 'session/login', ['Cookie' => $fresh], self::formToken($page->body, 'session/login') + ['email' => 'ada', 'password' => 'a-new-passphrase-77']);
        $this->assertSame(302, $login->status);
        $this->assertSame(200, $this->settings(self::sessionCookieOrNull($login) ?? $fresh)->status, 'the form of the page the old browser landed on logs in');
        $again = $this->site()->request('GET', 'session/login', ['Cookie' => $old]);
        $this->assertStringNotContainsString('Your password was changed', \Tests\Acceptance\Browser::text($this->site()->request('GET', '', ['Cookie' => self::sessionCookie($again)])->body), 'the message is shown once');
    }

    public function testAnAutosaveAfterAChangeOfPasswordDoesNotRevertIt(): void
    {
        $a = $this->registerUser('ada');
        $b = $this->loginCookie('ada', self::PASSWORD);
        $page = $this->settings($b);
        $this->runInSite('$u = \Idno\Entities\User::getByHandle("ada"); $u->setPassword("a-new-passphrase-77"); $u->save(true); return true;');

        $this->site()->request('POST', 'autosave', ['Cookie' => $a, 'X-Requested-With' => 'XMLHttpRequest'], ['context' => 'status', 'elements' => ['body' => 'a draft']]);

        $this->assertSame(403, $this->settings($a)->status, 'the session that did not change the password is over');
        $this->assertNotSame('', $this->loginCookie('ada', 'a-new-passphrase-77'), 'and the new password still works');
        $this->assertNotSame('', $page->body);
    }

    public function testTheHashUpgradeAtLoginDoesNotEndTheOtherSessions(): void
    {
        $this->registerUser('ada');
        $first = $this->loginCookie('ada', self::PASSWORD);
        // A hash made at a lower cost than the site's own: the next login replaces it by a new hash of the SAME password.
        $this->setStoredHash('ada', self::PASSWORD, 4);
        $this->assertTrue($this->hashNeedsUpgrade('ada'));

        $second = $this->loginCookie('ada', self::PASSWORD);

        $this->assertFalse($this->hashNeedsUpgrade('ada'), 'the login upgraded the stored hash');
        $this->assertSame(200, $this->settings($second)->status);
        $this->assertSame(200, $this->settings($first)->status, 'the upgrade of the same password ended nothing');
    }

    public function testAStatelessRequestIsUnaffectedByAPasswordChange(): void
    {
        $cookie = $this->registerUser('ada');
        $key = $this->apiKeyOf('ada');
        $page = $this->settings($cookie);
        $this->site()->request('POST', 'account/settings/', ['Cookie' => $cookie, 'Referer' => $this->baseUrl() . 'account/settings/'], self::formToken($page->body, 'account/settings/') + [
            'name' => 'Ada', 'email' => 'ada@example.com', 'current_password' => self::PASSWORD, 'password' => self::NEW_PASSWORD,
        ]);

        $response = $this->site()->request('GET', 'currentUser?_t=json', self::signedHeadersFor('ada', $key, '/currentUser?_t=json'));

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('ada', json_encode($response->json()));
    }

    public function testNothingOfThePasswordHashIsInTheSession(): void
    {
        $this->registerUser('ada');
        $cookie = $this->loginCookie('ada', self::PASSWORD);
        $stored = (string) $this->runInSite('return \Idno\Entities\User::getByHandle("ada")->password;');
        $id = substr($cookie, strpos($cookie, '=') + 1);

        $session = $this->runInSite('
            session_write_close();
            session_id(' . $this->literal($id) . ');
            session_start();
            return $_SESSION;
        ');

        $this->assertIsArray($session);
        $this->assertStringNotContainsString($stored, json_encode($session));
        $this->assertStringNotContainsString(hash('sha256', $stored), json_encode($session));
        $this->assertArrayHasKey('password_fingerprint', $session);
    }

    // ------------------------------------------------------------------ helpers

    private function settings(string $cookie): SiteResponse
    {
        return $this->site()->request('GET', 'account/settings/', ['Cookie' => $cookie]);
    }

    /**
     * A session of the user that a release without the fingerprint would have made: logged on, and nothing more. Made in a
     * process of the site that writes a session through the site's own storage, then used over HTTP.
     */
    private function sessionWithoutFingerprint(string $handle): string
    {
        $id = $this->runInSite('
            $user = \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ');
            $_SESSION["user_uuid"] = $user->getUUID();
            unset($_SESSION["password_fingerprint"]);
            $id = session_id();
            session_write_close();
            return $id;
        ');
        $this->assertIsString($id);
        $this->assertNotSame('', $id);

        return (string) \Idno\Core\Idno::site()->config()->sessionname . '=' . $id;
    }

    private function addUserWithoutMarker(string $handle): void
    {
        $hash = (new \Idno\Security\Passwords())->hash(self::PASSWORD);
        $this->runInSite(
            '$u = new \Idno\Entities\User(); $u->handle = ' . $this->literal($handle) . '; $u->email = ' . $this->literal($handle . '@example.com')
            . '; $u->setTitle("Old"); $u->setPasswordHash(' . $this->literal($hash) . '); $u->save(true); return true;'
        );
    }

    private function setStoredHash(string $handle, string $password, int $cost): void
    {
        $this->runInSite('
            $user = \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ');
            $user->setPasswordHash(password_hash(' . $this->literal($password) . ', PASSWORD_BCRYPT, ["cost" => ' . $cost . ']));
            $user->save(true);
            return true;
        ');
    }

    private function hashNeedsUpgrade(string $handle): bool
    {
        return (bool) $this->runInSite('
            $user = \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ');
            return (new \Idno\Security\Passwords())->needsRehash((string) $user->password);
        ');
    }

    /**
     * @return array{path: string, code: string}
     */
    private function resetLink(string $email): array
    {
        $form = $this->site()->request('GET', 'account/password/');
        $this->assertSame(200, $form->status);
        $post = $this->site()->request('POST', 'account/password/', ['Cookie' => self::sessionCookie($form)], self::formToken($form->body, 'account/password') + ['email' => $email]);
        $this->assertSame(302, $post->status);
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
