<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Acceptance\Browser;
use Tests\Support\PrivateSiteTestCase;

/**
 * Failed logins are counted for each login name (five in five minutes) and the sixth attempt is refused before the
 * password is looked at. Each test has a site of its own, and so a cache pool of its own (PrivateSiteTestCase gives each site a `cachepath`): no count leaks from one
 * test to another, or onto the rig's shared site.
 */
final class LoginThrottlingTest extends PrivateSiteTestCase
{
    private const PASSWORD = 'correct-horse-battery-9';

    public function testSixthFailureIsRefusedEvenWithTheRightPassword(): void
    {
        $this->registerUser('ada');

        for ($i = 0; $i < 5; $i++) {
            [, $page] = $this->attempt('ada', 'not the password');
            $this->assertStringContainsString("Oops! That username or password isn't right. Please try again.", Browser::text($page->body), 'attempt ' . ($i + 1));
        }
        [$post, $page] = $this->attempt('ada', self::PASSWORD);

        $this->assertSame(302, $post->status);
        $this->assertStringContainsString('/session/login/?fwd=', (string) $post->header('Location'), 'the same redirect as a failure');
        $this->assertStringContainsString('Too many failed sign-in attempts', Browser::text($page->body));
        $this->assertStringNotContainsString('signed in', Browser::text($page->body));
        $cookie = self::sessionCookieOrNull($post);
        $this->assertSame(403, $this->site()->request('GET', 'account/settings/', $cookie === null ? [] : ['Cookie' => $cookie])->status, 'the right password did not log in');
    }

    public function testTheLoginNameIsTheKeyNotTheCase(): void
    {
        $this->registerUser('ada');
        foreach (['ada', 'ADA', 'Ada', 'aDa', 'ada'] as $typed) {
            $this->attempt($typed, 'not the password');
        }

        [, $page] = $this->attempt('ada', self::PASSWORD);

        $this->assertStringContainsString('Too many failed sign-in attempts', Browser::text($page->body));
    }

    public function testSuccessResetsTheCount(): void
    {
        $this->registerUser('ada');
        for ($i = 0; $i < 4; $i++) {
            $this->attempt('ada', 'not the password');
        }
        $this->assertNotSame('', $this->loginCookie('ada', self::PASSWORD), 'the fifth attempt is allowed and right');

        for ($i = 0; $i < 4; $i++) {
            $this->attempt('ada', 'not the password');
        }

        $this->assertNotSame('', $this->loginCookie('ada', self::PASSWORD), 'the count began again after the success');
    }

    public function testOtherLoginsAreUnaffectedAndAnUnknownNameIsCountedToo(): void
    {
        $this->registerUser('ada');
        $this->addUser('grace');
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('ada', 'not the password');
            [, $unknownPage] = $this->attempt('nobody-here', 'not the password');
            $this->assertStringContainsString("Oops! That username or password isn't right.", Browser::text($unknownPage->body), 'an unknown name gets the message a wrong password gets');
        }

        [, $unknown] = $this->attempt('nobody-here', self::PASSWORD);
        [, $adaByEmail] = $this->attempt('ada@example.com', self::PASSWORD);

