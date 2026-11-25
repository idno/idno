<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\SitePage;
use Idno\Legacy\LegacyPageLifecycle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Fixtures\RecordingMailTransport;

/**
 * The page lifecycle's legacy adapter, in the process of the suite (the rig's site, a visitor, a public site): what it
 * answers for a page that is not an account page, and that reporting an exception logs it and never throws.
 * The lifecycle in order, around a real request, is `Tests\Characterisation\SitePageLifecycleTest`.
 */
final class LegacyPageLifecycleTest extends TestCase
{
    private function context(SitePage $page): PageContext
    {
        return new PageContext(Request::create('/'), $page);
    }

    public function testAVisitorMayViewAPageOnAPublicSite(): void
    {
        $this->assertTrue(Idno::site()->config()->isPublicSite(), 'the rig\'s site is public');

        $this->assertTrue((new LegacyPageLifecycle())->mayView($this->context(new SitePage('Tests\Fixtures\SitePageFixture'))));
    }

    public function testNoRequestIsAnApiRequestHere(): void
    {
        $lifecycle = new LegacyPageLifecycle();

        $this->assertFalse($lifecycle->refusesApiRequest($this->context(new SitePage('Tests\Fixtures\SitePageFixture', accountPage: true))));
        $this->assertFalse($lifecycle->refusesApiRequest($this->context(new SitePage('Tests\Fixtures\SitePageFixture'))));
    }

    public function testThereAreNoStandardHeadersUnderUnitTestsAsThereWereNoneFromPageInit(): void
    {
        // The test bootstrap defines IDNO_UNIT_TEST; the three headers a request sends are asserted over HTTP.
        $this->assertTrue(defined('IDNO_UNIT_TEST'));
        $this->assertSame([], (new LegacyPageLifecycle())->standardHeaders());
    }

    public function testReportingAnExceptionLogsItAndNeverThrows(): void
    {
        $log = sys_get_temp_dir() . '/idno-lifecycle-' . bin2hex(random_bytes(6)) . '.log';
        $previous = ini_set('error_log', $log);
        RecordingMailTransport::drain();
        try {
            (new LegacyPageLifecycle())->reportException(new \RuntimeException('a page failed'));
            $lines = is_file($log) ? (string) file_get_contents($log) : '';
        } finally {
            ini_set('error_log', (string) $previous);
            if (is_file($log)) {
                unlink($log);
            }
            RecordingMailTransport::drain();
        }

        $this->assertStringContainsString('a page failed [', $lines);
        $this->assertStringContainsString(basename(__FILE__), $lines);
    }
}
