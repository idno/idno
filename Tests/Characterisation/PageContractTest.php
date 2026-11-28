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
    protected const SHARED_SITE = true;

    /** The file the site records the page events in: one for the class, so that its environment is the same for every test. */
    private static string $recordFile = '';

    protected function setUp(): void
    {
        self::$recordFile = self::$recordFile !== '' ? self::$recordFile : sys_get_temp_dir() . '/idno-pages-' . bin2hex(random_bytes(6));
        parent::setUp();
    }

    protected function seedSharedSite(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9', null, true);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$recordFile !== '' && is_file(self::$recordFile)) {
            unlink(self::$recordFile);
        }
        self::$recordFile = '';
        parent::tearDownAfterClass();
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_RECORD_PAGES' => self::$recordFile];
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
        file_put_contents(self::$recordFile, '');
        $response = $this->site()->request($method, $path, $headers);
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents(self::$recordFile))));

        return [$response, array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines)];
    }

    public function testTheHomePageFiresReadyHeadAndGetWithItsClass(): void
    {
        [$response, $events] = $this->recorded('GET', '/');

        $this->assertSame(200, $response->status);
        $this->assertSame(['page/ready', 'page/head', 'page/get'], array_column($events, 'event'));
        foreach ($events as $event) {
            // a controller serves the home page: its current page is the controller page, which stands for the legacy class
            $this->assertSame('Idno\Legacy\ControllerPage', $event['current']);
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

    public function testTheFeedAndTagPagesFireTheHomePagesEventsOnce(): void
    {
        // The feed and the tag page are the home page's stream under another address: it is one page, so its three events fire once, as the
        // home page's, in the feed's own template type (the feed page used to fire a set of its own and then the home page's it built:
        // `page/ready`, `page/head` and `page/get` twice, and the tag page a trailing `page/ready`).
        [, $feed] = $this->recorded('GET', '/feed.xml');
        $this->assertSame(
            [['page/ready', 'Idno\Legacy\ControllerPage', 'idno_pages_homepage', 'rss'], ['page/head', 'Idno\Legacy\ControllerPage', 'idno_pages_homepage', 'rss'], ['page/get', 'Idno\Legacy\ControllerPage', 'idno_pages_homepage', 'rss']],
            array_map(static fn (array $e): array => [$e['event'], $e['current'], $e['body_class'], $e['type']], $feed)
        );
        $this->assertSame('Idno\Pages\Homepage', $feed[2]['page_class']);

        [, $tag] = $this->recorded('GET', '/tag/x');
        $this->assertSame(
            [['page/ready', 'Idno\Legacy\ControllerPage', 'idno_pages_homepage', 'default'], ['page/head', 'Idno\Legacy\ControllerPage', 'idno_pages_homepage', 'default'], ['page/get', 'Idno\Legacy\ControllerPage', 'idno_pages_homepage', 'default']],
            array_map(static fn (array $e): array => [$e['event'], $e['current'], $e['body_class'], $e['type']], $tag)
        );
        $this->assertSame('Idno\Pages\Homepage', $tag[2]['page_class']);

        foreach (['/rss.xml', '/feed'] as $path) {
            [, $events] = $this->recorded('GET', $path);
            $this->assertSame(['page/ready', 'page/head', 'page/get'], array_column($events, 'event'), $path);
        }
    }

    public function testTheSmallPagesFireTheEventsWithTheirOwnClass(): void
    {
        foreach (['/robots.txt' => 'Idno\Pages\Txt\Robots', '/search/mentions.json' => 'Idno\Pages\Search\Mentions', '/content/all' => 'Idno\Pages\Homepage'] as $path => $class) {
            [, $events] = $this->recorded('GET', $path);

            $this->assertSame(['page/ready', 'page/head', 'page/get'], array_column($events, 'event'), $path);
            $this->assertSame($class, $events[2]['page_class'], $path);
            // each is a controller's now: its current page is the controller page, while its events keep the name of the class that was the page
            $this->assertSame('Idno\Legacy\ControllerPage', $events[2]['current'], $path);
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
        $url = $this->startExtraServer(['IDNO_WALLED_GARDEN' => '1']);
        $client = new HttpSiteClient($url);
        $host = (string) parse_url($url, PHP_URL_HOST);

        foreach (['/', '/feed.xml', '/tag/x', '/robots.txt', '/search/mentions.json'] as $path) {
            $this->assertSame(403, $client->request('GET', $path)->status, $path);
        }
        // (the form token endpoint was no public route: a visitor of a private site is refused, with the XHR header the page asks for as well as without it)
        foreach ([[], ['X-Requested-With' => 'XMLHttpRequest']] as $headers) {
            $response = $client->request('GET', '/service/security/csrftoken/', $headers);
            $this->assertSame(403, $response->status, 'the form token endpoint ' . json_encode($headers));
            $this->assertStringNotContainsString('"token"', $response->body, 'no token reaches a visitor of a private site');
        }
        // (the service worker, either spelling, and the manifest are public routes)
        foreach (['/chrome/manifest.json', '/service-worker.min.js', '/service-worker.js'] as $path) {
            $this->assertSame(200, $client->request('GET', $path)->status, $path);
        }
        // WebFinger is open to a stranger but names no member of a private site: the 404 page, a member who exists included
        $this->assertSame(404, $client->request('GET', '/.well-known/webfinger?resource=acct:ada@' . $host)->status, 'a private site names no member');
    }

    public function testARefusedWalledGardenRequestFiresNoPageHead(): void
    {
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_WALLED_GARDEN' => '1']));

        foreach (['/', '/feed.xml', '/tag/x'] as $path) {
            file_put_contents(self::$recordFile, '');
            $response = $client->request('GET', $path);

            $this->assertSame(403, $response->status, $path);
            $this->assertNull($response->header('X-Idno-Test-Page-Head'), $path);
            $events = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", (string) file_get_contents(self::$recordFile)))));
            $this->assertSame(['page/ready'], array_column($events, 'event'), $path);
            $this->assertSame('Idno\Legacy\ControllerPage', $events[0]['current'], $path);
        }
    }
}
