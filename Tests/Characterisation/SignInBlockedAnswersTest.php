<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\RecordedAnswers;

/**
 * What the login does for a member whose email address the site blocks, recorded from the legacy page over HTTP on a site of the test's own (the
 * blocklist is a setting): a login with the right password, and a session the member had before the address was blocked. The answers are
 * `Tests/Characterisation/fixtures/answers/sign-in-blocked/`.
 */
final class SignInBlockedAnswersTest extends SignInSiteCase
{
    use RecordedAnswers;

    protected const GROUP = 'sign-in-blocked';

    public static function tearDownAfterClass(): void
    {
        try {
            self::assertRecordedAnswersComplete();
        } finally {
            parent::tearDownAfterClass();
        }
    }

    private function blockBobs(bool $blocked): void
    {
        $code = '$c = \\Idno\\Core\\Idno::site()->config(); ' . ($blocked ? '$c->addBlockedEmail("bob@example.com");' : '$c->blocked_emails = [];') . ' $c->save(); return true;';
        $this->assertTrue($this->runInSite($code));
    }

    public function testAMemberWhoseAddressIsBlocked(): void
    {
        $this->seedSharedSite();
        $this->twice(function (): void {
            $this->blockBobs(false);
            $before = $this->bobsSession();
            $this->blockBobs(true);
            [$cookie, $token] = $this->visitorSession();
            $s = $this->scrub($token);
            $login = $this->site()->request('POST', '/session/login', ['Cookie' => $cookie], ['__bTk' => $token, 'email' => 'bob', 'password' => self::PASSWORD]);
            $this->answer('login-post-blocked', $login, $s);
            $this->answer('login-post-blocked-then', $this->follow($login, $cookie), $s);
            $this->answer('login-post-blocked-user', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => self::sessionCookieOrNull($login) ?? $cookie]), $s);
            // the session he had before: the site ends it
            $this->answer('blocked-old-session', $this->site()->request('GET', '/currentUser?_t=json', ['Cookie' => $before]), $s);
            $this->answer('blocked-old-session-then', $this->site()->request('GET', '/session/login', ['Cookie' => $before]), $s);
            $this->blockBobs(false);
        });
    }
}
