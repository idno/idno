<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * What a page's class tells the rest of the site, over HTTP, on a site of the test's own: the events
 * `page/ready`, `page/head` and `page/get` (recorded by `Tests\Fixtures\PageEventRecorder`, which also sends a
 * header from `page/head`, as a plugin's listener may), the class the body's first CSS class is made from, the
 * owner the home page of a single-user site has, and who is let in to each page on a private site. Each assertion
 * is what the site does today.
 */
final class PageContractTest extends PrivateSiteTestCase
{
    private string $recordFile = '';

    protected function setUp(): void
    {
        $this->recordFile = sys_get_temp_dir() . '/idno-pages-' . bin2hex(random_bytes(6));
        parent::setUp();
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
    }

    protected function tearDown(): void
    {
        if ($this->recordFile !== '' && is_file($this->recordFile)) {
            unlink($this->recordFile);
        }
        parent::tearDown();
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_RECORD_PAGES' => $this->recordFile];
    }

    /**
     * Requests a page and returns the events it recorded, in order (the file is emptied first).
     *
     * @param array<string, string> $headers
     *
     * @return array{0: \Tests\Characterisation\SiteResponse, 1: list<array<string, mixed>>}
     */
    private function recorded(string $method, string $path, array $headers = []): array
    {
        file_put_contents($this->recordFile, '');
        $response = $this->site()->request($method, $path, $headers);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->recordFile))));

        return [$response, array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines)];
    }

    public function testTheHomePageFiresReadyHeadAndGetWithItsClass(): void
    {
        [$response, $events] = $this->recorded('GET', '/');

        $this->assertSame(200, $response->status);
        $this->assertSame(['page/ready', 'page/head', 'page/get'], array_column($events, 'event'));
        foreach ($events as $event) {
            $this->assertSame('Idno\Pages\Homepage', $event['current']);
            $this->assertSame('idno_pages_homepage', $event['body_class']);
            $this->assertSame('default', $event['type']);
            $this->assertFalse($event['xhr']);
            $this->assertFalse($event['permalink']);
        }
        // Only page/get carries the class in its data: a GET's page/head carries the page itself, not its class.
        $this->assertSame([null, null, 'Idno\Pages\Homepage'], array_column($events, 'page_class'));
    }

    public function testAHeadRequestFiresReadyAndHeadWithItsClassAndNoGet(): void
    {
        [$response, $events] = $this->recorded('HEAD', '/');

        $this->assertSame(200, $response->status);
        $this->assertSame(['page/ready', 'page/head'], array_column($events, 'event'));
        $this->assertSame([null, 'Idno\Pages\Homepage'], array_column($events, 'page_class'));
    }

    public function testASingleUserSitesHomePageIsOwnedByItsAdmin(): void
    {
        // single_user is on by default and the site has one administrator. The owner is set inside getContent(), after the
        // three events have fired, so they all record no owner: the page's output is where the owner shows.
        [$response, $events] = $this->recorded('GET', '/?_t=json');

        $this->assertSame(200, $response->status);
        $this->assertSame([null, null, null], array_column($events, 'owner'));
        $this->assertSame('Ada', $response->json()['user']['displayName']);
    }

    public function testThePageHeadHeaderReachesTheResponse(): void
    {
        foreach (['/', '/feed.xml', '/tag/x', '/robots.txt'] as $path) {
            [$response] = $this->recorded('GET', $path);

            $this->assertSame(200, $response->status, $path);
            $this->assertStringContainsString('1', (string) $response->header('X-Idno-Test-Page-Head'), $path);
        }
    }

    public function testTheFeedAndTagPagesSeeTheHomePageClass(): void
    {
        // The feed page builds a home page to draw its items: after its own three events the home page's three follow, the head and get
        // of the second on the rss type (and a second Link header block, so the headers of the feed are sent twice).
        [, $feed] = $this->recorded('GET', '/feed.xml');
        $this->assertSame(
            [['page/ready', 'Idno\Pages\Feed', 'idno_pages_feed', 'default'], ['page/head', 'Idno\Pages\Feed', 'idno_pages_feed', 'default'], ['page/get', 'Idno\Pages\Feed', 'idno_pages_feed', 'default'],
                ['page/ready', 'Idno\Pages\Homepage', 'idno_pages_homepage', 'default'], ['page/head', 'Idno\Pages\Homepage', 'idno_pages_homepage', 'rss'], ['page/get', 'Idno\Pages\Homepage', 'idno_pages_homepage', 'rss']],
            array_map(static fn (array $e): array => [$e['event'], $e['current'], $e['body_class'], $e['type']], $feed)
        );
        $this->assertSame('Idno\Pages\Homepage', $feed[5]['page_class']);

        // The tag page's own three events, then a home page it builds (page/ready only: it is never asked to get).
        [, $tag] = $this->recorded('GET', '/tag/x');
        $this->assertSame(
            [['page/ready', 'Idno\Pages\Search\Tags', 'idno_pages_search_tags'], ['page/head', 'Idno\Pages\Search\Tags', 'idno_pages_search_tags'], ['page/get', 'Idno\Pages\Search\Tags', 'idno_pages_search_tags'],
                ['page/ready', 'Idno\Pages\Homepage', 'idno_pages_homepage']],
            array_map(static fn (array $e): array => [$e['event'], $e['current'], $e['body_class']], $tag)
        );
        $this->assertSame('Idno\Pages\Search\Tags', $tag[2]['page_class']);
    }

    public function testTheSmallPagesFireTheEventsWithTheirOwnClass(): void
    {
        foreach (['/robots.txt' => 'Idno\Pages\Txt\Robots', '/search/mentions.json' => 'Idno\Pages\Search\Mentions', '/content/all' => 'Idno\Pages\Homepage'] as $path => $class) {
            [, $events] = $this->recorded('GET', $path);

            $this->assertSame(['page/ready', 'page/head', 'page/get'], array_column($events, 'event'), $path);
            $this->assertSame($class, $events[2]['page_class'], $path);
            $this->assertSame($class, $events[2]['current'], $path);
        }
    }

    public function testAnXhrRequestIsAnXhrPage(): void
    {
        [$response, $events] = $this->recorded('GET', '/', ['X-Requested-With' => 'XMLHttpRequest']);

        $this->assertSame(200, $response->status);
        // page/ready fires before the XHR flag is set.
        $this->assertSame([false, true, true], array_column($events, 'xhr'));
    }

    public function testWalledGardenAnswersForEachPage(): void
    {
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_WALLED_GARDEN' => '1']));

        foreach (['/', '/feed.xml', '/tag/x', '/robots.txt', '/search/mentions.json'] as $path) {
            $this->assertSame(403, $client->request('GET', $path)->status, $path);
        }
        foreach (['/.well-known/webfinger?resource=acct:ada@127.0.0.1', '/chrome/manifest.json', '/service-worker.min.js'] as $path) {
            $this->assertSame(200, $client->request('GET', $path)->status, $path);
        }
    }
}
