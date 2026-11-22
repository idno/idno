<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * What a site with NO theme renders (the `theme` setting is empty: the rig's default, so the variable IDNO_THEME
 * is simply not set), for a visitor and for the logged-in administrator, and the output that is not a web page.
 * Idno 2026 is the look of a site with no theme, so these are the golden files RenderedPagesTest compares a site that
 * names the theme `Twenty26` with: Tests/Templating/fixtures/pages/twenty26/, logged-in/ and non-html/.
 */
final class RenderedPagesNoThemeTest extends RenderedSiteTestCase
{
    public function testPagesAndTheNonHtmlOutputAreRenderedAsTheyWere(): void
    {
        $paths = $this->seedSite();
        $cookie = $this->loginAsAdmin();

        $this->visitorPages('twenty26', $paths);
        $this->nonHtmlPages($paths, $cookie);
        $this->loggedInPages('logged-in', $paths, $cookie, true);

        $this->assertNoDifferences();
    }
}
