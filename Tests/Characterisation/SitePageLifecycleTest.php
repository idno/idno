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
            ['site_page' => true, 'current' => 'Idno\Legacy\ControllerPage', 'page_class' => self::PAGE_CLASS, 'type' => 'default', 'xhr' => false, 'permalink' => false, 'owner' => null, 'input_q' => null, 'arguments' => [], 'method' => 'GET'],
            $response->json()
        );
    }

    public function testTheCurrentPageReadsTheRequestsInput(): void
    {
        $this->assertSame('hello', $this->site()->request('GET', self::STATE . '?q=hello')->json()['input_q']);
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
        // As a legacy page's: the flag is set after page/ready.
        $this->assertSame([false, true, true], array_column($events, 'xhr'));
    }

    public function testAnXhrRequestGetsTheHeadersTheLegacyRouterSentForIt(): void
    {
        $legacy = $this->site()->request('GET', '/robots.txt', ['X-Requested-With' => 'XMLHttpRequest']);
        $response = $this->site()->request('GET', self::STATE, ['X-Requested-With' => 'XMLHttpRequest']);
        $plain = $this->site()->request('GET', self::STATE);

        $this->assertSame('Mon, 26 Jul 1997 05:00:00 GMT', $response->header('Expires'));
        $this->assertSame($legacy->header('Expires'), $response->header('Expires'));
        $this->assertSame('no-cache', $response->header('Pragma'));
        $this->assertNotNull($response->header('Last-Modified'));
        $directives = array_map('trim', explode(',', (string) $response->header('Cache-Control')));
        foreach (['no-store', 'no-cache', 'must-revalidate', 'post-check=0', 'pre-check=0'] as $directive) {
            $this->assertContains($directive, $directives, 'Cache-Control: ' . $response->header('Cache-Control'));
        }
        $this->assertStringStartsWith('application/json', (string) $response->header('Content-Type'), 'the page\'s own type');
        // Not for another request, and not for OPTIONS (a legacy page has no options_xhr).
        $this->assertNotSame('Mon, 26 Jul 1997 05:00:00 GMT', $plain->header('Expires'));
        $options = $this->site()->request('OPTIONS', self::STATE, ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertNotSame('Mon, 26 Jul 1997 05:00:00 GMT', $options->header('Expires'));
    }

    public function testAHeadRequestFiresHeadWithItsClassAndNoGet(): void
    {
        [$response, $events] = $this->recorded('HEAD', self::STATE);

        $this->assertSame(200, $response->status);
        $this->assertSame('', $response->body);
        $this->assertSame(['page/ready', 'page/head'], array_column($events, 'event'));
        // A legacy HEAD's page/head carries the class and arguments, not the page.
        $this->assertSame([null, self::PAGE_CLASS], array_column($events, 'page_class'));
        $this->assertSame([null, []], array_column($events, 'arguments'));
        $this->assertStringContainsString('1', (string) $response->header('X-Idno-Test-Page-Head'));
    }

    public function testTheAttributesDefaultTypeAppliesWhenTheRequestNamesNone(): void
    {
        $this->assertSame('json', $this->site()->request('GET', '/_test/site-page/typed')->json()['type']);
        $this->assertSame('default', $this->site()->request('GET', '/_test/site-page/typed?_t=default')->json()['type'], 'a type the request names wins');
        [, $events] = $this->recorded('GET', '/_test/site-page/typed');
        $this->assertSame(['json', 'json', 'json'], array_column($events, 'type'), 'known from page/ready');
    }

    public function testPageGetCarriesTheRoutesArguments(): void
    {
        [$response, $events] = $this->recorded('GET', '/_test/site-page/args/hello/');

        $this->assertSame(200, $response->status);
        $this->assertSame([null, null, ['hello']], array_column($events, 'arguments'));
        $this->assertSame(['hello'], $response->json()['arguments']);
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

    public function testAWalledGardenRefusalIsTheDrawn403AndIsNotLoggedAsAnError(): void
    {
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_WALLED_GARDEN' => '1']));

        $html = $client->request('GET', self::STATE);
        $this->assertSame(403, $html->status);
        $this->assertStringContainsString('text/html', (string) $html->header('Content-Type'));
        $this->assertStringContainsString('id="soft-forward"', $html->body, 'the site\'s 403 page of a visitor');
        $this->assertNull($html->header('Location'), 'no login redirect: a legacy refusal drew the 403 page too');
        $this->assertSame('GNU Terry Pratchett', $html->header('X-Clacks-Overhead'), 'the headers every page sends');

        // The type follows `_t`, as a legacy refusal's did (`publicGatekeeper()` ran before the `Accept` header was read), so the JSON
        // refusal is the JSON shell's, not a bare error object.
        $json = $client->request('GET', self::STATE . '?_t=json');
        $this->assertSame(403, $json->status);
        $this->assertStringContainsString('json', (string) $json->header('Content-Type'));
        $this->assertIsArray($json->json());
        $accept = $client->request('GET', self::STATE, ['Accept' => 'application/json']);
        $this->assertSame(403, $accept->status);
        $this->assertStringContainsString('text/html', (string) $accept->header('Content-Type'), 'an Accept header does not choose the type of a walled garden\'s refusal');

        $log = $this->serverLogContents();
        $this->assertStringNotContainsString('request.ERROR', $log);
        $this->assertStringNotContainsString('request.CRITICAL', $log);
    }

    public function testAnApiRefusalOnAnAccountPageIsAJson403AndIsNotLoggedAsAnError(): void
    {
        $path = '/_test/site-page/account';
        $headers = self::signedHeadersFor('ada', $this->apiKeyOf('ada'), $path);

        // A signed request is answered in JSON whatever it asked for, as the legacy refusal was (the JSON shell).
        foreach ([$headers, $headers + ['Accept' => 'application/json']] as $sent) {
            $response = $this->site()->request('GET', $path, $sent);

            $this->assertSame(403, $response->status);
            $this->assertSame(['error' => 'forbidden'], $response->json());
            $this->assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
        }

        $this->assertStringNotContainsString('request.ERROR', $this->serverLogContents());
    }

    public function testASignedOptionsRequestOnAnAccountPageIsRefusedAsALegacyOneWas(): void
    {
        $path = '/_test/site-page/account';

        [$response, $events] = $this->recorded('OPTIONS', $path, self::signedHeadersFor('ada', $this->apiKeyOf('ada'), $path));

        $this->assertSame(403, $response->status);
        $this->assertSame(['page/ready'], array_column($events, 'event'));
    }

    public function testAPostThePageDidNotDeclareIsRefusedBeforeAnyEvent(): void
    {
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            [$response, $events] = $this->recorded($method, '/_test/site-page/undeclared');

            $this->assertSame(405, $response->status, $method);
            $this->assertSame([], $events, $method . ': nothing fired');
            $this->assertStringContainsString('GET', (string) $response->header('Allow'), $method);
        }
    }

    /**
     * A session and the signed form token of its login form.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function sessionAndToken(): array
    {
        $form = $this->site()->request('GET', 'session/login');

        return [self::sessionCookie($form), self::formToken($form->body, 'session/login')];
    }

    public function testAPostNeedsTheFormTokenBeforePagePostFires(): void
    {
        $path = '/_test/site-page/post';
        [$cookie, $token] = $this->sessionAndToken();

        [$refused, $events] = $this->recorded('POST', $path, ['Cookie' => $cookie]);
        $this->assertSame(302, $refused->status, 'a POST without its token is sent away, as Page::post() did');
        $this->assertSame(['page/ready', 'page/head'], array_column($events, 'event'), 'no page/post');
        $this->assertStringContainsString('Invalid token', $this->serverLogContents());

        file_put_contents($this->recordFile, '');
        $accepted = $this->site()->request('POST', $path, ['Cookie' => $cookie], $token);
        $this->assertSame(200, $accepted->status);
        $this->assertSame('POST', $accepted->json()['method']);
        $this->assertSame(['page/ready', 'page/head', 'page/post'], array_column($this->events(), 'event'));
        $this->assertSame([null, null, self::PAGE_CLASS], array_column($this->events(), 'page_class'));
    }

    public function testAPutNeedsTheFormTokenToo(): void
    {
        $path = '/_test/site-page/post';
        [$cookie] = $this->sessionAndToken();

        [$response, $events] = $this->recorded('PUT', $path, ['Cookie' => $cookie]);

        $this->assertSame(500, $response->status, 'a legacy PUT without a token threw');
        $this->assertSame(['page/ready', 'page/head'], array_column($events, 'event'));
    }

    public function testASignedApiRequestNeedsNoFormToken(): void
    {
        $path = '/_test/site-page/post';

        [$response, $events] = $this->recorded('POST', $path, self::signedHeadersFor('ada', $this->apiKeyOf('ada'), $path));

        $this->assertSame(200, $response->status);
        $this->assertSame(['page/ready', 'page/head', 'page/post'], array_column($events, 'event'));
    }
}
