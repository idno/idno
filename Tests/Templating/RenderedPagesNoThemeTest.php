<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * What a site with NO theme renders (the `theme` setting is empty: the rig's default, so the variable IDNO_THEME
 * is simply not set), for a visitor and for the logged-in administrator, and the output that is not a web page.
 * The golden files are Tests/Templating/fixtures/pages/no-theme/, logged-in-no-theme/ and non-html/.
 */
final class RenderedPagesNoThemeTest extends RenderedSiteTestCase
{
    public function testPagesAndTheNonHtmlOutputAreRenderedAsTheyWere(): void
    {
        $paths = $this->seedSite();
        $cookie = $this->loginAsAdmin();

        $this->visitorPages('no-theme', $paths);
        $this->nonHtmlPages($paths, $cookie);
        $this->loggedInPages('logged-in-no-theme', $paths, $cookie, false);

        $this->assertNoDifferences();
    }
}
