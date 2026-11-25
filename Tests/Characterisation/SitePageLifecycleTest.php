<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * What a page a controller serves does around its handler, over HTTP, on a site of the test's own: the fixture pages of
 * `Tests\Fixtures\SitePageFixtureController`. The events they fire (recorded by `Tests\Fixtures\PageEventRecorder`),
 * their order, the template type, the headers every page sends, OPTIONS, the walled garden and the refusal of an API
 * request on an account page are the legacy pages' (`PageContractTest`, `PortedPagesTest`), with the one difference noted
 * where it shows: the page is an XHR page from `page/ready` on.
 */
final class SitePageLifecycleTest extends PrivateSiteTestCase
{
    use LogsIn;

    private const STATE = '/_test/site-page/state';

    private const PAGE_CLASS = 'Tests\Fixtures\SitePageFixture';

    private string $recordFile = '';

    protected function setUp(): void
    {
        $this->recordFile = sys_get_temp_dir() . '/idno-site-pages-' . bin2hex(random_bytes(6));
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
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) file_get_contents($this->recordFile))))
        );
    }

    /**
     * Requests a page of the site (or of another client's) and returns the events it recorded, in order (the file is emptied first).
     *
     * @param array<string, string> $headers
     *
     * @return array{0: SiteResponse, 1: list<array<string, mixed>>}
     */
    private function recorded(string $method, string $path, array $headers = [], ?SiteClient $client = null): array
    {
        file_put_contents($this->recordFile, '');
        $response = ($client ?? $this->site())->request($method, $path, $headers);

        return [$response, $this->events()];
    }

    public function testEventsFireInTheLegacyOrder(): void
    {
        [$response, $events] = $this->recorded('GET', self::STATE);

        $this->assertSame(200, $response->status);
        $this->assertSame(['page/ready', 'page/head', 'page/get'], array_column($events, 'event'));
        foreach ($events as $event) {
            $this->assertSame('Idno\Legacy\ControllerPage', $event['current']);
            $this->assertSame('tests_fixtures_sitepagefixture', $event['body_class']);
            $this->assertSame('default', $event['type']);
            $this->assertFalse($event['permalink']);
            $this->assertNull($event['owner']);
        }
        // As a legacy page's: only page/get carries the class in its data; a GET's page/head carries the page itself.
        $this->assertSame([null, null, self::PAGE_CLASS], array_column($events, 'page_class'));
        $this->assertSame(
            ['site_page' => true, 'current' => 'Idno\Legacy\ControllerPage', 'page_class' => self::PAGE_CLASS, 'type' => 'default', 'xhr' => false, 'permalink' => false, 'owner' => null, 'input_q' => null],
            $response->json()
        );
    }

    public function testTheCurrentPageReadsTheRequestsInput(): void
    {
        $this->assertSame('hello', $this->site()->request('GET', self::STATE . '?q=hello')->json()['input_q']);
        $this->assertSame('/', $this->site()->request('GET', '/_test/site-page/state/')->header('Location') ?? '/', 'the optional trailing slash is a route of its own');
        $this->assertSame(200, $this->site()->request('GET', '/_test/site-page/state/')->status);
        $this->assertSame(200, $this->site()->request('GET', '//_test/site-page/state')->status);
    }

    public function testTheTemplateTypeFollowsTAndAccept(): void
    {
        $cases = [
            ['?_t=json', [], 'json'],
            ['?_t=md', [], 'markdown'],
            ['?_t=markdown', [], 'markdown'],
            ['', ['Accept' => 'application/json'], 'json'],
            ['', ['Accept' => 'application/activity+json'], 'activitypub'],
            ['?_t=rss', ['Accept' => 'application/json'], 'rss'],
            ['?_t=email', [], 'default'],
            ['?_t=classic', [], 'default'],
            ['?_t[]=json', [], 'default'],
            ['?_t=nosuchtype', [], 'default'],
            ['', [], 'default'],
        ];
        foreach ($cases as [$query, $headers, $type]) {
            $response = $this->site()->request('GET', self::STATE . $query, $headers);

            $this->assertSame(200, $response->status, $query);
            $this->assertSame($type, $response->json()['type'], $query . ' ' . json_encode($headers));
        }
    }

    public function testTheTypeIsKnownFromPageReadyWhenTheRequestNamesIt(): void
    {
        [, $events] = $this->recorded('GET', self::STATE . '?_t=json');
        $this->assertSame(['json', 'json', 'json'], array_column($events, 'type'));

        // Accept is read after page/ready, as for a legacy page: the first event still sees the default type.
        [, $events] = $this->recorded('GET', self::STATE, ['Accept' => 'application/json']);
        $this->assertSame(['default', 'json', 'json'], array_column($events, 'type'));
    }

    public function testXhr(): void
    {
        [$response, $events] = $this->recorded('GET', self::STATE, ['X-Requested-With' => 'XMLHttpRequest']);

        $this->assertSame(200, $response->status);
        $this->assertTrue($response->json()['xhr']);
        // The request is an XHR one from the start: a legacy page set its flag after page/ready (false, true, true).
        $this->assertSame([true, true, true], array_column($events, 'xhr'));
    }

    public function testTheStandardHeaders(): void
    {
        $legacy = $this->site()->request('GET', '/robots.txt');
        $response = $this->site()->request('GET', self::STATE);

        $this->assertSame('https://idno.co', $response->header('X-Powered-By'));
        $this->assertSame('GNU Terry Pratchett', $response->header('X-Clacks-Overhead'));
        $this->assertNotSame('', (string) $response->header('X-Idno-Build-Fingerprint'));
        foreach (['X-Powered-By', 'X-Clacks-Overhead', 'X-Idno-Build-Fingerprint'] as $name) {
            $this->assertSame($legacy->header($name), $response->header($name), $name . ' is what a legacy page sends');
        }
    }

    public function testThePageHeadListenersHeaderIsSent(): void
    {
        [$response] = $this->recorded('GET', self::STATE);

        $this->assertStringContainsString('1', (string) $response->header('X-Idno-Test-Page-Head'));
    }

    public function testOptionsAnswersAsPageOptionsDid(): void
    {
        [$response, $events] = $this->recorded('OPTIONS', self::STATE);

        $this->assertSame(204, $response->status);
        $this->assertSame('', $response->body);
        $this->assertSame('GET, POST, HEAD, OPTIONS, PUT, DELETE', $response->header('Access-Control-Allow-Methods'));
        $this->assertSame('86400', $response->header('Access-Control-Max-Age'));
        $this->assertSame(['page/ready'], array_column($events, 'event'), 'the page is begun and nothing more');
        $this->assertSame('Idno\Legacy\ControllerPage', $events[0]['current']);
    }

    public function testWalledGardenRefusesAnonymousAndAllowsAPublicPage(): void
    {
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_WALLED_GARDEN' => '1']));

        $this->assertSame(403, $client->request('GET', self::STATE)->status, 'a visitor, a page that is not public');
        $this->assertSame(200, $client->request('GET', '/_test/site-page/public')->status, 'a visitor, a public page');
        $member = $client->request('GET', self::STATE, ['Cookie' => $this->loginCookie('ada', 'correct-horse-battery-9')]);
        $this->assertSame(200, $member->status, 'a member');
        $this->assertTrue($member->json()['site_page']);
    }

    public function testARefusedRequestFiresNoPageHead(): void
    {
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_WALLED_GARDEN' => '1']));

        [$response, $events] = $this->recorded('GET', self::STATE, [], $client);

        $this->assertSame(403, $response->status);
        $this->assertNull($response->header('X-Idno-Test-Page-Head'));
        $this->assertSame(['page/ready'], array_column($events, 'event'));
        $this->assertSame('Idno\Legacy\ControllerPage', $events[0]['current']);

        [$allowed, $events] = $this->recorded('GET', '/_test/site-page/public', [], $client);
        $this->assertSame(200, $allowed->status);
        $this->assertSame(['page/ready', 'page/head', 'page/get'], array_column($events, 'event'), 'a control: a public page is let in');
    }

    public function testAnAccountPageRefusesAnApiRequest(): void
    {
        $path = '/_test/site-page/account';
        $headers = self::signedHeadersFor('ada', $this->apiKeyOf('ada'), $path);

        [$response, $events] = $this->recorded('GET', $path, $headers);

        $this->assertSame(403, $response->status);
        $this->assertNull($response->header('X-Idno-Test-Page-Head'));
        $this->assertSame(['page/ready'], array_column($events, 'event'), 'no page/head');
        $this->assertStringContainsString('API request to an account page refused: ' . self::PAGE_CLASS, $this->serverLogContents());

        [$browser, $events] = $this->recorded('GET', $path, ['Cookie' => $this->loginCookie('ada', 'correct-horse-battery-9')]);
        $this->assertSame(200, $browser->status, 'a browser session is let in');
        $this->assertSame(['page/ready', 'page/head', 'page/get'], array_column($events, 'event'));

        $other = '/_test/site-page/state';
        $this->assertSame(200, $this->site()->request('GET', $other, self::signedHeadersFor('ada', $this->apiKeyOf('ada'), $other))->status, 'a page that is not an account page answers an API request');
    }
}
