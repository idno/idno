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
        $this->ids = $this->runInSite(
            '$admin = \Idno\Entities\User::getByHandle("ada");'
            . '$mk = function ($title, $access, $extra = []) use ($admin) { $p = new \IdnoPlugins\StaticPages\StaticPage(); $p->setOwner($admin); $p->setTitle($title); $p->body = "<p>Body of $title</p>"; $p->setAccess($access); foreach ($extra as $k => $v) { $p->$k = $v; } $p->save(true); return $p->getID(); };'
            . '$note = new \IdnoPlugins\Status\Status(); $note->body = "a note"; $note->setOwner($admin); $note->setAccess("PUBLIC"); $note->save(true);'
            . 'return ["public" => $mk("Public page", "PUBLIC", ["slug" => "public-page"]), "forward" => $mk("Forwarding page", "PUBLIC", ["forward_url" => "http://example.test/away"]), "private" => $mk("Private page", $admin->getUUID()), "note" => $note->getID()];'
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
        return ['no such page' => ['no-such-page'], 'a page that is no static page' => ['note'], 'a page the viewer cannot read' => ['private']];
    }

    /**
     * @dataProvider unusable
     */
    public function testAHomePageThatCannotBeUsedIsClearedByAnAdministratorAndTheRootIsAskedAgain(string $case): void
    {
        $chosen = $this->ids[$case] ?? $case;
        // a member cannot read the private page: the administrator can, so it is the member who finds it unusable and an administrator who clears it
        $asAdministrator = $case === 'private' ? 'bob' : 'ada';

        $answer = $this->ask($chosen, $asAdministrator);

        $this->assertSame(302, $answer['response']['status']);
        $this->assertSame('/', $answer['response']['location']);
        // (clearing is an administrator's: for a member the setting stays, as the plugin's page left it)
        $this->assertSame($asAdministrator === 'ada' ? false : $chosen, $answer['homepage']);
    }

    public function testAVisitorFindingAnUnusablePageLeavesTheSettingAndIsSentToTheRoot(): void
    {
        $answer = $this->ask('no-such-page', null);

        $this->assertSame(302, $answer['response']['status']);
        $this->assertSame('/', $answer['response']['location']);
        $this->assertSame('no-such-page', $answer['homepage']);
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
