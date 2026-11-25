<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * The same request answered by a real legacy page and by the fixture page a controller serves: the events each fires,
 * in order and with their data, who is let in and refused, the headers every page sends and, for an XHR request, the
 * router's. The legacy pages are `/robots.txt` (`Idno\Pages\Txt\Robots`, a page that does nothing in its handler but
 * answer) and `/account/settings/` (an account page); the fixtures are `/_test/site-page/state` and `/account`.
 * Nothing is typed in from `PageContractTest`: both sides are run and compared, so a case the legacy tests do not
 * pin is held to the legacy page all the same.
 *
 * Not compared: the class of the current page and the body class (they name each page), and the body.
 */
final class SitePageLifecycleEquivalenceTest extends PrivateSiteTestCase
{
    use LogsIn;

    private const LEGACY = '/robots.txt';

    private const FIXTURE = '/_test/site-page/state';

    private const LEGACY_ACCOUNT = '/account/settings/';

    private const FIXTURE_ACCOUNT = '/_test/site-page/account';

    private const PASSWORD = 'correct-horse-battery-9';

    private string $recordFile = '';

    protected function setUp(): void
    {
        $this->recordFile = sys_get_temp_dir() . '/idno-site-pages-eq-' . bin2hex(random_bytes(6));
        parent::setUp();
        $this->addUser('ada', self::PASSWORD, null, true);
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
     * What a request did that the two pages must share: its status, the kind of its body, the headers every page sends, and
     * each event with its data (the page's own class named `PAGE`).
     *
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    private function observe(SiteClient $client, string $method, string $path, string $pageClass, array $headers = []): array
    {
        file_put_contents($this->recordFile, '');
        $response = $client->request($method, $path, $headers);
        $events = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($this->recordFile))) as $line) {
            $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $events[] = [
                'event' => $event['event'],
                'page_class' => $event['page_class'] === $pageClass ? 'PAGE' : $event['page_class'],
                'arguments' => $event['arguments'],
                'type' => $event['type'],
                'xhr' => $event['xhr'],
                'permalink' => $event['permalink'],
                'owner' => $event['owner'],
            ];
        }

