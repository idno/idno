<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use Tests\Support\PrivateSiteTestCase;

/**
 * The headers a draw sends itself (`header()` in an extension of `shell/headers`, in a third-party shell) reach the client from a
 * page a controller draws as they did from a legacy page: once, with the replace or append the call asked for, after the ones the
 * template type declares, so that a third-party shell's `Content-Type` wins. The site has the fixture templates of
 * `Tests/Fixtures/DrawHeaders` (`IDNO_TEST_DRAW_HEADERS=1`): an extension of `shell/headers` that replaces `Access-Control-Allow-Origin`
 * and `Content-Type`, sends `X-Fixture-Header` twice and adds to `Vary` and `Link`, and a `json` shell that sends its own `Content-Type`.
 * A page `.../draw-legacy` (a real legacy page) and `.../draw-new` (the controller) are asked the same way and their headers compared.
 *
 * PHP keeps the list of headers `header()` sets only under a web server SAPI (the CLI keeps none, so a process of the test suite
 * sees nothing): the site is a `php -S` of the test's own, and the observation is over HTTP.
 */
final class SitePageDrawingHeadersTest extends PrivateSiteTestCase
{
    protected const SHARED_SITE = true;

    private const TRANSPORT = ['date', 'server', 'connection', 'transfer-encoding', 'keep-alive', 'content-length'];

    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_DRAW_HEADERS' => '1'];
    }

    /**
     * @return array<string, string>
     */
    private function headersOf(string $page, string $query, int $status = 200): array
    {
        $response = $this->site()->request('GET', '_test/site-page/' . $page . '?' . $query);
        $this->assertSame($status, $response->status, $page . ' ' . $query);
        $headers = [];
        foreach ($response->headers as $name => $value) {
            if (in_array($name, self::TRANSPORT, true) || $name === 'host') {
                continue;
            }
            $headers[$name] = $name === 'set-cookie'
                ? preg_replace(['/^([^=;]+)=[^;]*/', '/expires=[^;]+/i'], ['$1=(id)', 'expires=(date)'], $value)
                : str_replace(['draw-legacy', 'draw-new'], 'draw-page', preg_replace('/\s*;\s*/', ';', $value) ?? $value);
        }
        ksort($headers);

        return $headers;
    }

    public function testAnExtensionOfTheShellHeadersIsSentOnceAndLikeALegacyPageSendsIt(): void
    {
        $legacy = $this->headersOf('draw-legacy', 'case=draw');
        $new = $this->headersOf('draw-new', 'case=draw');

        $this->assertSame($legacy, $new);
        // and what that is: replaced where it replaces, added where it adds, never twice
        $this->assertSame('https://fixture.example', $new['access-control-allow-origin']);
        $this->assertSame('text/html;charset=utf-8', $new['content-type']);
        $this->assertSame('one, two', $new['x-fixture-header']);
        $this->assertStringContainsString('Cookie', $new['vary']);
        $this->assertStringContainsString('rel="fixture"', $new['link']);
    }

    public function testAThirdPartyShellsContentTypeWinsOverTheTypesOwn(): void
    {
        $legacy = $this->headersOf('draw-legacy', 'case=draw&_t=json');
        $new = $this->headersOf('draw-new', 'case=draw&_t=json');

        $this->assertSame($legacy, $new);
        $this->assertSame('application/x-fixture+json', $new['content-type']);
        // the rest of the type's headers stay
        $this->assertSame('*', $new['access-control-allow-origin']);
        $this->assertSame('{"fixture":"shell"}', $this->site()->request('GET', '_test/site-page/draw-new?case=draw&_t=json')->body);
    }

    public function testAHeaderTheDrawRemovesIsAbsent(): void
    {
        // the type's own headers are sent before the draw, so a plugin's header_remove() takes one of them off
        $legacy = $this->headersOf('draw-legacy', 'case=draw&draw_headers=remove');
        $new = $this->headersOf('draw-new', 'case=draw&draw_headers=remove');

        $this->assertSame($legacy, $new);
        $this->assertArrayNotHasKey('access-control-allow-origin', $new);
        $this->assertArrayHasKey('content-type', $new);
    }

    public function testAHeaderTheDrawAddsBesideATypesOwnIsSentBesideIt(): void
    {
        $legacy = $this->headersOf('draw-legacy', 'case=draw&draw_headers=append');
        $new = $this->headersOf('draw-new', 'case=draw&draw_headers=append');

        $this->assertSame($legacy, $new);
        $this->assertSame('*, https://fixture.example', $new['access-control-allow-origin'], 'both, in the order sent');
        $this->assertSame('text/html;charset=UTF-8', $new['content-type'], 'the one that replaced nothing');
    }

    public function testAStatusTheDrawSetsIsTheAnswers(): void
    {
        $legacy = $this->headersOf('draw-legacy', 'case=draw&draw_headers=status', 203);
        $new = $this->headersOf('draw-new', 'case=draw&draw_headers=status', 203);

        $this->assertSame($legacy, $new);
    }

    public function testALocationTheDrawSendsIsARedirectAsPhpMakesIt(): void
    {
        $legacy = $this->headersOf('draw-legacy', 'case=draw&draw_headers=location', 302);
        $new = $this->headersOf('draw-new', 'case=draw&draw_headers=location', 302);

        $this->assertSame($legacy, $new);
        $this->assertSame('https://fixture.example/moved', $new['location']);
    }

    public function testTheSessionsCookieIsNeitherTakenNorSentTwice(): void
    {
        $response = $this->site()->request('GET', '_test/site-page/draw-new?case=draw');
        $legacy = $this->site()->request('GET', '_test/site-page/draw-legacy?case=draw');

        $this->assertNotNull($response->header('Set-Cookie'));
        $this->assertSame(substr_count((string) $legacy->header('Set-Cookie'), 'SameSite'), substr_count((string) $response->header('Set-Cookie'), 'SameSite'));
        $this->assertSame(1, substr_count((string) $response->header('Set-Cookie'), 'SameSite'));
    }

    public function testADrawThatFailsLeavesNoneOfItsHeadersForTheErrorPage(): void
    {
        // The headers the type sent before the draw, and one a plugin sent before it failed, are not the error page's: PHP must not hold them
        $response = $this->site()->request('GET', '_test/site-page/draw-new?case=draw&draw_headers=throw');

        $this->assertSame(500, $response->status);
        $this->assertNull($response->header('X-Fixture-Leftover'), 'a header of the failed draw is sent with the error page: ' . json_encode($response->headers));
    }

    public function testAFailedDrawKeepsTheCachingThePageAskedFor(): void
    {
        // the page asked for no caching, then its draw failed: the error page is sent with the lines the page asked for, as a legacy page's was
        $legacy = $this->headersOf('draw-legacy', 'case=no-cache&draw_headers=throw', 500);
        $new = $this->headersOf('draw-new', 'case=no-cache&draw_headers=throw', 500);

        // (the one difference is deliberate: a legacy page's failed draw left the header it had sent; a controller's does not)
        unset($legacy['x-fixture-leftover']);
        $this->assertSame($legacy, $new);
        $response = $this->site()->request('GET', '_test/site-page/draw-new?case=no-cache&draw_headers=throw');
        $this->assertSame(['no-store, no-cache, must-revalidate, max-age=0', 'post-check=0, pre-check=0'], $response->lines('Cache-Control'));
        $this->assertSame([], $response->lines('X-Fixture-Leftover'));
    }
}
