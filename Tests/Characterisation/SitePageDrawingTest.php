<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Idno\Templating\ShellHeaders;
use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;
use Tests\Templating\PageNormaliser;

/**
 * A site page drawn into a Response by a controller (`PageRenderer`), over HTTP on a site of the test's own, against the
 * same page drawn the way a legacy page draws it (`$t->__([...])->drawPage()`, `noContent()`, `forward()`, ...): the fixture
 * `Tests\Fixtures\LegacyDrawPage` is a real legacy page registered at `...draw-legacy` and the fixture controller serves
 * `...draw-new`; both do the same thing for each `case` and are asked the same way. The two answers are compared whole: the
 * status, every header (name, value, repetition and order) and the body, once the only thing that differs between them, the
 * name of the page in its own address, is made the same.
 *
 * Headers that belong to the transport and not to the page are left out of the comparison (`Date`, `Server`, `Connection`,
 * `Transfer-Encoding`). `Content-Length` is compared as
 * what it is: the length of that answer's own body (the two bodies differ in the name of their page), and for a HEAD that it is
 * there and the length of the GET's. A cookie is compared whole but for the session id and its date, and the space after the `;` of a media type is
 * not compared. Every other header is compared line by line, in the order sent: `Cache-Control` is the session's line (and the
 * page's own), never a second one a response computes.
 */
final class SitePageDrawingTest extends PrivateSiteTestCase
{
    use LogsIn;

    private const PASSWORD = 'correct-horse-battery-9';

    /** The directory each kind of page lives in: the root of the site, and the two that have a shell of their own. */
    private const AREAS = ['plain' => '_test/site-page/', 'admin' => 'admin/_test-site-page/', 'account' => 'account/_test-site-page/'];

    private const TYPES = ['default', 'json', 'jsonp', 'rss', 'jsonfeed', 'kml', 'markdown', 'activitypub'];

    private const TRANSPORT = ['date', 'server', 'connection', 'transfer-encoding', 'keep-alive'];

    private string $member = '';

    /** The client whose address `observed()` makes the same (the test's own site when null). */
    private ?SiteClient $siteClient = null;

    protected const SHARED_SITE = true;

    protected function seedSharedSite(): void
    {
        $this->addUser('ada', self::PASSWORD, null, true);
        // a member whose address the site blocks (for the signed request of a blocked member), and one who is neither blocked nor the administrator
        $this->addUser('bob');
        $this->addUser('bea', self::PASSWORD);
        $this->runInSite('$c = \Idno\Core\Idno::site()->config(); $c->blocked_emails = [\'bob@example.com\']; $c->save(); return true;');
        // the API key is made when it is first asked for: made here, so that no test makes it
        foreach (['ada', 'bob', 'bea'] as $handle) {
            $this->apiKeyOf($handle);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = $this->loginCookie('ada', self::PASSWORD);
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(string $page, string $area, string $query, array $headers, string $method = 'GET'): SiteResponse
    {
        return $this->site()->request($method, self::AREAS[$area] . $page . ($query === '' ? '' : '?' . $query), $headers);
    }

    /**
     * What the answer is, as a person would compare two answers: the status, each header by name (the values of a repeated
     * header in the order they were sent: HTTP gives no meaning to the order of different names, and PHP's own headers precede
     * the response's) and the body, normalised.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function observed(SiteResponse $response, string $area, string $page, bool $html, bool $head = false): array
    {
        // each LINE of each header, in the order sent: a repeated header is compared line by line, with nothing normalised but what is not a
        // line of the page's (the session's id and date, the spelling of a media type)
        $lines = [];
        foreach ($response->lines as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + ['', ''];
            $name = strtolower($name);
            if (in_array($name, self::TRANSPORT, true)) {
                continue;
            }
            $value = $this->named($value, $area, $page);
            if ($name === 'set-cookie') {
                // the session's id and its date are the only things that differ between two requests
                $value = preg_replace(['/^([^=;]+)=[^;]*/', '/expires=[^;]+/i'], ['$1=(id)', 'expires=(date)'], $value) ?? $value;
            } elseif ($name === 'content-length') {
                $value = $head || $value !== (string) strlen($response->body) ? ($head ? '(present)' : $value) : '(its body)';
            } elseif ($name === 'content-type') {
                // PHP appends its charset as `;charset=UTF-8`, a response as `; charset=UTF-8`: the same media type
                $value = preg_replace('/\s*;\s*/', ';', $value) ?? $value;
            }
            $lines[$name][] = $value;
        }
        $headers = array_map(static fn (array $values): string => implode("\n", $values), $lines);
        ksort($headers);

        $body = $this->named($response->body, $area, $page);
        if ($response->status >= 500) {
            // The one difference of a 500 page, a defect of the legacy one: a legacy page's exception was answered by the exception handler, after
            // the request had left the request stack, so the page's address in its structured data (the `url` of the JSON-LD `WebSite`, and nothing
            // else) was empty. A controller's page is answered while its request is the current one, and says its address.
            $body = preg_replace('/("@type": "WebSite",\s*"url": )"[^"]*"/', '$1"(the page)"', $body, 1) ?? $body;
        }

