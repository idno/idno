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
 * `Transfer-Encoding`, and `X-Robots-Tag`, which Symfony adds to every response in debug mode). `Content-Length` is compared as
 * what it is: the length of that answer's own body (the two bodies differ in the name of their page), and for a HEAD that it is
 * there and the length of the GET's. A cookie is compared whole but for the session id and its date, and the spelling of a header's
 * value is compared as the same where HTTP says it is (the space after the `;` of a media type, the set of `Cache-Control`
 * directives: the response adds `private`, as `SitePageLifecycleEquivalenceTest` allows).
 */
final class SitePageDrawingTest extends PrivateSiteTestCase
{
    use LogsIn;

    private const PASSWORD = 'correct-horse-battery-9';

    /** The directory each kind of page lives in: the root of the site, and the two that have a shell of their own. */
    private const AREAS = ['plain' => '_test/site-page/', 'admin' => 'admin/_test-site-page/', 'account' => 'account/_test-site-page/'];

    private const TYPES = ['default', 'json', 'jsonp', 'rss', 'jsonfeed', 'kml', 'markdown', 'activitypub'];

    private const TRANSPORT = ['date', 'server', 'connection', 'transfer-encoding', 'keep-alive', 'x-robots-tag'];

    private string $member = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->addUser('ada', self::PASSWORD, null, true);
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
        $headers = [];
        foreach ($response->headers as $name => $value) {
            if (in_array($name, self::TRANSPORT, true)) {
                continue;
            }
            $value = $this->named($value, $area, $page);
            if ($name === 'set-cookie') {
                // the session's id and its date are the only things that differ between two requests
                $value = preg_replace(['/^([^=;]+)=[^;]*/', '/expires=[^;]+/i'], ['$1=(id)', 'expires=(date)'], $value) ?? $value;
                $value = preg_replace('/, ([^=;, ]+)=[^;]*/', ', $1=(id)', $value) ?? $value;
            } elseif ($name === 'content-length') {
                $value = $head || $value !== (string) strlen($response->body) ? ($head ? '(present)' : $value) : '(its body)';
            } elseif ($name === 'content-type') {
                // PHP appends its charset as `;charset=UTF-8`, a response as `; charset=UTF-8`: the same media type
                $value = preg_replace('/\s*;\s*/', ';', $value) ?? $value;
            } elseif ($name === 'cache-control') {
                // the session's directives, and the ones a response adds of its own (`private`, a second `no-cache`): the set of them
                $directives = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), static fn (string $d): bool => $d !== 'private')));
                sort($directives);
                $value = implode(', ', $directives);
            }
            $headers[$name] = $value;
        }
        ksort($headers);

        return [
            'status' => $response->status,
            'headers' => $headers,
            'body' => PageNormaliser::normalise($this->named($response->body, $area, $page), $this->site()->baseUrl(), $html),
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

        // (and the class of the page, which the body's classes carry)
        return str_replace([$page, 'tests_fixtures_legacydrawpage', 'tests_fixtures_sitepagefixture'], ['draw-page', 'tests_fixtures_page', 'tests_fixtures_page'], $text);
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

        $this->assertSame(
            $this->observed($legacy, $area, 'draw-legacy', $html, $head),
            $this->observed($new, $area, 'draw-new', $html, $head),
            $label
        );
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
        $this->addUser('bob');
        $key = $this->apiKeyOf('bob');
        $this->runInSite('$c = \Idno\Core\Idno::site()->config(); $c->blocked_emails = [\'bob@example.com\']; $c->save(); return true;');
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
}
