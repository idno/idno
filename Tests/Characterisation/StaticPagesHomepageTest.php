<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * A static page set as the home page (StaticPages hijacks the routes `` and `/` for its own page class), over HTTP, on a
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
        // so what is pinned is the route: the page the router finds for the site's root is the static home page's class, and the method that
        // handles a mention of it is the stream's home page's own (which hands a root mention on a single-user site to the administrator's profile page).
        $result = $this->runInSite(
            '$page = \Idno\Core\Idno::site()->routes()->getRoute(\Idno\Core\Idno::site()->config()->getURL());'
            . 'return [get_class($page), (new \ReflectionMethod($page, \'webmentionContent\'))->getDeclaringClass()->getName()];'
        );

        $this->assertSame(['IdnoPlugins\StaticPages\Pages\Homepage', 'Idno\Pages\Homepage'], $result);
    }

    public function testARootWebmentionWithASourceThatIsNotPublicIsRefusedWithTheSelfMentionMessage(): void
    {
        $this->setAsHomepage();

        $response = $this->site()->request('POST', '/webmention/', [], ['source' => $this->baseUrl() . '_test/page/mention', 'target' => $this->baseUrl()]);

        $this->assertSame(400, $response->status);
        $this->assertSame('{"error":"target_not_supported","error_text":"A page can\'t webmention itself."}', $response->body);
    }
}
