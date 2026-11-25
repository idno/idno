<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * What the StaticPages plugin answers to the home page's replacement event (`homepage/replace`) on a site of the test's own: nothing when no
 * static page is the home page, the page (as the page's own address would serve it) when one is, and a redirect to the root, clearing the
 * setting, when the page chosen is gone, is no static page or cannot be read.
 */
final class StaticPagesHomepageReplacementTest extends PrivateSiteTestCase
{
    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $this->addUser('bob', 'correct-horse-battery-9');
        $this->addUser('carol', 'correct-horse-battery-9');
        $this->ids = $this->runInSite(
            '$admin = \Idno\Entities\User::getByHandle("ada"); $carol = \Idno\Entities\User::getByHandle("carol");'
            . '$mk = function ($title, $access, $extra = [], $owner = null) use ($admin, $carol) { $p = new \IdnoPlugins\StaticPages\StaticPage(); $p->setOwner($owner ?? $admin); $p->setTitle($title); $p->body = "<p>Body of $title</p>"; $p->setAccess($access); foreach ($extra as $k => $v) { $p->$k = $v; } $p->save(true); return $p->getID(); };'
            . '$note = new \IdnoPlugins\Status\Status(); $note->body = "a note"; $note->setOwner($admin); $note->setAccess("PUBLIC"); $note->save(true);'
            . 'return ["public" => $mk("Public page", "PUBLIC", ["slug" => "public-page"]), "forward" => $mk("Forwarding page", "PUBLIC", ["forward_url" => "http://example.test/away"]), "private" => $mk("Private page", $carol->getUUID(), [], $carol), "site" => $mk("Members page", "SITE"), "draft" => $mk("Draft page", "PUBLIC", ["publish_status" => "draft"], $carol), "note" => $note->getID()];'
        );
    }

    /**
     * Asks the plugin for the home page as `$who` (a handle, or null for a visitor) with `$homepage` chosen, and says what it answered and what it left.
     *
     * @return array{response: ?array{status: int, location: ?string, body: string}, homepage: mixed, owner: ?string, permalink: bool, lastModified: ?string}
     */
    private function ask(string $homepage, ?string $who): array
    {
        return $this->runInSite(
            '\Idno\Core\Idno::site()->config()->staticPages = ' . ($homepage === '' ? '[]' : '["homepage" => ' . $this->literal($homepage) . ']') . ';'
            . '\Idno\Core\Idno::site()->config()->save();'
            . ($who !== null ? '\Idno\Core\Idno::site()->session()->logUserOn(\Idno\Entities\User::getByHandle(' . $this->literal($who) . '));' : '')
            . '$_SERVER["REQUEST_URI"] = "/"; $_SERVER["REQUEST_METHOD"] = "GET";'
            . '$context = new \Idno\Http\Page\PageContext(\Symfony\Component\HttpFoundation\Request::create("/"), new \Idno\Http\Page\SitePage("Idno\Pages\Homepage"));'
            . '\Idno\Core\Idno::site()->setCurrentPage(new \Idno\Legacy\ControllerPage($context, false));'
            . '$renderer = \Idno\Core\Idno::container()->get(\Idno\Http\Page\PageRenderer::class);'
            . '$response = (new \IdnoPlugins\StaticPages\HomepageReplacement(\Idno\Core\Idno::site()->plugins()->get("StaticPages")))->respond($context, $renderer);'
            . 'return ["response" => $response === null ? null : ["status" => $response->getStatusCode(), "location" => $response->headers->get("Location"), "body" => (string) $response->getContent()],'
            . ' "homepage" => \Idno\Core\Idno::site()->plugins()->get("StaticPages")->getCurrentHomepageId(), "owner" => $context->owner()?->handle, "permalink" => $context->isPermalink(), "lastModified" => $context->headers()["Last-Modified"][0] ?? null];'
        );
    }

    public function testNothingIsAnsweredWhenNoStaticPageIsTheHomePage(): void
    {
        $answer = $this->ask('', null);

        $this->assertNull($answer['response']);
        $this->assertFalse($answer['permalink']);
    }

    public function testTheChosenPageIsAnsweredInItsShellAsAPermalinkOfItsOwner(): void
    {
        foreach ([$this->ids['public'], 'public-page'] as $chosen) {
            $answer = $this->ask($chosen, null);

            $this->assertSame(200, $answer['response']['status'], $chosen);
            $this->assertStringContainsString('<title class="p-name">Public page</title>', $answer['response']['body'], $chosen);
            $this->assertStringContainsString('Body of Public page', $answer['response']['body'], $chosen);
            $this->assertSame('ada', $answer['owner'], 'the page is its owner\'s');
            $this->assertTrue($answer['permalink'], 'and a permalink');
            $this->assertMatchesRegularExpression('/^\w{3}, \d\d \w{3} \d{4} \d\d:\d\d:\d\d GMT$/', (string) $answer['lastModified'], 'and was last modified when it was saved');
            $this->assertSame($chosen, $answer['homepage'], 'the setting is kept');
        }
    }

    public function testAVisitorIsForwardedToTheAddressAPageForwardsTo(): void
    {
        $answer = $this->ask($this->ids['forward'], null);

        $this->assertSame(302, $answer['response']['status']);
        $this->assertSame('http://example.test/away', $answer['response']['location']);
        $this->assertNull($answer['lastModified'], 'nothing was drawn');
    }

    public function testAnAdministratorSeesAPageThatForwardsAVisitor(): void
    {
        $answer = $this->ask($this->ids['forward'], 'ada');

        $this->assertSame(200, $answer['response']['status']);
        $this->assertStringContainsString('Body of Forwarding page', $answer['response']['body']);
        $this->assertNull($answer['response']['location']);
    }

    public function testAMemberIsForwardedToo(): void
    {
        $answer = $this->ask($this->ids['forward'], 'bob');

        $this->assertSame('http://example.test/away', $answer['response']['location']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusable(): array
    {
        return ['no such page' => ['no-such-page'], 'a page that is no static page' => ['note']];
    }

    /**
     * A home page that is gone, or is no static page, is unset by an administrator (the setting is theirs) and nothing replaces the home
     * page: the ordinary one is served, with no redirect to the root (which would ask for the same page again).
     *
     * @dataProvider unusable
     */
    public function testAHomePageThatCannotBeUsedIsUnsetByAnAdministratorAndTheStreamIsServed(string $case): void
    {
        $chosen = $this->ids[$case] ?? $case;

        $answer = $this->ask($chosen, 'ada');

        $this->assertNull($answer['response']);
        $this->assertFalse($answer['homepage'], 'the setting is cleared');
    }

    /**
     * @dataProvider unusable
     */
    public function testAVisitorOrAMemberFindingAnUnusablePageLeavesTheSettingAndTheStreamIsServed(string $case): void
    {
        $chosen = $this->ids[$case] ?? $case;

        foreach ([null, 'bob'] as $who) {
            $answer = $this->ask($chosen, $who);

            $this->assertNull($answer['response'], (string) $who);
            $this->assertSame($chosen, $answer['homepage'], 'a setting only an administrator can change stays');
        }
    }

    /**
     * What the viewer may not read is not the home page: not for a visitor, not for a member, and the setting stays for them. The page's
     * own checks: the entity's `canRead()`, which includes who may see it unpublished.
     *
     * @return array<string, array{0: string, 1: list<?string>, 2: list<string>}> the page, who may not read it, who may
     */
    public static function restricted(): array
    {
        return [
            'owner only' => ['private', [null, 'bob'], ['ada', 'carol']],
            'members only' => ['site', [null], ['bob', 'ada', 'carol']],
            'a draft' => ['draft', [null, 'bob'], ['ada', 'carol']],
        ];
    }

    /**
     * @dataProvider restricted
     *
     * @param list<?string> $cannot
     * @param list<string>  $can
     */
    public function testWhatTheViewerCannotReadIsNoHomePage(string $case, array $cannot, array $can): void
    {
        foreach ($cannot as $who) {
            $answer = $this->ask($this->ids[$case], $who);

            $this->assertNull($answer['response'], $case . ' for ' . ($who ?? 'a visitor'));
            $this->assertSame($this->ids[$case], $answer['homepage'], 'nothing is unset for a viewer who is only refused');
        }
        foreach ($can as $who) {
            $answer = $this->ask($this->ids[$case], $who);

            $this->assertSame(200, $answer['response']['status'] ?? null, $case . ' for ' . $who);
        }
    }

    public function testThePluginListensToTheEvent(): void
    {
        $answer = $this->runInSite(
            '\Idno\Core\Idno::site()->config()->staticPages = ["homepage" => ' . $this->literal($this->ids['public']) . '];'
            . '$_SERVER["REQUEST_URI"] = "/"; $_SERVER["REQUEST_METHOD"] = "GET";'
            . '$context = new \Idno\Http\Page\PageContext(\Symfony\Component\HttpFoundation\Request::create("/"), new \Idno\Http\Page\SitePage("Idno\Pages\Homepage"));'
            . '\Idno\Core\Idno::site()->setCurrentPage(new \Idno\Legacy\ControllerPage($context, false));'
            . '$renderer = \Idno\Core\Idno::container()->get(\Idno\Http\Page\PageRenderer::class);'
            . '$response = \Idno\Core\Idno::site()->events()->triggerEvent("homepage/replace", ["page" => $context, "renderer" => $renderer]);'
            . 'return [$response instanceof \Symfony\Component\HttpFoundation\Response, $response instanceof \Symfony\Component\HttpFoundation\Response && str_contains((string) $response->getContent(), "Body of Public page")];'
        );

        $this->assertSame([true, true], $answer);
    }
}
