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
        foreach (['/.well-known/webfinger?resource=acct:ada@' . $host, '/chrome/manifest.json', '/service-worker.min.js'] as $path) {
            $this->assertSame(200, $client->request('GET', $path)->status, $path);
        }
    }

    public function testARefusedWalledGardenRequestFiresNoPageHead(): void
    {
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_WALLED_GARDEN' => '1']));

        foreach (['/', '/feed.xml', '/tag/x'] as $path) {
            file_put_contents($this->recordFile, '');
            $response = $client->request('GET', $path);

            $this->assertSame(403, $response->status, $path);
            $this->assertNull($response->header('X-Idno-Test-Page-Head'), $path);
            $events = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), array_values(array_filter(explode("\n", (string) file_get_contents($this->recordFile)))));
            $this->assertSame(['page/ready'], array_column($events, 'event'), $path);
            $this->assertSame('Idno\Legacy\ControllerPage', $events[0]['current'], $path);
        }
    }

    /**
     * The `Link` headers of a page as they were sent, each as `<rel> <address>` with the site's own address as `@/`.
     *
     * @return list<string>
     */
    private function links(string $path): array
    {
        $lines = [];
        $ch = curl_init($this->baseUrl() . ltrim($path, '/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$lines): int {
                $lines[] = trim($line);

                return strlen($line);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);
        $links = [];
        foreach ($lines as $line) {
            if (stripos($line, 'link:') === 0) {
                $links[] = (string) preg_replace('/^Link: <([^>]*)>; rel="([^"]*)"$/', '$2 $1', str_replace($this->baseUrl(), '@/', $line));
            }
        }

        return $links;
    }

    public function testEachPageSendsTheLinksOfItsEndpointsAndTheHubsListenersWhenTheHubIsSet(): void
    {
        $endpoints = ['authorization_endpoint @/indieauth/auth', 'token_endpoint @/indieauth/token', 'micropub @/micropub/endpoint'];
        $listeners = static fn (string $self): array => ['http://webmention.org/ @/webmention/', 'webmention @/webmention/', 'hub https://withknown.superfeedr.com/', 'self @/' . $self];

        // The default hub: the webmention, hub and self links follow the endpoints, once on every page (the feed used to send them twice: its own
        // page and the home page it built each fired `page/head`).
        foreach (['', 'robots.txt', 'tag/x', 'content/all', 'feed.xml', 'search/mentions.json', 'chrome/manifest.json'] as $path) {
            $this->assertSame([...$endpoints, ...$listeners($path)], $this->links($path), '/' . $path);
        }
    }

    public function testEachPageSendsOnlyTheLinksOfItsEndpointsWhenTheHubIsEmpty(): void
    {
        $this->runInSite('$config = \Idno\Core\Idno::site()->config(); $config->hub = ""; $config->save(); return true;');
        $endpoints = ['authorization_endpoint @/indieauth/auth', 'token_endpoint @/indieauth/token', 'micropub @/micropub/endpoint'];

        foreach (['', 'feed.xml', 'robots.txt', 'tag/x', 'content/all', 'search/mentions.json', 'chrome/manifest.json'] as $path) {
            $this->assertSame($endpoints, $this->links($path), '/' . $path);
        }
    }
}