        $this->assertStringContainsString('Too many failed sign-in attempts', Browser::text($unknown->body), 'the same message for a name nobody has');
        $this->assertStringContainsString('Too many failed sign-in attempts', Browser::text($adaByEmail->body), 'the handle and the email address of one account share a count');
        $this->assertNotSame('', $this->loginCookie('grace', self::PASSWORD));
    }

    public function testAnAccountsSpellingsShareOneCountOnADatabaseThatFoldsAccentsAndCase(): void
    {
        if (!in_array(\Idno\Storage\ConnectionParameters::driverFamily(\Idno\Core\Idno::container()->get(\Idno\Config\BootConfig::class)->all()), ['mysql'], true)) {
            $this->markTestSkipped('Only MySQL and MariaDB compare handles ignoring accents and case.');
        }
        $this->registerUser('ben');
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('ben', 'not the password');
        }

        foreach (["b\u{e9}n", "B\u{ca}N", 'BEN', 'ben@example.com'] as $spelling) {
            [, $page] = $this->attempt($spelling, self::PASSWORD);
            $this->assertStringContainsString('Too many failed sign-in attempts', Browser::text($page->body), $spelling);
        }
    }

    public function testAnUnknownNameIsFoldedForTheCount(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt($i % 2 === 0 ? 'Nobody' : ' NOBODY ', 'x');
        }

        [, $page] = $this->attempt('nobody', 'x');

        $this->assertStringContainsString('Too many failed sign-in attempts', Browser::text($page->body));
    }

    public function testAParallelBurstCannotGetMoreGuessesThanTheLimit(): void
    {
        $this->registerUser('ada');
        $form = $this->site()->request('GET', 'session/login');
        $cookie = self::sessionCookie($form);
        $fields = self::formToken($form->body, 'session/login') + ['email' => 'ada', 'password' => 'not the password'];
        $accepted = 0;

        // The attempt is reserved before the password is checked: after five, none is checked, whatever is in flight.
        for ($i = 0; $i < 9; $i++) {
            $post = $this->site()->request('POST', 'session/login', ['Cookie' => $cookie], $fields);
            $page = $this->site()->request('GET', substr((string) $post->header('Location'), strlen($this->baseUrl())), ['Cookie' => self::sessionCookieOrNull($post) ?? $cookie]);
            $accepted += str_contains(Browser::text($page->body), "That username or password isn't right") ? 1 : 0;
        }

        $this->assertSame(5, $accepted);
    }

    public function testAPasswordResetClearsTheAccountsCount(): void
    {
        $this->registerUser('ada');
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('ada', 'not the password');
        }
        [, $locked] = $this->attempt('ada', self::PASSWORD);
        $this->assertStringContainsString('Too many failed sign-in attempts', Browser::text($locked->body));

        $form = $this->site()->request('GET', 'account/password/');
        $this->site()->request('POST', 'account/password/', ['Cookie' => self::sessionCookie($form)], self::formToken($form->body, 'account/password') + ['email' => 'ada@example.com']);
        $mine = array_values(array_filter($this->site()->request('GET', '_test/mail/log')->json(), static fn (array $m): bool => str_contains(json_encode($m['to'] ?? []), 'ada@example.com')));
        $this->assertSame(1, preg_match('#account/password/reset/\?email=[^&\s"]+&code=([0-9a-f]+)#', (string) end($mine)['text'], $m));
        $page = $this->site()->request('GET', 'account/password/reset/?email=' . rawurlencode('ada@example.com') . '&code=' . $m[1]);
        $this->site()->request('POST', 'account/password/reset', ['Cookie' => self::sessionCookie($page)], self::formToken($page->body, 'account/password/reset') + [
            'email' => 'ada@example.com', 'code' => $m[1], 'password' => 'a-new-passphrase-77', 'password2' => 'a-new-passphrase-77',
        ]);

        $this->assertNotSame('', $this->loginCookie('ada', 'a-new-passphrase-77'), 'the member signs in at once');
    }

    public function testJsonLoginIsThrottledToo(): void
    {
        $this->registerUser('ada');
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('ada', 'not the password', '?_t=json');
        }

        [$post, $page] = $this->attempt('ada', self::PASSWORD, '?_t=json');

        $this->assertSame(302, $post->status);
        $this->assertStringContainsString('_t=json', (string) $post->header('Location'));
        $messages = json_encode($page->json());
        $this->assertStringContainsString('Too many failed sign-in attempts', (string) $messages);
        $this->assertArrayNotHasKey('api-token', $page->json());
    }

    public function testTheJsonLoginSaysTheSameForAWrongPasswordAndAnUnknownName(): void
    {
        $this->registerUser('ada');

        [, $wrong] = $this->attempt('ada', 'not the password', '?_t=json');
        [, $unknown] = $this->attempt('nobody-here', 'not the password', '?_t=json');

        $this->assertStringContainsString("That username or password isn't right.", (string) json_encode($wrong->json()));
        $this->assertStringContainsString("That username or password isn't right.", (string) json_encode($unknown->json()));
        $this->assertStringNotContainsString("couldn't find", (string) json_encode($unknown->json()));
    }

    /**
     * Posts the login form as a browser would and loads the page the answer redirects to, with the session that started.
     *
     * @return array{0: SiteResponse, 1: SiteResponse}
     */
    private function attempt(string $login, string $password, string $query = ''): array
    {
        $form = $this->site()->request('GET', 'session/login');
        $cookie = self::sessionCookie($form);
        $post = $this->site()->request('POST', 'session/login' . $query, ['Cookie' => $cookie], self::formToken($form->body, 'session/login') + ['email' => $login, 'password' => $password]);
        $cookie = self::sessionCookieOrNull($post) ?? $cookie;
        $location = (string) $post->header('Location');
        $page = $this->site()->request('GET', substr($location, strlen($this->baseUrl())), ['Cookie' => $cookie]);

        return [$post, $page];
    }
}