        return [
            'status' => $response->status,
            'content_type' => strtolower(trim((string) strtok((string) $response->header('Content-Type'), ';'))),
            'powered_by' => $response->header('X-Powered-By'),
            'clacks' => $response->header('X-Clacks-Overhead'),
            'fingerprint' => $response->header('X-Idno-Build-Fingerprint'),
            'page_head_header' => $response->header('X-Idno-Test-Page-Head'),
            'events' => $events,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @param list<string>          $skip    the keys of the observation that the two are not asked to share
     */
    private function assertSameAnswer(string $method, array $headers = [], bool $account = false, ?SiteClient $client = null, array $skip = []): void
    {
        $client ??= $this->site();
        $legacyPath = $account ? self::LEGACY_ACCOUNT : self::LEGACY;
        $fixturePath = $account ? self::FIXTURE_ACCOUNT : self::FIXTURE;
        $legacyClass = $account ? 'Idno\Pages\Account\Settings' : 'Idno\Pages\Txt\Robots';
        $legacy = $this->observe($client, $method, $legacyPath, $legacyClass, $headers);
        $fixture = $this->observe($client, $method, $fixturePath, 'Tests\Fixtures\SitePageFixture', $headers);
        foreach ($skip as $key) {
            unset($legacy[$key], $fixture[$key]);
        }

        $this->assertSame($legacy, $fixture, $method . ' ' . json_encode($headers));
    }

    /**
     * @return array<string, string>
     */
    private function member(): array
    {
        return ['Cookie' => $this->loginCookie('ada', self::PASSWORD)];
    }

    public function testAGetFiresTheSameEventsAndSendsTheSameHeaders(): void
    {
        $this->assertSameAnswer('GET', skip: ['content_type']);
        $this->assertSameAnswer('GET', $this->member(), skip: ['content_type']);
    }

    public function testAHeadFiresTheSameEvents(): void
    {
        $this->assertSameAnswer('HEAD', skip: ['content_type']);
    }

    public function testOptionsFiresTheSameEventsAndAnswersTheSameStatus(): void
    {
        $this->assertSameAnswer('OPTIONS', skip: ['content_type']);
    }

    public function testAnXhrRequestFiresTheSameEventsAndGetsTheSameRouterHeaders(): void
    {
        $headers = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->assertSameAnswer('GET', $headers, skip: ['content_type']);
        $this->assertSameAnswer('HEAD', $headers, skip: ['content_type']);

        $legacy = $this->site()->request('GET', self::LEGACY, $headers);
        $fixture = $this->site()->request('GET', self::FIXTURE, $headers);
        $this->assertSame($legacy->header('Expires'), $fixture->header('Expires'));
        $this->assertSame($legacy->header('Pragma'), $fixture->header('Pragma'));
        $this->assertNotNull($legacy->header('Last-Modified'));
        $this->assertNotNull($fixture->header('Last-Modified'));
        $directives = static fn (SiteResponse $r): array => array_values(array_diff(array_map('trim', explode(',', (string) $r->header('Cache-Control'))), ['private']));
        $this->assertEqualsCanonicalizing($directives($legacy), $directives($fixture));
    }

    public function testAMethodsRefusalAndEventsAreTheSameOnAWalledGarden(): void
    {
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_WALLED_GARDEN' => '1']));

        // A visitor is refused (the page is not public) before page/head; a member is let in.
        $this->assertSameAnswer('GET', client: $client, skip: ['content_type']);
        $this->assertSameAnswer('HEAD', client: $client, skip: ['content_type']);
        $this->assertSameAnswer('GET', $this->member(), client: $client, skip: ['content_type']);
        $visitor = $this->observe($client, 'GET', self::FIXTURE, 'Tests\Fixtures\SitePageFixture');
        $this->assertSame(403, $visitor['status']);
        $this->assertSame([['page/ready']], [array_column($visitor['events'], 'event')]);
    }

    public function testAnApiRequestIsRefusedOnAnAccountPageAsOnALegacyOne(): void
    {
        $key = $this->apiKeyOf('ada');
        $legacy = $this->observe($this->site(), 'GET', self::LEGACY_ACCOUNT, 'Idno\Pages\Account\Settings', self::signedHeadersFor('ada', $key, self::LEGACY_ACCOUNT));
        $fixture = $this->observe($this->site(), 'GET', self::FIXTURE_ACCOUNT, 'Tests\Fixtures\SitePageFixture', self::signedHeadersFor('ada', $key, self::FIXTURE_ACCOUNT));

        $this->assertSame(403, $legacy['status']);
        // The same refusal: the status, the kind of body (JSON, for a signed request) and the events (the page begun, nothing more).
        $this->assertSame($legacy['status'], $fixture['status']);
        $this->assertSame($legacy['content_type'], $fixture['content_type']);
        // The events: the page begun, nothing more. The one difference is deliberate: the legacy router drew its 403 in a page it
        // built for the purpose (a second `page/ready`, for a page that is not the one refused); the controller's page is drawn in itself.
        $this->assertSame(['page/ready', 'page/ready'], array_column($legacy['events'], 'event'));
        $this->assertSame(['page/ready'], array_column($fixture['events'], 'event'));
        $this->assertSame($legacy['events'][0], $fixture['events'][0]);
        // The same request signed for a page that is not an account page is let in by both.
        $this->assertSame(200, $this->observe($this->site(), 'GET', self::LEGACY, 'Idno\Pages\Txt\Robots', self::signedHeadersFor('ada', $key, self::LEGACY))['status']);
        $this->assertSame(200, $this->observe($this->site(), 'GET', self::FIXTURE, 'Tests\Fixtures\SitePageFixture', self::signedHeadersFor('ada', $key, self::FIXTURE))['status']);
    }
}
