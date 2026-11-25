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
     * @param array<string, string>       $headers
     * @param string|array<string, string>|null $body
     *
     * @return array<string, mixed>
     */
    private function observe(SiteClient $client, string $method, string $path, string $pageClass, array $headers = [], string|array|null $body = null): array
    {
        file_put_contents($this->recordFile, '');
        $response = $client->request($method, $path, $headers, $body);
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

    private const FIXTURE_POST = '/_test/site-page/post';

    /**
     * A session cookie and the form token of its login form.
     *
     * @return array{0: array<string, string>, 1: array<string, string>} the headers that carry the session, and the token fields
     */
    private function sessionAndToken(): array
    {
        $form = $this->site()->request('GET', 'session/login');

        return [['Cookie' => self::sessionCookie($form)], self::formToken($form->body, 'session/login')];
    }

    public function testAPostWithItsTokenFiresTheSameEvents(): void
    {
        [$headers, $token] = $this->sessionAndToken();
        $legacy = $this->observe($this->site(), 'POST', self::LEGACY, 'Idno\Pages\Txt\Robots', $headers, $token);
        $fixture = $this->observe($this->site(), 'POST', self::FIXTURE_POST, 'Tests\Fixtures\SitePageFixture', $headers, $token);

        $this->assertSame(['page/ready', 'page/head', 'page/post'], array_column($legacy['events'], 'event'));
        $this->assertSame($legacy['events'], $fixture['events']);
        $this->assertSame($legacy['powered_by'], $fixture['powered_by']);
    }

    public function testAPostWithoutItsTokenIsSentAwayByBothButTheControllersPageNeverHearsOfIt(): void
    {
        [$headers] = $this->sessionAndToken();
        $legacy = $this->observe($this->site(), 'POST', self::LEGACY, 'Idno\Pages\Txt\Robots', $headers);
        $fixture = $this->observe($this->site(), 'POST', self::FIXTURE_POST, 'Tests\Fixtures\SitePageFixture', $headers);

        $this->assertSame(302, $legacy['status']);
        $this->assertSame(302, $fixture['status']);
        // The one difference, on purpose: a legacy page fired `page/post` and then checked the token; the controller's page checks first.
        $this->assertSame(['page/ready', 'page/head', 'page/post'], array_column($legacy['events'], 'event'));
        $this->assertSame(['page/ready', 'page/head'], array_column($fixture['events'], 'event'));
        $this->assertSame(array_slice($legacy['events'], 0, 2), $fixture['events']);
    }

    public function testAPutWithoutItsTokenIsAServerErrorForBoth(): void
    {
        [$headers] = $this->sessionAndToken();
        $legacy = $this->observe($this->site(), 'PUT', self::LEGACY, 'Idno\Pages\Txt\Robots', $headers);
        $fixture = $this->observe($this->site(), 'PUT', self::FIXTURE_POST, 'Tests\Fixtures\SitePageFixture', $headers);

        $this->assertSame(500, $legacy['status']);
        $this->assertSame(500, $fixture['status']);
        $this->assertSame(['page/ready', 'page/head', 'page/put'], array_column($legacy['events'], 'event'));
        $this->assertSame(['page/ready', 'page/head'], array_column($fixture['events'], 'event'));
    }

    public function testAMethodThePageDidNotDeclareIsA405WhereALegacyPageRanItsHandlersDefaults(): void
    {
        [$headers] = $this->sessionAndToken();
        // A legacy page has `put()` and `delete()` whatever it declares: they ask for the token and throw without one. PATCH it never had.
        $legacyPut = $this->site()->request('PUT', self::LEGACY, $headers);
        $legacyPatch = $this->site()->request('PATCH', self::LEGACY, $headers);
        $this->assertSame(500, $legacyPut->status);
        $this->assertSame(404, $legacyPatch->status);

        $fixture = $this->observe($this->site(), 'PUT', '/_test/site-page/undeclared', 'Tests\Fixtures\SitePageFixture', $headers);
        $this->assertSame(405, $fixture['status']);
        $this->assertSame([], $fixture['events'], 'nothing is begun or fired');
        $this->assertStringContainsString('GET', (string) $this->site()->request('PUT', '/_test/site-page/undeclared', $headers)->header('Allow'));
    }

    public function testPatchCanBeDeclaredAndThenAsksForTheFormTokenToo(): void
    {
        [$headers] = $this->sessionAndToken();
        $path = '/_test/site-page/patch';

        $this->assertSame(500, $this->site()->request('PATCH', $path, $headers)->status, 'a PATCH with no token is refused as a PUT is');
        $signed = $this->site()->request('PATCH', $path, self::signedHeadersFor('ada', $this->apiKeyOf('ada'), $path));
        $this->assertSame(200, $signed->status, 'a signed API request needs no token');
        $this->assertSame('PATCH', $signed->json()['method']);
    }

    public function testAnXhrPostWithABadTokenIsRefusedWhereALegacyPageAnswered200(): void
    {
        [$headers] = $this->sessionAndToken();
        $headers += ['X-Requested-With' => 'XMLHttpRequest'];

        $legacy = $this->observe($this->site(), 'POST', self::LEGACY, 'Idno\Pages\Txt\Robots', $headers);
        $fixture = $this->observe($this->site(), 'POST', self::FIXTURE_POST, 'Tests\Fixtures\SitePageFixture', $headers);

        // `forward()` does nothing for an XHR request, so a legacy page went on and answered 200 with no body; a controller's page is refused.
        $this->assertSame(200, $legacy['status']);
        $this->assertSame(403, $fixture['status']);
        $this->assertSame(['page/ready', 'page/head', 'page/post'], array_column($legacy['events'], 'event'));
        $this->assertSame(['page/ready', 'page/head'], array_column($fixture['events'], 'event'));
    }
}