        return [
            'status' => $response->status,
            'headers' => $headers,
            'body' => PageNormaliser::normalise($body, ($this->siteClient ?? $this->site())->baseUrl(), $html),
        ];
    }

    /** Makes the name of the page in its own address the same in both. */
    private function named(string $text, string $area, string $page): string
    {
        // The address the visitor is sent back to after logging in, in the link of a refusal: base64 of the page's address
        $text = preg_replace_callback('/fwd=([A-Za-z0-9_-]+,{0,2})/', static function (array $match) use ($page): string {
            $decoded = base64_decode(strtr($match[1], '-_,', '+/='), true);

            return $decoded === false ? $match[0] : 'fwd=' . strtr(base64_encode(str_replace($page, 'draw-page', $decoded)), '+/=', '-_,');
        }, $text) ?? $text;

        // The stack trace the 500 page shows in debug mode: where each page threw, and what called it, is the one thing that differs
        $text = preg_replace('/Stack trace:\n.*?#\d+ \{main\}/s', 'Stack trace: {trace}', $text) ?? $text;

        // (and the class of the page, which the body's classes carry; the legacy router drew its refusal of an API request in a page of its own, BarePage)
        return str_replace([$page, 'tests_fixtures_legacydrawpage', 'tests_fixtures_legacyaccountdrawpage', 'tests_fixtures_sitepagefixture', 'idno_legacy_barepage'], ['draw-page', 'tests_fixtures_page', 'tests_fixtures_page', 'tests_fixtures_page', 'tests_fixtures_page'], $text);
    }

    /**
     * @param array<string, string> $headers
     */
    private function assertTheSamePage(string $query, array $headers = [], string $area = 'plain', string $method = 'GET'): SiteResponse
    {
        $legacy = $this->get('draw-legacy', $area, $query, $headers, $method);
        $new = $this->get('draw-new', $area, $query, $headers, $method);
        $head = $method === 'HEAD';
        $html = !str_contains($query, '_t=') || str_contains($query, '_t=default');
        $label = $method . ' ' . $area . ' ?' . $query . ' ' . json_encode(array_keys($headers));

        $observedLegacy = $this->observed($legacy, $area, 'draw-legacy', $html, $head);
        $observedNew = $this->observed($new, $area, 'draw-new', $html, $head);
        if (isset($headers['X-Requested-With']) && in_array($method, ['GET', 'HEAD'], true)) {
            // On purpose: the legacy router sent an XHR GET (and the HEAD that answers like it) its own caching lines (a 1997 `Expires`, two
            // `Cache-Control` lines, `Pragma`, `Last-Modified` now). A controller's page sends the lines of the same request that is not XHR,
            // which are what the legacy page sends that request (everything else is compared as it is).
            $plain = $this->observed($this->get('draw-legacy', $area, $query, array_diff_key($headers, ['X-Requested-With' => true]), $method), $area, 'draw-legacy', $html, $head);
            foreach (['expires', 'cache-control', 'pragma', 'last-modified'] as $name) {
                $this->assertSame($plain['headers'][$name] ?? null, $observedNew['headers'][$name] ?? null, $label . ' ' . $name . ' is the request\'s that is not XHR');
                unset($observedLegacy['headers'][$name], $observedNew['headers'][$name]);
            }
        }
        $this->assertSame($observedLegacy, $observedNew, $label);
        if (!$head && $new->header('Location') === null) {
            $this->assertSame((string) strlen($new->body), $new->header('Content-Length'), 'the length of what the controller drew: ' . $label);
        }

        return $new;
    }

    public function testADrawnPageInEveryTemplateType(): void
    {
        foreach (self::TYPES as $type) {
            // (the ActivityPub shell has something to say about a page that has an owner: a person)
            $response = $this->get('draw-new', 'plain', 'case=' . ($type === 'activitypub' ? 'permalink' : 'draw') . '&_t=' . $type, ['Cookie' => $this->member]);

            $this->assertSame(200, $response->status, $type);
            $this->assertNotSame('', trim($response->body), $type . ' has a body');
            $declared = ShellHeaders::for($type)[0] ?? null;
            $this->assertNotNull($declared, $type . ' declares a Content-Type');
            $this->assertStringStartsWith(strtolower(explode(';', $declared[1])[0]), strtolower((string) $response->header('Content-Type')), $type);
            if (in_array($type, ['json', 'jsonfeed', 'activitypub'], true)) {
                $this->assertIsArray(json_decode($response->body, true), $type . ' is JSON: ' . substr($response->body, 0, 200));
            }
            if ($type === 'markdown') {
                $this->assertStringStartsWith("---\n", $response->body);
            }
        }
    }

    public function testEveryTemplateTypeIsTheLegacyPageByteForByteForAVisitorAndAMember(): void
    {
        foreach (self::TYPES as $type) {
            foreach ([[], ['Cookie' => $this->member]] as $headers) {
                $this->assertTheSamePage('case=draw&_t=' . $type, $headers);
            }
        }
        // The type a request asks for with a header, and the web page's default
        $this->assertTheSamePage('case=draw', ['Accept' => 'application/json']);
        $this->assertTheSamePage('case=draw', ['Accept' => 'application/activity+json']);
        $this->assertTheSamePage('case=draw', ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertTheSamePage('case=draw', [], method: 'HEAD');
    }

    /**
     * @return list<array<string, string>> a visitor, then a member
     */
    private function callers(): array
    {
        return [[], ['Cookie' => $this->member]];
    }

    public function testTheSessionsCacheControlIsKeptByteForByte(): void
    {
        foreach (['draw', 'cached'] as $case) {
            foreach ($this->callers() as $headers) {
                $label = $case . ' ' . json_encode(array_keys($headers));
                $legacy = $this->get('draw-legacy', 'plain', 'case=' . $case, $headers);
                $new = $this->get('draw-new', 'plain', 'case=' . $case, $headers);

                // the lines PHP's session cache limiter queued, once each, and nothing a response computes (`no-cache, private`)
                $this->assertSame(['no-store, no-cache, must-revalidate'], $new->lines('Cache-Control'), $label);
                $this->assertSame(['no-cache'], $new->lines('Pragma'), $label);
                $this->assertCount(1, $new->lines('Expires'), $label);
                $this->assertSame($legacy->lines('Expires'), $new->lines('Expires'), $label);
                $this->assertSame([], $new->lines('ETag'), $label);
                $this->assertSame($case === 'cached' ? ['Tue, 14 Nov 2023 22:13:20 GMT'] : [], $new->lines('Last-Modified'), $label);
                $this->assertSame($legacy->lines('Last-Modified'), $new->lines('Last-Modified'), $label);
                $this->assertTheSamePage('case=' . $case, $headers);
            }
        }
    }

    public function testEveryCachingHeaderIsTheLegacyPagesInEveryTypeAndMethod(): void
    {
        foreach (['default', 'json', 'rss', 'markdown', 'activitypub'] as $type) {
            foreach (['cached', 'no-cache'] as $case) {
                $this->assertTheSamePage('case=' . $case . '&_t=' . $type, ['Cookie' => $this->member]);
            }
        }
        $this->assertTheSamePage('case=cached', [], method: 'HEAD');
        $this->assertTheSamePage('case=no-cache', ['Cookie' => $this->member], method: 'HEAD');
        // an XHR request gets the lines of the request that is not XHR, and a page that asks for no caching changes only what it asks for
        $this->assertTheSamePage('case=cached', ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertTheSamePage('case=no-cache', ['X-Requested-With' => 'XMLHttpRequest']);
    }

    public function testSetNoCacheReachesTheResponse(): void
    {
        foreach ($this->callers() as $headers) {
            $legacy = $this->get('draw-legacy', 'plain', 'case=no-cache', $headers);
            $new = $this->get('draw-new', 'plain', 'case=no-cache', $headers);

            // two `Cache-Control` lines, as `header()` sent them, and the session's `Expires` that `setNoCache()` leaves
            $this->assertSame(['no-store, no-cache, must-revalidate, max-age=0', 'post-check=0, pre-check=0'], $new->lines('Cache-Control'));
            $this->assertSame(['no-cache'], $new->lines('Pragma'));
            $this->assertSame($legacy->lines('Expires'), $new->lines('Expires'));
            $this->assertSame(['Thu, 19 Nov 1981 08:52:00 GMT'], $new->lines('Expires'));
            $this->assertTheSamePage('case=no-cache', $headers);
        }
        // an XHR request: the same lines (the router's own are not sent to a controller's page)
        $xhr = $this->get('draw-new', 'plain', 'case=no-cache', ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertSame(['Thu, 19 Nov 1981 08:52:00 GMT'], $xhr->lines('Expires'));
        $this->assertSame(['no-store, no-cache, must-revalidate, max-age=0', 'post-check=0, pre-check=0'], $xhr->lines('Cache-Control'));
    }

    public function testThePageHeadHeaderSurvives(): void
    {
        // The plugins' `page/head` and `page/ready` listeners send `Link` headers with `header()` (IndieAuth, Micropub, webmention): a
        // controller's response sends them too, once each, before the response's own
        foreach ($this->callers() as $headers) {
            $legacy = $this->get('draw-legacy', 'plain', 'case=draw', $headers);
            $new = $this->get('draw-new', 'plain', 'case=draw', $headers);

            $this->assertNotSame([], $legacy->lines('Link'), 'the legacy page has them');
            $this->assertSame(
                array_map(fn (string $line): string => $this->named($line, 'plain', 'draw-legacy'), $legacy->lines('Link')),
                array_map(fn (string $line): string => $this->named($line, 'plain', 'draw-new'), $new->lines('Link'))
            );
            $this->assertCount(count(array_unique($new->lines('Link'))), $new->lines('Link'), 'none twice');
        }
    }

    public function testLinkHeadersAreMerged(): void
    {
        // A Markdown page adds its canonical link beside the plugins' (one header name, several lines, none lost, in the order sent)
        $legacy = $this->get('draw-legacy', 'plain', 'case=draw&_t=markdown', ['Cookie' => $this->member]);
        $new = $this->get('draw-new', 'plain', 'case=draw&_t=markdown', ['Cookie' => $this->member]);

        $this->assertSame(
            array_map(fn (string $line): string => $this->named($line, 'plain', 'draw-legacy'), $legacy->lines('Link')),
            array_map(fn (string $line): string => $this->named($line, 'plain', 'draw-new'), $new->lines('Link'))
        );
        $this->assertContains('rel="canonical"', array_map(static fn (string $l): string => substr($l, (int) strrpos($l, ';') + 2), $new->lines('Link')));
        $this->assertGreaterThan(1, count($new->lines('Link')));
    }

    public function testNoXRobotsTagInDebugMode(): void
    {
        // Symfony adds `X-Robots-Tag: noindex` to every response in debug mode (the test site's); a page of the site never had it
        foreach (['draw', 'notfound', 'error', 'redirect'] as $case) {
            foreach ($this->callers() as $headers) {
                $this->assertSame([], $this->get('draw-legacy', 'plain', 'case=' . $case, $headers)->lines('X-Robots-Tag'), 'legacy ' . $case);
                $this->assertSame([], $this->get('draw-new', 'plain', 'case=' . $case, $headers)->lines('X-Robots-Tag'), 'new ' . $case);
            }
        }
        // and the responses of the other controllers keep what they have
        $this->assertSame(['noindex'], $this->site()->request('GET', '_test/ping')->lines('X-Robots-Tag'));
    }

    public function testAPageThatSaysNoindexKeepsItInDebugAndProdModeAndAPageThatDoesNotNeverGetsOne(): void
    {
        // In debug mode Symfony adds `X-Robots-Tag: noindex` to every response; only what it added is taken off a site page's
        foreach (['debug' => $this->site(), 'prod' => new HttpSiteClient($this->startExtraServer(['APP_DEBUG' => '0']))] as $mode => $client) {
            foreach ($this->callers() as $headers) {
                $label = $mode . ' ' . json_encode(array_keys($headers));
                $this->assertSame(['noindex'], $client->request('GET', self::AREAS['plain'] . 'draw-new?case=robots', $headers)->lines('X-Robots-Tag'), 'its own response: ' . $label);
                $this->assertSame(['noindex'], $client->request('GET', self::AREAS['plain'] . 'draw-new?case=robots-context', $headers)->lines('X-Robots-Tag'), 'its context: ' . $label);
                $this->assertSame([], $client->request('GET', self::AREAS['plain'] . 'draw-new?case=draw', $headers)->lines('X-Robots-Tag'), 'nothing said: ' . $label);
            }
        }
    }

    public function testCachingIsOnlyWhatThePageAskedForTheSupportedWay(): void
    {
        foreach ($this->callers() as $headers) {
            $asked = $this->get('draw-new', 'plain', 'case=context-caching', $headers);
            $this->assertSame(['public, max-age=45'], $asked->lines('Cache-Control'), 'exactly as asked');
            $this->assertSame(['Thu, 19 Nov 1981 08:52:00 GMT'], $asked->lines('Expires'), 'a name it did not ask for is the session\'s');
            $this->assertSame(['no-cache'], $asked->lines('Pragma'));
        }
    }

    public function testAResponseThatMerelyCarriesCachingHeadersNeverMakesAPageMoreCacheable(): void
    {
        // `BinaryFileResponse` sets `Cache-Control: public` itself, and a controller may set the three on any response: none is the page
        // asking for caching. Debug mode refuses a value that is not one the framework sets by itself (so that a developer notices);
        // otherwise they are ignored, the session's lines go out, and one warning is logged.
        $session = ['no-store, no-cache, must-revalidate'];
        // a server of its own (the label): the warning is once for each process of a server, so another test's requests to it would count
        $prodUrl = $this->startExtraServer(['APP_DEBUG' => '0', 'IDNO_TEST_SERVER_LABEL' => 'caching-warning']);
        $prod = new HttpSiteClient($prodUrl);
        foreach ($this->callers() as $headers) {
            $label = json_encode(array_keys($headers));
            foreach (['binary', 'binary-public', 'stream'] as $case) {
                foreach (['debug' => $this->site(), 'prod' => $prod] as $mode => $client) {
                    $response = $client->request('GET', self::AREAS['plain'] . 'draw-new?case=' . $case, $headers);
                    $this->assertSame(200, $response->status, "$case $mode $label");
                    $this->assertSame($session, $response->lines('Cache-Control'), "$case $mode $label");
                    $this->assertSame(['no-cache'], $response->lines('Pragma'), "$case $mode $label");
                    $this->assertSame(['Thu, 19 Nov 1981 08:52:00 GMT'], $response->lines('Expires'), "$case $mode $label");
                    $this->assertSame([], $response->lines('ETag'), "$case $mode $label");
                }
            }
            foreach (['binary-maxage', 'response-caching'] as $case) {
                $debug = $this->get('draw-new', 'plain', 'case=' . $case, $headers);
                $this->assertSame(500, $debug->status, "$case debug $label");
                $this->assertStringContainsString('setCaching()', $debug->body, "$case debug $label");

                $response = $prod->request('GET', self::AREAS['plain'] . 'draw-new?case=' . $case, $headers);
                $this->assertSame(200, $response->status, "$case prod $label");
                $this->assertSame($session, $response->lines('Cache-Control'), "$case prod $label");
                $this->assertSame(['Thu, 19 Nov 1981 08:52:00 GMT'], $response->lines('Expires'), "$case prod $label");
                $this->assertSame(['no-cache'], $response->lines('Pragma'), "$case prod $label");
            }
        }
        $log = (string) file_get_contents($this->extraServerLog($prodUrl));
        // once for each process of the server (it has four workers), not once for each request
        $warnings = substr_count($log, 'on a site page\'s response is not the page asking for caching');
        $this->assertGreaterThanOrEqual(1, $warnings, 'a warning is logged');
        $this->assertLessThanOrEqual(4, $warnings, 'one a process, not one a request: ' . $warnings);
    }

    public function testAFileASitePageServesKeepsWhatTheLegacyFilePageSent(): void
    {
        // `Idno\Pages\File\View` sent one of three sets of lines by `header()`, and `Last-Modified` of the entity, and no `ETag`: the page asks
        // for them the supported way, also on a `BinaryFileResponse`, which sets its own `Last-Modified` and `public`
        $expected = [
            'file-public' => ['Cache-Control' => ['public'], 'Pragma' => ['public'], 'Expires' => ['Fri, 01 Jan 2027 00:00:00 GMT']],
            'file-restricted' => ['Cache-Control' => ['private, no-cache'], 'Pragma' => ['no-cache'], 'Expires' => ['Thu, 19 Nov 1981 08:52:00 GMT']],
            'file-admin' => ['Cache-Control' => ['private, no-store'], 'Pragma' => ['no-cache'], 'Expires' => ['Thu, 19 Nov 1981 08:52:00 GMT']],
        ];
        foreach ($this->callers() as $headers) {
            foreach ($expected as $case => $lines) {
                foreach (['', 'bytes=0-9'] as $range) {
                    $response = $this->get('draw-new', 'plain', 'case=' . $case, $headers + ($range === '' ? [] : ['Range' => $range]));
                    $label = $case . ' ' . $range;

                    $this->assertSame($range === '' ? 200 : 206, $response->status, $label);
                    foreach ($lines as $name => $values) {
                        $this->assertSame($values, $response->lines($name), $label . ' ' . $name);
                    }
                    $this->assertSame(['Tue, 14 Nov 2023 22:13:20 GMT'], $response->lines('Last-Modified'), $label . ': the entity\'s, not the file\'s');
                    $this->assertSame([], $response->lines('ETag'), $label);
                }
            }
        }
    }

    public function testAnOptionsRequestIsAnsweredAsALegacyPageAnswersIt(): void
    {
        $legacy = $this->get('draw-legacy', 'plain', 'case=draw', [], 'OPTIONS');
        $new = $this->get('draw-new', 'plain', 'case=draw', [], 'OPTIONS');

        $this->assertSame(204, $legacy->status);
        $this->assertSame(['text/html; charset=UTF-8'], $legacy->lines('Content-Type'), 'PHP\'s own default type');
        $this->assertSame($legacy->status, $new->status);
        $this->assertSame($legacy->lines('Content-Type'), $new->lines('Content-Type'));
        $this->assertSame($legacy->lines('Access-Control-Allow-Methods'), $new->lines('Access-Control-Allow-Methods'));
        $this->assertSame($legacy->lines('Cache-Control'), $new->lines('Cache-Control'));
        // and the process goes on answering as it did: an empty answer does not take PHP's default type from the pages that follow
        $this->assertNotSame([], $this->get('draw-legacy', 'plain', 'case=draw', [])->lines('Content-Type'));
    }

    public function testAGonePageASignedApiRequestAndAnXhrHeadAreTheLegacyPages(): void
    {
        $key = $this->apiKeyOf('ada');
        foreach (['draw', 'gone', 'notfound', 'cached'] as $case) {
            $path = static fn (string $page): string => self::AREAS['plain'] . $page . '?case=' . $case;
            $legacy = $this->site()->request('GET', $path('draw-legacy'), self::signedHeadersFor('ada', $key, '/' . $path('draw-legacy')));
            $new = $this->site()->request('GET', $path('draw-new'), self::signedHeadersFor('ada', $key, '/' . $path('draw-new')));

            // a signed request is stateless, and the session's lines are still PHP's: what a legacy page sent is what is sent
            $this->assertSame($legacy->lines('Cache-Control'), $new->lines('Cache-Control'), $case);
            $this->assertSame($legacy->lines('Expires'), $new->lines('Expires'), $case);
            $this->assertSame($legacy->lines('Pragma'), $new->lines('Pragma'), $case);
            $this->assertSame($legacy->lines('Last-Modified'), $new->lines('Last-Modified'), $case);
            $this->assertSame($legacy->status, $new->status, $case);
        }
        $this->assertTheSamePage('case=gone', [], method: 'HEAD');
        $this->assertTheSamePage('case=gone', ['Cookie' => $this->member]);
        $this->assertTheSamePage('case=draw', ['X-Requested-With' => 'XMLHttpRequest'], method: 'HEAD');
        $this->assertTheSamePage('case=cached', ['X-Requested-With' => 'XMLHttpRequest'], method: 'HEAD');
    }

    public function testTheSettingsShellsAreDrawnForTheDirectoriesThatHaveOne(): void
    {
        foreach (['admin', 'account'] as $area) {
            foreach (self::TYPES as $type) {
                $this->assertTheSamePage('case=draw&_t=' . $type, ['Cookie' => $this->member], $area);
            }
            $this->assertTheSamePage('case=draw', ['Cookie' => $this->member], $area);
        }
        // JSONP of a settings page is the HTML settings shell, never a script
        $response = $this->get('draw-new', 'admin', 'case=draw&_t=jsonp&callback=f', ['Cookie' => $this->member]);
        $this->assertStringStartsWith('text/html', (string) $response->header('Content-Type'));
        $this->assertSame('nosniff', $response->header('X-Content-Type-Options'));
    }

    public function testThePagesALegacyPageAnswersWithAreTheSameWhenDrawn(): void
    {
        foreach (['notfound', 'gone', 'denied', 'error', 'technical'] as $case) {
            foreach (['default', 'json', 'jsonp', 'markdown', 'rss'] as $type) {
                $response = $this->assertTheSamePage('case=' . $case . '&title=No+entry&_t=' . $type, ['Cookie' => $this->member]);
                $this->assertSame(['notfound' => 404, 'gone' => 410, 'denied' => 403, 'error' => 500, 'technical' => 500][$case], $response->status, $case . ' ' . $type);
            }
            $this->assertTheSamePage('case=' . $case . '&title=No+entry');
            $this->assertTheSamePage('case=' . $case, ['Cookie' => $this->member], 'admin');
        }
    }

    public function testAForwardIsTheSameRedirect(): void
    {
        foreach (['', '_t=json', '_t=rss'] as $type) {
            $response = $this->assertTheSamePage('case=redirect&to=' . rawurlencode($this->site()->baseUrl() . 'content/all') . '&' . $type, ['Cookie' => $this->member]);
            $this->assertSame(302, $response->status);
            $this->assertNotNull($response->header('Location'));
            $response = $this->assertTheSamePage('case=redirect&' . $type, ['Cookie' => $this->member]);
            $this->assertSame($this->site()->baseUrl(), $response->header('Location') === null ? '' : preg_replace('/\?.*$/', '', $response->header('Location')));
        }
    }

    public function testAForwardOfAPageThatSetA201KeepsIt(): void
    {
        $response = $this->assertTheSamePage('case=redirect201&to=' . rawurlencode('https://example.com/created'), ['Cookie' => $this->member]);

        $this->assertSame(201, $response->status, 'PHP sends a 201 with its Location as it is');
        $this->assertSame('https://example.com/created', $response->header('Location'));
    }

    public function testAForwardForASignedApiRequestIsTheJsonAPageEchoed(): void
    {
        $key = $this->apiKeyOf('ada');
        $path = static fn (string $page): string => '/' . self::AREAS['plain'] . $page . '?case=redirect&to=' . rawurlencode('https://example.com/elsewhere');
        $sign = fn (string $page): array => self::signedHeadersFor('ada', $key, $path($page));
        $legacy = $this->site()->request('GET', ltrim($path('draw-legacy'), '/'), $sign('draw-legacy'));
        $new = $this->site()->request('GET', ltrim($path('draw-new'), '/'), $sign('draw-new'));

        $this->assertSame(200, $legacy->status);
        $this->assertSame(200, $new->status);
        $this->assertNull($new->header('Location'));
        // (a signed request is answered in JSON, so the location carries the type)
        $this->assertSame(['location' => 'https://example.com/elsewhere?_t=json'], $new->json());
        $this->assertSame($legacy->body, $new->body);
        // The one difference: the page echoed the JSON with no type of its own (so `text/html`); the response says what it is.
        $this->assertStringStartsWith('application/json', (string) $new->header('Content-Type'));
    }

    public function testAHeadAnswerHasTheLengthOfTheGet(): void
    {
        foreach (['legacy', 'new'] as $side) {
            $get = $this->get('draw-' . $side, 'plain', 'case=draw', ['Cookie' => $this->member]);
            $head = $this->get('draw-' . $side, 'plain', 'case=draw', ['Cookie' => $this->member], 'HEAD');

            $this->assertSame((string) strlen($get->body), $get->header('Content-Length'), $side . ' GET');
            $this->assertSame($get->header('Content-Length'), $head->header('Content-Length'), $side . ' HEAD');
            $this->assertSame('', $head->body);
        }
    }

    public function testAForwardForASignedRequestOfABlockedMemberIsTheSameJson(): void
    {
        // The signed request is an API request that no member is let in by: the page is answered as an API request all the same
        $key = $this->apiKeyOf('bob');
        $path = static fn (string $page): string => '/' . self::AREAS['plain'] . $page . '?case=redirect&to=' . rawurlencode('https://example.com/elsewhere');
        $legacy = $this->site()->request('GET', ltrim($path('draw-legacy'), '/'), self::signedHeadersFor('bob', $key, $path('draw-legacy')));
        $new = $this->site()->request('GET', ltrim($path('draw-new'), '/'), self::signedHeadersFor('bob', $key, $path('draw-new')));

        $this->assertSame(200, $legacy->status);
        $this->assertSame(200, $new->status);
        $this->assertNull($new->header('Location'), 'the JSON, not a redirect');
        $this->assertSame($legacy->body, $new->body);
        $this->assertSame(['location' => 'https://example.com/elsewhere?_t=json'], $new->json());
    }

    public function testAForwardTheSiteAsksToTraceCarriesItsTrace(): void
    {
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_FORWARD_TRACE' => '1']));
        $legacy = $client->request('GET', self::AREAS['plain'] . 'draw-legacy?case=redirect&to=' . rawurlencode('https://example.com/x'));
        $new = $client->request('GET', self::AREAS['plain'] . 'draw-new?case=redirect&to=' . rawurlencode('https://example.com/x'));

        $this->assertStringStartsWith('Forward at ', (string) $legacy->header('X-Idno-Forward-Trace'));
        $this->assertStringStartsWith('Forward at ', (string) $new->header('X-Idno-Forward-Trace'));
        $this->assertStringContainsString(', called by ', (string) $new->header('X-Idno-Forward-Trace'));
    }

    public function testMessagesAreShownOnceAndFlushed(): void
    {
        $first = $this->get('draw-new', 'plain', 'case=drawmessage', ['Cookie' => $this->member]);
        $this->assertSame(200, $first->status);
        $this->assertStringContainsString('A message to be shown once', $first->body);

        $second = $this->get('draw-new', 'plain', 'case=draw', ['Cookie' => $this->member]);
        $this->assertStringNotContainsString('A message to be shown once', $second->body);

        // and the same for the legacy page
        $legacy = $this->get('draw-legacy', 'plain', 'case=drawmessage', ['Cookie' => $this->member]);
        $this->assertStringContainsString('A message to be shown once', $legacy->body);
        $this->assertStringNotContainsString('A message to be shown once', $this->get('draw-legacy', 'plain', 'case=draw', ['Cookie' => $this->member])->body);

        // A message is used up by the page that draws it, in the same way: drawn by both at their first request
        $legacy = $this->get('draw-legacy', 'plain', 'case=drawmessage', ['Cookie' => $this->member]);
        $new = $this->get('draw-new', 'plain', 'case=drawmessage', ['Cookie' => $this->member]);
        $this->assertSame(
            $this->observed($legacy, 'plain', 'draw-legacy', true),
            $this->observed($new, 'plain', 'draw-new', true)
        );
    }

    public function testAMessageAddedBeforeARedirectIsShownByThePageItGoesTo(): void
    {
        // The redirect that follows a message keeps it for the page it goes to
        $this->get('draw-new', 'plain', 'case=messages', ['Cookie' => $this->member]);

        $response = $this->get('draw-new', 'plain', 'case=draw', ['Cookie' => $this->member]);
        $this->assertStringContainsString('A message to be shown once', $response->body);
        $this->assertStringNotContainsString('A message to be shown once', $this->get('draw-new', 'plain', 'case=draw', ['Cookie' => $this->member])->body);
    }

    public function testPermalinkAndOwnerReachTheTemplates(): void
    {
        $plain = $this->get('draw-new', 'plain', 'case=draw', ['Cookie' => $this->member]);
        $permalink = $this->get('draw-new', 'plain', 'case=permalink', ['Cookie' => $this->member]);

        $this->assertSame(200, $permalink->status);
        // pages/home/blurb draws the page's owner (the member's card), and only for a page that has one
        $this->assertStringNotContainsString('h-card', $plain->body);
        $this->assertStringContainsString('h-card', $permalink->body);
        $this->assertStringContainsString('Ada', $permalink->body);

        $this->assertTheSamePage('case=permalink', ['Cookie' => $this->member]);
        $this->assertTheSamePage('case=permalink&_t=json', ['Cookie' => $this->member]);
        $this->assertTheSamePage('case=permalink&_t=markdown', ['Cookie' => $this->member]);
    }

    // ------------------------------------------------------------------ what a page that stops answers with

    /**
     * What the site logged since `$before` was read: the lines of its own log, without the web server's own (a connection accepted and
     * closed) and the warning every request of a site with no asynchronous queue writes.
     *
     * @return list<string>
     */
    private function loggedSince(string $before): array
    {
        $added = substr($this->serverLogContents(), strlen($before));

        return array_values(array_filter(
            explode("\n", $added),
            static fn (string $line): bool => $line !== '' && preg_match('/ (Accepted|Closing)$|ActivityPub requires the asynchronous event queue/', $line) !== 1
        ));
    }

    /** What a case answers with, by its name. */
    private const STOPS = ['throw-missing' => 404, 'throw-gone' => 410, 'throw-denied' => 403, 'throw-runtime' => 500, 'throw-technical' => 500, 'throw-error' => 500];

    public function testErrorPages(): void
    {
        $titles = ['throw-missing' => "This page can't be found.", 'throw-gone' => "This content isn't here."];
        foreach (self::STOPS as $case => $status) {
            // thrown directly, and through the current page as legacy code calls it (`via=page`), in the types a request may ask for
            foreach (['', '&via=page'] as $via) {
                foreach (['default', 'json', 'jsonp', 'markdown', 'rss'] as $type) {
                    $response = $this->assertTheSamePage('case=' . $case . '&title=No+entry&_t=' . $type . $via, ['Cookie' => $this->member]);
                    $this->assertSame($status, $response->status, $case . $via . ' ' . $type);
                }
                $response = $this->assertTheSamePage('case=' . $case . '&title=No+entry' . $via);
                $this->assertSame($status, $response->status);
                // the site's page, with its title
                $this->assertStringContainsString('<title>' . ($titles[$case] ?? ($case === 'throw-denied' ? 'No entry' : 'Exception')) . '</title>', html_entity_decode($response->body, ENT_QUOTES), $case);
                if ($status === 500) {
                    // A legacy 500 page was drawn by the exception handler, after the request had left the request stack, so the shell of a
                    // settings page was chosen without its address: an `/admin` page that threw was drawn in the account shell. A controller's
                    // page is drawn in the shell of its own directory.
                    $this->assertStringContainsString('idno-admin-main', $this->get('draw-new', 'admin', 'case=' . $case . $via, ['Cookie' => $this->member])->body);
                    $this->assertStringContainsString('idno-account-main', $this->get('draw-new', 'account', 'case=' . $case . $via, ['Cookie' => $this->member])->body);

                    continue;
                }
                $this->assertTheSamePage('case=' . $case . $via, ['Cookie' => $this->member], 'admin');
                $this->assertTheSamePage('case=' . $case . $via, [], 'account');
            }
        }
    }

    /**
     * Every refusal of a site page is drawn ONE way, as a legacy page's `deniedContent()` was: Symfony Security's `#[IsGranted]`, the refusal of an
     * API request on an account page, the private site's refusal of a visitor and the page's own `deniedContent()`. Each is compared, status, headers
     * and body, with the legacy page's refusal of the same caller and template type: the page's own `deniedContent()` for the first and the last,
     * the legacy router's for the two the router made (an API request on an account page, a visitor of a private site).
     */
    public function testEveryRefusalOfASitePageIsDrawnAsALegacyPagesDeniedContent(): void
    {
        $member = $this->loginCookie('bea', self::PASSWORD);
        $key = $this->apiKeyOf('bea');
        foreach (['', '&_t=json', '&_t=markdown'] as $type) {
            $query = 'case=throw-denied&via=page' . $type;
            $html = $type === '';

            // #[IsGranted('ROLE_ADMIN')], for a visitor and a member who is no administrator; and the page's own deniedContent()
            foreach (['visitor' => [], 'member' => ['Cookie' => $member]] as $caller => $headers) {
                $legacy = $this->observed($this->get('draw-legacy', 'plain', $query, $headers), 'plain', 'draw-legacy', $html);
                $granted = $this->get('draw-granted', 'plain', $query, $headers);
                $this->assertSame(403, $granted->status, $caller . $type);
                $this->assertSame($legacy, $this->observed($granted, 'plain', 'draw-granted', $html), '#[IsGranted], ' . $caller . $type);
                $this->assertSame($legacy, $this->observed($this->get('draw-new', 'plain', $query, $headers), 'plain', 'draw-new', $html), 'deniedContent(), ' . $caller . $type);
            }

            // A signed API request on an account page: the legacy router refused it before the page ran
            $legacyPath = self::AREAS['account'] . 'draw-legacy?' . $query;
            $newPath = self::AREAS['account'] . 'draw-new?' . $query;
            $legacy = $this->site()->request('GET', $legacyPath, self::signedHeadersFor('bea', $key, '/' . $legacyPath));
            $new = $this->site()->request('GET', $newPath, self::signedHeadersFor('bea', $key, '/' . $newPath));
            $this->assertSame(403, $legacy->status, 'the legacy refusal' . $type);
            $this->assertSame($this->observed($legacy, 'account', 'draw-legacy', $html), $this->observed($new, 'account', 'draw-new', $html), 'an API request on an account page' . $type);
        }

        // A visitor of a private site (`testAWalledGardenRefusalIsTheDrawn403` has the other types)
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_WALLED_GARDEN' => '1']));
        $legacy = $client->request('GET', self::AREAS['plain'] . 'draw-legacy?case=draw');
        $new = $client->request('GET', self::AREAS['plain'] . 'draw-granted?case=draw');
        $this->assertSame(403, $new->status);
        $this->assertSame($this->observedBy($client, $legacy, 'draw-legacy', true), $this->observedBy($client, $new, 'draw-granted', true), 'a visitor of a private site');
    }

    public function testAMissingPageOfAnXhrRequestAndAHeadRequestIsTheSame(): void
    {
        $this->assertTheSamePage('case=throw-missing', ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertTheSamePage('case=throw-missing', [], method: 'HEAD');
        $this->assertTheSamePage('case=throw-runtime', ['Cookie' => $this->member], method: 'HEAD');
    }

    public function testAForwardCalledByLegacyCodeIsTheSameRedirect(): void
    {
        foreach (['', '&_t=json', '&_t=rss'] as $type) {
            $response = $this->assertTheSamePage('case=throw-redirect&to=' . rawurlencode($this->site()->baseUrl() . 'content/all') . $type, ['Cookie' => $this->member]);
            $this->assertSame(302, $response->status);
            $this->assertNotNull($response->header('Location'));
        }
        $key = $this->apiKeyOf('ada');
        $path = static fn (string $page): string => '/' . self::AREAS['plain'] . $page . '?case=throw-redirect&to=' . rawurlencode('https://example.com/elsewhere');
        $legacy = $this->site()->request('GET', ltrim($path('draw-legacy'), '/'), self::signedHeadersFor('ada', $key, $path('draw-legacy')));
        $new = $this->site()->request('GET', ltrim($path('draw-new'), '/'), self::signedHeadersFor('ada', $key, $path('draw-new')));
        $this->assertSame($legacy->body, $new->body, 'an API request is given the JSON, not a redirect');
        $this->assertSame(200, $new->status);
    }

    public function testARedirectAndA404LogNothing(): void
    {
        foreach (['case=throw-missing', 'case=throw-missing&via=page', 'case=throw-gone', 'case=throw-denied', 'case=throw-redirect&to=' . rawurlencode('https://example.com/x')] as $query) {
            $before = $this->serverLogContents();

            $response = $this->get('draw-new', 'plain', $query, ['Cookie' => $this->member]);

            $this->assertContains($response->status, [302, 403, 404, 410], $query);
            $this->assertSame([], $this->loggedSince($before), 'no line is logged for ' . $query);
        }
    }

    public function testA500LogsOneCriticalLine(): void
    {
        foreach (['throw-runtime' => 'Broken <b>page</b>', 'throw-error' => 'A PHP <b>type</b> error in the page', 'throw-5xx' => 'Down for <b>maintenance</b>'] as $case => $message) {
            $lines = [];
            foreach (['legacy', 'new'] as $side) {
                $before = $this->serverLogContents();

                $response = $this->get('draw-' . $side, 'plain', 'case=' . $case, ['Cookie' => $this->member]);

                $this->assertSame($side === 'new' && $case === 'throw-5xx' ? 503 : 500, $response->status, $side . ' ' . $case);
                $added = implode("\n", $this->loggedSince($before));
                // (the server's own prefix, its process and the time, differs between two requests)
                $lines[$side] = array_values(array_filter(
                    array_map(static fn (string $line): string => preg_replace('/^\[\d+\] \[[^\]]+\] /', '', $line) ?? $line, $this->loggedSince($before)),
                    static fn (string $line): bool => str_contains($line, $message)
                ));
                $this->assertCount(1, $lines[$side], $side . ' ' . $case . ': one line, however many handlers saw the exception: ' . $added);
                $this->assertCount(1, $this->loggedSince($before), $side . ' ' . $case . ': and nothing else');
                $this->assertStringNotContainsString('request.CRITICAL', $added, $side);
                $this->assertStringNotContainsString('request.ERROR', $added, $side);
                $this->assertStringNotContainsString('Fatal', $added, $side);
            }
            $this->assertMatchesRegularExpression('/: critical - ' . preg_quote($message, '/') . ' \[.+(LegacyDrawPage|SitePageFixtureController)\.php:\d+\]$/', $lines['new'][0], 'the site logger\'s line');
            // the same line: the same function made both exceptions, so the file and line are the same
            $this->assertSame($lines['legacy'][0], $lines['new'][0], $case);
        }
    }

    public function testAnExceptionThrownAfterOutputBeganIsAnsweredAsALegacyPageDid(): void
    {
        $response = $this->assertTheSamePage('case=throw-echo', ['Cookie' => $this->member]);

        $this->assertStringStartsWith('Output before the exception. ', $response->body);
        $this->assertStringContainsString('Something went wrong', $response->body);
    }

    public function testAnErrorIsTheSites500PageAsItIsForALegacyPage(): void
    {
        foreach (['default', 'json', 'jsonp', 'markdown'] as $type) {
            foreach ($this->callers() as $headers) {
                $response = $this->assertTheSamePage('case=throw-error&_t=' . $type, $headers);

                $this->assertSame(500, $response->status, $type);
            }
        }
        $page = $this->get('draw-new', 'plain', 'case=throw-error', ['Cookie' => $this->member]);
        $this->assertStringContainsString('Something went wrong.', $page->body, 'in the site\'s shell, not the fatal-error page');
        $this->assertStringNotContainsString("Idno experienced a problem with this page", $page->body);
    }

    public function testAnErrorThatIsThrownByAControllerOtherThanASitePageStillEndsAtTheFatalErrorPage(): void
    {
        $response = $this->site()->request('GET', '_test/throw-error');

        $this->assertSame(500, $response->status);
        $this->assertStringContainsString("Idno experienced a problem with this page and couldn't continue.", $response->body);
    }

    public function testAPageThatCannotBeDrawnEndsAsPlainTextAndIsReportedOnce(): void
    {
        $before = $this->serverLogContents();

        $response = $this->get('draw-new', 'plain', 'case=throw-nested', ['Cookie' => $this->member]);

        $this->assertSame(500, $response->status);
        $this->assertStringStartsWith('text/plain', (string) $response->header('Content-Type'));
        $this->assertSame("Internal Server Error\n", $response->body);
        $this->assertCount(1, $response->lines('Content-Type'), 'the type of the page that failed to draw is not left beside the plain one');
        $added = implode("\n", $this->loggedSince($before));
        $this->assertSame(1, substr_count($added, 'Broken, and so is its page'), 'the exception, once: ' . $added);
        $this->assertSame(1, substr_count($added, 'The exception cannot be printed'), 'the failure of its page, once: ' . $added);
        $this->assertStringNotContainsString('Fatal', $added);
    }

    public function testAPageWhoseErrorPageCannotBeDrawnIsTheSafePlain500InDebugAndProdModeAndNeverAnException(): void
    {
        // The plain answer is the site's own and never cached: it is exempt from the rule that a response must not set Cache-Control itself
        // (debug mode throws for that), and its `no-store` stays
        $prod = new HttpSiteClient($this->startExtraServer(['APP_DEBUG' => '0']));
        foreach ($this->callers() as $headers) {
            foreach (['debug' => $this->site(), 'prod' => $prod] as $mode => $client) {
                $label = $mode . ' ' . json_encode(array_keys($headers));
                $response = $client->request('GET', self::AREAS['plain'] . 'draw-new?case=throw-nested', $headers);

                $this->assertSame(500, $response->status, $label);
                $this->assertSame("Internal Server Error\n", $response->body, $label);
                $this->assertStringStartsWith('text/plain', (string) $response->header('Content-Type'), $label);
                $this->assertSame(['no-store, no-cache, must-revalidate'], $response->lines('Cache-Control'), $label . ': one line, the session\'s, never cached (not the response\'s own beside it)');
            }
        }
    }

    public function testAWalledGardenRefusalIsTheDrawn403(): void
    {
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_WALLED_GARDEN' => '1']));
        $pair = function (string $query, array $headers) use ($client): array {
            return [
                $client->request('GET', self::AREAS['plain'] . 'draw-legacy?' . $query, $headers),
                $client->request('GET', self::AREAS['plain'] . 'draw-new?' . $query, $headers),
            ];
        };
        foreach (['', '&_t=json', '&_t=jsonp', '&_t=markdown', '&_t=rss'] as $type) {
            [$legacy, $new] = $pair('case=draw' . $type, []);
            $this->assertSame(403, $legacy->status, $type);
            // exactly the caching lines a legacy refusal carries, line by line, and nothing added by a response
            foreach (['Cache-Control', 'Pragma', 'Expires'] as $name) {
                $this->assertSame($legacy->lines($name), $new->lines($name), $name . ' of the refusal ' . $type);
            }
            $this->assertSame(['no-store, no-cache, must-revalidate'], $new->lines('Cache-Control'), $type);
            $this->assertSame(
                $this->observedBy($client, $legacy, 'draw-legacy', !str_contains($type, '_t=') || str_contains($type, 'default')),
                $this->observedBy($client, $new, 'draw-new', !str_contains($type, '_t=') || str_contains($type, 'default')),
                'a visitor of a walled garden, ' . $type
            );
        }
        [$legacy, $new] = $pair('case=draw', ['Cookie' => $this->member]);
        $this->assertSame(200, $legacy->status);
        $this->assertSame(200, $new->status, 'a member is let in');
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function observedBy(SiteClient $client, SiteResponse $response, string $page, bool $html): array
    {
        $this->siteClient = $client;
        try {
            return $this->observed($response, 'plain', $page, $html);
        } finally {
            $this->siteClient = null;
        }
    }

    /**
     * An observation of a legacy 500 answer as it would be at another server error status (the page says its status in a `meta` and in
     * the `exception` of the JSON shells).
     *
     * @param array{status: int, headers: array<string, string>, body: string} $observed
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function atStatus(array $observed, int $status): array
    {
        $observed['status'] = $status;
        $observed['body'] = str_replace('<meta http-equiv="Status" content="500"/>', '<meta http-equiv="Status" content="' . $status . '"/>', $observed['body']);

        return $observed;
    }

    /**
     * @return list<array{0: string, 1: string}> a case and a type
     */
    private function debugOffCases(): array
    {
        $cases = [];
        foreach (['throw-runtime', 'throw-technical', 'throw-5xx', 'throw-error'] as $case) {
            foreach (['', '_t=json', '_t=jsonp', '_t=markdown'] as $type) {
                $cases[] = [$case, $type];
            }
        }

        return $cases;
    }

    public function testNothingTechnicalIsShownWhenDebugIsOff(): void
    {
        // the rig's site runs with `debug` on; this one does not
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_DEBUG' => '0']));
        // the premise: the site's `debug` really is off here, and on in the rig's (the same page shows its exception there)
        $rigs = $this->get('draw-new', 'plain', 'case=throw-technical', ['Cookie' => $this->member]);
        $this->assertStringContainsString('Stack trace', $rigs->body, 'the rig\'s site runs with debug on, and shows the exception');
        $this->assertStringNotContainsString('Stack trace', $client->request('GET', self::AREAS['plain'] . 'draw-new?case=throw-technical')->body, 'this one runs with debug off');
        $secrets = ['idno_entities', 'TechnicalException', 'TypeError', 'ServiceUnavailableHttpException', 'LegacyDrawPage.php', 'SitePageFixtureController.php', 'Stack trace', '#0 ', 'type</b> error', 'type&lt;/b&gt; error', 'maintenance'];
        foreach ($this->debugOffCases() as [$case, $type]) {
            $query = 'case=' . $case . ($type === '' ? '' : '&' . $type);
            $legacy = $client->request('GET', self::AREAS['plain'] . 'draw-legacy?' . $query);
            $new = $client->request('GET', self::AREAS['plain'] . 'draw-new?' . $query);
            $html = $type === '';
            $label = $query;

            // the same answer (a 5xx exception is drawn at its own status: the one difference)
            $this->assertSame($case === 'throw-5xx' ? 503 : 500, $new->status, $label);
            $this->assertSame(500, $legacy->status, $label);
            $expected = $this->observedBy($client, $legacy, 'draw-legacy', $html);
            $actual = $this->observedBy($client, $new, 'draw-new', $html);
            $expected = $this->atStatus($expected, $actual['status']);
            $this->assertSame($expected, $actual, $label);

            if ($case !== 'throw-runtime') {
                // a technical message, a class, a file, a line and a trace are not for a visitor, in either answer
                foreach ([$legacy, $new] as $response) {
                    foreach ($secrets as $secret) {
                        $this->assertStringNotContainsString($secret, $response->body, $label . ' shows ' . $secret);
                    }
                    $this->assertDoesNotMatchRegularExpression('~\.php\b|\bline \d+|:\d+\)|\.php:\d+~i', $response->body, $label . ' shows a file or a line');
                }
            } else {
                $this->assertStringContainsString('Broken', $new->body, $label . ': a friendly message is shown');
                $this->assertStringNotContainsString('LegacyDrawPage.php', $new->body, $label);
                $this->assertStringNotContainsString('Stack trace', $new->body, $label);
                $this->assertDoesNotMatchRegularExpression('~\.php\b|\bline \d+|:\d+\)~i', $new->body, $label . ' shows a file or a line');
            }
        }
    }

    public function testAServerErrorExceptionIsTheSites500PageAtItsStatus(): void
    {
        $legacy = $this->get('draw-legacy', 'plain', 'case=throw-5xx', ['Cookie' => $this->member]);
        $new = $this->get('draw-new', 'plain', 'case=throw-5xx', ['Cookie' => $this->member]);

        $this->assertSame(500, $legacy->status);
        $this->assertSame(503, $new->status);
        $this->assertStringContainsString('Something went wrong.', $new->body);
        $this->assertSame($this->atStatus($this->observed($legacy, 'plain', 'draw-legacy', true), 503), $this->observed($new, 'plain', 'draw-new', true));
    }

    public function testAServerErrorExceptionsOwnHeadersGoWithTheSites500Page(): void
    {
        $new = $this->get('draw-new', 'plain', 'case=throw-5xx-retry', ['Cookie' => $this->member]);

        $this->assertSame(503, $new->status);
        $this->assertStringContainsString('Something went wrong.', $new->body);
        $this->assertSame(['120'], $new->lines('Retry-After'));
    }

    public function testAClientErrorExceptionIsSymfonysAndNotReportedAsAFailure(): void
    {
        $before = $this->serverLogContents();

        $response = $this->get('draw-new', 'plain', 'case=throw-4xx', ['Cookie' => $this->member]);

        $this->assertSame(400, $response->status);
        $this->assertStringNotContainsString('Something went wrong.', $response->body, 'not the site\'s 500 page');
        $this->assertStringNotContainsString('critical', implode("\n", $this->loggedSince($before)), 'no failure of the page is reported');
    }

    /**
     * An exception that wraps a refusal of Symfony Security is answered as the refusal (a 403), and is not a failure of the page,
     * but it is not silent either: one warning names the wrapping exception's class (never its message or anything of the request),
     * so that code which wraps a refusal by mistake can be found.
     */
    public function testAWrappedRefusalIsARefusalAndIsLoggedOnceAsAWarning(): void
    {
        $before = $this->serverLogContents();

        $response = $this->get('draw-new', 'plain', 'case=throw-wrapped-denied', ['Cookie' => $this->member]);

        $this->assertSame(403, $response->status);
        $logged = $this->loggedSince($before);
        $warnings = array_values(array_filter($logged, static fn (string $line): bool => str_contains($line, 'wraps a refusal')));
        $this->assertCount(1, $warnings, implode("\n", $logged));
        $this->assertStringContainsString('warning', $warnings[0]);
        $this->assertStringContainsString('RuntimeException', $warnings[0]);
        $this->assertStringNotContainsString('not logged', implode("\n", $logged), 'the message is not logged');
        $this->assertStringNotContainsString('critical', implode("\n", $logged), 'not reported as a failure');
    }

    public function testAnErrorAndAServerErrorAreMailedToTheAdministratorAsAnExceptionIs(): void
    {
        $client = new HttpSiteClient($this->startExtraServer(['IDNO_OOPS_NOTIFY' => 'ops@example.net', 'IDNO_FROM_EMAIL' => 'site@example.net']));
        $mail = static fn (): array => $client->request('GET', '_test/mail/log')->json();
        $mail();
        foreach (['throw-runtime', 'throw-error', 'throw-5xx'] as $case) {
            $seen = [];
            foreach (['legacy', 'new'] as $side) {
                $client->request('GET', self::AREAS['plain'] . 'draw-' . $side . '?case=' . $case);
                $sent = $mail();
                $this->assertCount(1, $sent, $side . ' ' . $case . ': one alert');
                $seen[$side] = [$sent[0]['subject'] ?? null, $sent[0]['to'] ?? null];
            }
            $this->assertSame($seen['legacy'], $seen['new'], $case . ': the same subject, which names the exception\'s own class, and recipient');
        }
    }
}
