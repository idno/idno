<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * What a legacy page that ends its request (`exit`) or closes the session while it draws leaves in the session, over HTTP on a site of the
 * test's own: a login made before an `exit` survives it, and a message is shown exactly once. The pages are Tests\Fixtures\LegacyLoginExitPage
 * and LegacyDrawPage.
 */
final class SessionLifecycleTest extends PrivateSiteTestCase
{
    private const MESSAGE = 'A message to be shown once';

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_LOGIN_EXIT' => '1'];
    }

    public function testAMessageALegacyPageAddsBeforeItForwardsIsShownOnTheNextPage(): void
    {
        $queued = $this->site()->request('GET', '/_test/site-page/draw-legacy?case=queue-message');
        $cookie = self::sessionCookie($queued);
        $this->assertSame('queued', $queued->body);

        $next = $this->site()->request('GET', '/_test/site-page/draw-legacy?case=draw', ['Cookie' => $cookie]);
        $this->assertStringContainsString(self::MESSAGE, $next->body, 'the message waited in the session');

        $after = $this->site()->request('GET', '/_test/site-page/draw-legacy?case=draw', ['Cookie' => $cookie]);
        $this->assertStringNotContainsString(self::MESSAGE, $after->body, 'and was used up');
    }

    public function testALoginMadeByALegacyPageSurvivesItsExit(): void
    {
        $this->registerUser('admin');
        $bob = $this->addUser('bob');

        $login = $this->site()->request('GET', '/_test/login-exit?user=bob');
        $this->assertSame("logged on\n", $login->body);
        $cookie = self::sessionCookie($login);

        $who = $this->site()->request('GET', '/_test/whoami', ['Cookie' => $cookie]);
        $this->assertSame($bob, json_decode($who->body, true)['uuid'] ?? null);
    }

    public function testAPageThatClosesTheSessionStillShowsItsMessagesOnce(): void
    {
        // the page draws its shell after closing the session (DefaultTemplate::drawPage()), with the messages it took first
        $first = $this->site()->request('GET', '/_test/site-page/draw-legacy?case=drawmessage');
        $cookie = self::sessionCookie($first);
        $this->assertStringContainsString(self::MESSAGE, $first->body);

        $second = $this->site()->request('GET', '/_test/site-page/draw-legacy?case=draw', ['Cookie' => $cookie]);
        $this->assertStringNotContainsString(self::MESSAGE, $second->body, 'it was shown once');
    }
}
