<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the login page, the current-user page and the logout answer to a visitor of a private site (`walled_garden`), recorded from the legacy pages over
 * HTTP: the answers are `Tests/Characterisation/fixtures/answers/sign-in-walled/`. The login page is registered as public, so a visitor is answered as
 * on an open site; the other two are not. The seeded site is `ProfileSiteCase`'s.
 */
final class SignInWalledAnswersTest extends SignInSiteCase
{
    use RecordedAnswers;

    protected const SHARED_SITE = true;

    protected const GROUP = 'sign-in-walled';

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_WALLED_GARDEN' => '1'];
    }

    /** The answer without the form token it was asked with, which is a secret of the session. */
    private static function withoutToken(string $token): \Closure
    {
        $swap = static fn (string $text): string => str_replace($token, '{form-token}', $text);

        return static fn (SiteResponse $r): SiteResponse => new SiteResponse($r->status, array_map($swap, $r->headers), $swap($r->body), array_map($swap, $r->lines));
    }

    public function testAVisitorOfAPrivateSite(): void
    {
        $this->record('login-visitor-html', 'GET', '/session/login');
        $this->record('login-visitor-json', 'GET', '/session/login?_t=json');
        $this->record('current-user-visitor-html', 'GET', '/currentUser');
        $this->record('current-user-visitor-json', 'GET', '/currentUser?_t=json');
        $this->addToAssertionCount(1);
    }

    public function testALogoutByAVisitorOfAPrivateSite(): void
    {
        foreach (range(1, static::passes()) as $_) {
            $form = $this->site()->request('GET', '/session/login');
            $this->assertSame(200, $form->status);
            $cookie = self::sessionCookie($form);
            $token = self::formToken($form->body, 'session/login')['__bTk'];
            $answer = $this->site()->request('POST', '/session/logout', ['Cookie' => $cookie], ['__bTk' => $token]);
            $this->assertRecordedAnswer(self::GROUP, 'logout-post-visitor', self::withoutToken($token)($answer));
        }
        $this->addToAssertionCount(1);
    }

    public function testAVisitorsLoginsOnAPrivateSite(): void
    {
        foreach (range(1, static::passes()) as $_) {
            $this->clearTheThrottle();
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            $wrong = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => 'not the password']);
            $this->assertRecordedAnswer(self::GROUP, 'login-post-wrong', $s($wrong));
            $this->assertRecordedAnswer(self::GROUP, 'login-post-wrong-then', $s($this->follow($wrong, $cookie)));
            $right = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => self::PASSWORD]);
            $this->assertRecordedAnswer(self::GROUP, 'login-post-right', $s($right));
            $then = $this->follow($right, $cookie);
            $this->assertRecordedAnswer(self::GROUP, 'login-post-right-then', $s($then));
            $this->assertRecordedAnswer(self::GROUP, 'login-post-right-user', $s($this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => self::sessionCookieOrNull($right) ?? $cookie])));
        }
        $this->clearTheThrottle();
        $this->addToAssertionCount(1);
    }

    public function testSignedRequestsAndMembersOfAPrivateSite(): void
    {
        $s = $this->scrubViewers();
        $this->record('login-visitor-markdown', 'GET', '/session/login?_t=markdown', [], 'visitor', null, $s);
        $this->record('login-signed-json', 'GET', '/session/login?_t=json', self::signedHeadersFor('bob', self::KEY_BOB, '/session/login?_t=json'), 'visitor', null, $s);
        $this->record('current-user-signed-json', 'GET', '/currentUser?_t=json', self::signedHeadersFor('bob', self::KEY_BOB, '/currentUser?_t=json'), 'visitor', null, $s);
        $this->record('login-bob-html', 'GET', '/session/login', [], 'owner', null, $s);
        $this->record('login-bob-json', 'GET', '/session/login?_t=json', [], 'owner', null, $s);
        $this->record('current-user-bob-html', 'GET', '/currentUser', [], 'owner', null, $s);
        $this->record('current-user-bob-json', 'GET', '/currentUser?_t=json', [], 'owner', null, $s);
        $this->addToAssertionCount(1);
    }
}

