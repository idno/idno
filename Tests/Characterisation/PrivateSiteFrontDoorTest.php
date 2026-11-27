<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * What a person sees at the door of a private site. A visitor who is refused must learn that the site is private and be offered the sign-in page;
 * a member who is refused for lack of permission is already signed in, so a "log in" offer would be wrong; and the navigation must not offer a visitor
 * pages that can only refuse them. The pages' own answers are recorded in the walled-site recordings.
 */
final class PrivateSiteFrontDoorTest extends PrivateSiteTestCase
{
    use LogsIn;

    protected function siteEnvironment(): array
    {
        return ['IDNO_WALLED_GARDEN' => '1', 'IDNO_TITLE' => 'Hidden Garden'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->addUser('admin1', 'correct-horse-battery-9', null, true);
        $this->addUser('member1');
    }

    /** A visitor met a bare "log in" link, with nothing to say the site is private or why they were stopped. */
    public function testAVisitorRefusedByAPrivateSiteIsToldItIsPrivateAndOfferedTheSignInPage(): void
    {
        $refused = $this->site()->request('GET', '');

        $this->assertSame(403, $refused->status);
        $this->assertMatchesRegularExpression('#<h1[^>]*>[^<]*Hidden Garden[^<]*</h1>#', $refused->body, 'the heading names the private site');
        $this->assertMatchesRegularExpression('#<a[^>]*id="soft-forward"[^>]*href="[^"]*/session/login[^"]*"|<a[^>]*href="[^"]*/session/login[^"]*"[^>]*id="soft-forward"#', $refused->body, 'the sign-in page is offered');
    }

    /** A signed-in member refused a page they have no permission for was told to log in, which they have already done. */
    public function testAMemberRefusedForLackOfPermissionIsNotOfferedASignIn(): void
    {
        $refused = $this->site()->request('GET', 'admin/', ['Cookie' => $this->loginCookie('member1', 'correct-horse-battery-9')]);

        $this->assertSame(403, $refused->status);
        $this->assertStringNotContainsString('soft-forward', $refused->body);
        $this->assertStringNotContainsString('session/login', $refused->body, 'no link to sign in on the page, in the navigation included');
    }

    /** The Home and Search items of the navigation only led a visitor of a private site to a refusal. */
    public function testTheNavigationOfAPrivateSiteOffersAVisitorOnlyTheSignInPage(): void
    {
        $visitor = $this->site()->request('GET', 'session/login');
        $member = $this->site()->request('GET', '', ['Cookie' => $this->loginCookie('member1', 'correct-horse-battery-9')]);

        $this->assertSame(200, $visitor->status);
        $navOf = static fn (string $html): string => preg_match('#<nav class="idno-nav-sidebar".*?</nav>#s', $html, $m) === 1 ? $m[0] : '';
        $nav = $navOf($visitor->body);
        $this->assertNotSame('', $nav, 'the page has its navigation');
        $this->assertStringContainsString('open-search', $navOf($member->body), 'a member is offered the search');
        $this->assertStringNotContainsString('open-search', $nav, 'a visitor is not');
        $this->assertStringContainsString('session/login', $nav, 'the sign-in page is offered');
        $this->assertSame(1, substr_count($nav, 'class="idno-nav-item"'), 'the sign-in page is the only item: ' . $nav);
    }
}
