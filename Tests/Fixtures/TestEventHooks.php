<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Core\EventDispatcher;
use Idno\Core\Idno;

/**
 * Test-environment only: the configurator of the legacy event dispatcher (config/services_test.php), which installs
 * the recorders and stand-ins that are inert unless their environment variable is set (EventRecorder, AuthHook, PageEventRecorder),
 * and registers the legacy page of the drawing tests (LegacyDrawPage) on every site of the test environment, the rig's included (it
 * is a page under `/_test/` and the admin and account directories that nothing links to, and it only answers what `case` asks).
 */
final class TestEventHooks
{
    public static function install(EventDispatcher $dispatcher): void
    {
        EventRecorder::install($dispatcher);
        AuthHook::install($dispatcher);
        PageEventRecorder::install($dispatcher);
        // The legacy page the drawing tests compare the controller's answer with, in each directory that has a shell of its own
        $dispatcher->addListener('plugins/loaded', static function (): void {
            foreach (['_test/site-page/' => LegacyDrawPage::class, 'admin/_test-site-page/' => LegacyAdminDrawPage::class, 'account/_test-site-page/' => LegacyAccountDrawPage::class] as $directory => $page) {
                Idno::site()->routes()->addRoute('/' . $directory . 'draw-legacy/?', $page);
            }
        });
        // With IDNO_TEST_DRAW_HEADERS=1 a directory of templates that send headers while a page is drawn: an extension of `shell/headers`
        // and a third-party `json` shell (SitePageDrawingHeadersTest). Inert otherwise.
        if (getenv('IDNO_TEST_DRAW_HEADERS') === '1') {
            $dispatcher->addListener('plugins/loaded', static function (): void {
                \Idno\Core\Bonita\Main::additionalPath(__DIR__ . '/DrawHeaders');
                Idno::site()->template()->extendTemplate('shell/headers', 'tests/draw-headers');
            });
        }
    }
}
