<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * What a site that names the theme `Twenty26` (IDNO_THEME=Twenty26: the name Idno 2026 had as a theme, which is
 * resolved to the built-in look) renders, for a visitor and for the logged-in administrator, and the output that is
 * not a web page (the same golden files as RenderedPagesNoThemeTest compares, whose site names no theme). The
 * golden files are Tests/Templating/fixtures/pages/twenty26/, logged-in/ and non-html/.
 *
 * The site traces every template draw (IDNO_TEST_TEMPLATE_TRACE): the draws of the non-HTML output that reach
 * a `classic` file (the entity templates of the look Idno 2026 replaced, which feeds and mail still draw) are pinned
 * in fixtures/fallback-trace.php (see FallbackTrace).
 */
final class RenderedPagesTest extends RenderedSiteTestCase
{
    private string $traceFile = '';

    protected function setUp(): void
    {
        $this->traceFile = sys_get_temp_dir() . '/idno-template-trace-' . bin2hex(random_bytes(6));
        touch($this->traceFile);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->traceFile !== '' && is_file($this->traceFile)) {
            unlink($this->traceFile);
        }
    }

    protected function siteEnvironment(): array
    {
        return ['IDNO_THEME' => 'Twenty26', 'IDNO_TEST_TEMPLATE_TRACE' => $this->traceFile];
    }

    public function testPagesAndTheNonHtmlOutputAreRenderedAsTheyWere(): void
    {
        $paths = $this->seedSite();
        $cookie = $this->loginAsAdmin();

        $this->visitorPages('twenty26', $paths);
        $this->assertContains('shell/pagination', FallbackTrace::draws((string) file_get_contents($this->traceFile))['default'] ?? [], 'the home page\'s second page draws the pagination');

        file_put_contents($this->traceFile, '');
        $this->nonHtmlPages($paths, $cookie);
        $trace = (string) file_get_contents($this->traceFile);
        $this->assertContains('shell/pagination', FallbackTrace::draws($trace)['rss'] ?? [], 'the second page of a feed draws the pagination');
        $this->assertFallbackTrace(FallbackTrace::derive($trace, dirname(__DIR__, 2)));

        $this->loggedInPages('logged-in', $paths, $cookie, true);

        $this->assertNoDifferences();
    }

    /**
     * @param array<string, list<array{string, string}>> $derived
     */
    private function assertFallbackTrace(array $derived): void
    {
        $file = __DIR__ . '/fixtures/fallback-trace.php';
        $classic = FallbackTrace::classic(dirname(__DIR__, 2));
        if (self::capturing()) {
            FallbackTrace::write($file, $derived, $classic);
            $this->written('fixtures/fallback-trace.php');

            return;
        }
        $expected = require $file;
        $this->assertSame($expected['traced'], $derived, 'the `classic` files the non-HTML output draws changed');
        $this->assertSame($expected['classic'], $classic, 'the `classic` files changed');
    }
}
