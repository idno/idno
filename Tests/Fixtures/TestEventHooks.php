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
        // With IDNO_TEST_CLAIM_ROUTES the pages of the routing tests (RouteOverrideTest), registered on `plugins/loaded`, the first event the boot
        // fires after every plugin's registerPages() (a plugin registers its pages when it is built), as a plugin's page is:
        //   replaced  the shell ReplacedPage on the path a controller serves, which the controller shadows
        //   1         the shell, then a plugin's hijackRoute() of the same pattern for ClaimingPage
        //   identical the shell, then a plugin's addRoute() of the identical pattern for ClaimingPage
        //   subclass  the shell, then a plugin's hijackRoute() of the same pattern for a subclass of the shell
        //   plugin    a plugin's addRoute() for a path no shell was ever registered for
        //   mdclaim   a plugin's hijackRoute() of the permalink route of the Markdown fixture, for a Markdown permalink of its own
        //   homepage-hijack  a plugin's hijackRoute() of the root for a subclass of the home page's shell (ThirdPartyHomepage)
        //   homepage-route   a plugin's addRoute() of a path of its own for the same subclass
        $claim = getenv('IDNO_TEST_CLAIM_ROUTES');
        if (is_string($claim) && $claim !== '') {
            $dispatcher->addListener('plugins/loaded', static function () use ($claim): void {
                $routes = Idno::site()->routes();
                if (in_array($claim, ['replaced', '1', 'identical', 'subclass'], true)) {
                    $routes->addRoute('/_test/claimable/?', ReplacedPage::class);
                }
                if (in_array($claim, ['1', 'identical', 'subclass'], true)) {
                    // a plugin's page for a path no controller serves: what a claimed path answers, in each spelling, is what this answers
                    $routes->addRoute('/_test/legacyonly/?', ClaimingPage::class);
                }
                match ($claim) {
                    '1' => $routes->hijackRoute('/_test/claimable/?', ClaimingPage::class),
                    'identical' => $routes->addRoute('/_test/claimable/?', ClaimingPage::class),
                    'subclass' => $routes->hijackRoute('/_test/claimable/?', SubclassedPage::class),
                    'plugin' => $routes->addRoute('/_test/unclaimed/?', ClaimingPage::class),
                    'mdclaim' => $routes->hijackRoute('/_test/markdown/([^/]+)/?', SubclassedPage::class),
                    'homepage-hijack' => $routes->hijackRoute('/', ThirdPartyHomepage::class),
                    'homepage-route' => $routes->addRoute('/third-party-home/?', ThirdPartyHomepage::class),
                    default => null,
                };
            });
        }
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
