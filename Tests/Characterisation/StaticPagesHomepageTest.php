<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * A static page set as the home page (StaticPages answers the home page's replacement event, `homepage/replace`), over HTTP, on a
 * site of the test's own: `/` shows the page, `/content/default/` is still the stream, and clearing the setting
 * brings the stream back. Each assertion is what the site does today.
 */
final class StaticPagesHomepageTest extends PrivateSiteTestCase
{
    private string $pageId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
        $this->pageId = (string) $this->runInSite(
            '$admin = \Idno\Entities\User::getByHandle(\'ada\');'
            . '$page = new \IdnoPlugins\StaticPages\StaticPage(); $page->setOwner($admin); $page->setTitle(\'About this fixture\'); $page->body = \'<p>Static fixture body</p>\'; $page->setAccess(\'PUBLIC\'); $page->save(true);'
            . '$note = new \IdnoPlugins\Status\Status(); $note->body = \'a note in the stream\'; $note->setOwner($admin); $note->setAccess(\'PUBLIC\'); $note->save(true);'
            . 'return $page->getID();'
        );
        $this->assertNotSame('', $this->pageId);
    }

    private function setAsHomepage(): void
    {
        $this->assertTrue($this->runInSite(
            '\Idno\Core\Idno::site()->session()->logUserOn(\Idno\Entities\User::getByHandle(\'ada\'));'
            . 'return \Idno\Core\Idno::site()->plugins()->get(\'StaticPages\')->setAsHomepage(' . $this->literal($this->pageId) . ');'
        ));
    }

    public function testAStaticPageSetAsTheHomePageIsServedAtTheRoot(): void
    {
        $this->setAsHomepage();

        foreach (['', '/'] as $path) {
            $home = $this->site()->request('GET', $path);
            $this->assertSame(200, $home->status, 'GET ' . $path);
            $this->assertStringContainsString('About this fixture', $home->body, 'GET ' . $path);
            $this->assertStringContainsString('Static fixture body', $home->body, 'GET ' . $path);
            $this->assertStringNotContainsString('a note in the stream', $home->body, 'GET ' . $path);
        }

        $stream = $this->site()->request('GET', '/content/default/');
        $this->assertSame(200, $stream->status);
        $this->assertStringContainsString('a note in the stream', $stream->body);
        $this->assertStringNotContainsString('Static fixture body', $stream->body);
    }

    public function testTheStreamIsStillReachableWhenTheHomePageIsAStaticPage(): void
    {
        $this->setAsHomepage();

        foreach (['/content/all', '/content/statuses', '/feed.xml'] as $path) {
            $response = $this->site()->request('GET', $path);
            $this->assertSame(200, $response->status, $path);
            $this->assertStringContainsString('a note in the stream', $response->body, $path);
        }
        $json = $this->site()->request('GET', '/content/default/?_t=json')->json();
        $this->assertSame('a note in the stream', $json['items'][0]['content']);
    }

    public function testClearingTheHomePageRestoresTheStream(): void
    {
        $this->setAsHomepage();
        $this->assertStringContainsString('Static fixture body', $this->site()->request('GET', '/')->body);

        $this->assertTrue($this->runInSite(
            '\Idno\Core\Idno::site()->session()->logUserOn(\Idno\Entities\User::getByHandle(\'ada\'));'
            . 'return \Idno\Core\Idno::site()->plugins()->get(\'StaticPages\')->clearHomepage();'
        ));

        $home = $this->site()->request('GET', '/');
        $this->assertSame(200, $home->status);
        $this->assertStringContainsString('a note in the stream', $home->body);
        $this->assertStringNotContainsString('Static fixture body', $home->body);
    }

    public function testARootWebmentionOnASiteWithAStaticHomePageReachesTheStreamsWebmentionHandler(): void
    {
        $this->setAsHomepage();

        // A webmention whose source is a public address cannot be accepted over HTTP in a test (the source is fetched, and a loopback source is refused),
        // so what is pinned is the route: the page the router finds for the site's root is the home page's own, whose webmention handler
        // (which hands a root mention on a single-user site to the administrator's profile page) is the one a site with no static home page has.
        // (The plugin once served the root with a page class of its own that inherited the handler; the root is the home page's now.)
        $result = $this->runInSite(
            // (a script's routes are under its own directory, which is the site's subdirectory there)
            '$page = \Idno\Core\Idno::site()->routes()->getRoute(rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/") . "/");'
            . 'return [get_class($page), (new \ReflectionMethod($page, \'webmentionContent\'))->getDeclaringClass()->getName()];'
        );

        $this->assertSame(['Idno\Pages\Homepage', 'Idno\Pages\Homepage'], $result);
    }

    public function testARootWebmentionWithASourceThatIsNotPublicIsRefusedWithTheSelfMentionMessage(): void
    {
        $this->setAsHomepage();

        $response = $this->site()->request('POST', '/webmention/', [], ['source' => $this->baseUrl() . '_test/page/mention', 'target' => $this->baseUrl()]);

        $this->assertSame(400, $response->status);
        $this->assertSame('{"error":"target_not_supported","error_text":"A page can\'t webmention itself."}', $response->body);
    }

    public function testASiteWithAStaticHomePageLogsNoDeprecation(): void
    {
        $this->setAsHomepage();

        $this->assertSame(200, $this->site()->request('GET', '/')->status);
        $this->assertSame(200, $this->site()->request('GET', '/content/default/')->status);

        $this->assertStringNotContainsString('Deprecated', $this->serverLog());
    }

    public function testTheMarkdownFormOfTheHomePageIsTheStaticPagesOwn(): void
    {
        $this->setAsHomepage();

        $page = $this->site()->request('GET', '/?_t=md');

        $this->assertSame(200, $page->status);
        $this->assertStringStartsWith('text/markdown', (string) $page->header('Content-Type'));
        $this->assertStringContainsString('title: "About this fixture"', $page->body);
        $this->assertStringContainsString('Static fixture body', $page->body);
        $this->assertStringNotContainsString('a note in the stream', $page->body);
    }

    public function testAHomePageThatForwardsSendsAVisitorOnAndLetsAnAdministratorIn(): void
    {
        $forwarding = (string) $this->runInSite(
            '$admin = \Idno\Entities\User::getByHandle(\'ada\');'
            . '$page = new \IdnoPlugins\StaticPages\StaticPage(); $page->setOwner($admin); $page->setTitle(\'Forwarding fixture\'); $page->body = \'<p>Forwarding body</p>\'; $page->forward_url = \'http://example.test/away\'; $page->setAccess(\'PUBLIC\'); $page->save(true);'
            . 'return $page->getID();'
        );
        $this->pageId = $forwarding;
        $this->setAsHomepage();

        $visitor = $this->site()->request('GET', '/');
        $this->assertSame(302, $visitor->status);
        $this->assertSame('http://example.test/away', $visitor->header('Location'));

        $admin = $this->site()->request('GET', '/', ['Cookie' => $this->loginCookie('ada', 'correct-horse-battery-9')]);
        $this->assertSame(200, $admin->status);
        $this->assertStringContainsString('Forwarding body', $admin->body);
    }

    public function testAHomePageThatIsGoneIsUnsetWhenAnAdministratorAsksAndTheStreamIsServedAtOnce(): void
    {
        $this->setAsHomepage();
        $this->runInSite('\IdnoPlugins\StaticPages\StaticPage::getByID(' . $this->literal($this->pageId) . ')->delete(); return 1;');

        // a visitor gets the ordinary home page (the old page redirected to `/`, which asked for the same page again) and the setting stays: it is the administrator's to change
        $visitor = $this->site()->request('GET', '/');
        $this->assertSame(200, $visitor->status);
        $this->assertStringContainsString('a note in the stream', $visitor->body);

        $admin = $this->site()->request('GET', '/', ['Cookie' => $this->loginCookie('ada', 'correct-horse-battery-9')]);
        $this->assertSame(200, $admin->status);
        $this->assertStringContainsString('a note in the stream', $admin->body);

        $this->assertSame('', (string) $this->runInSite('return \Idno\Core\Idno::site()->plugins()->get("StaticPages")->getCurrentHomepageId() ?: "";'), 'the administrator unset it');
    }
}
