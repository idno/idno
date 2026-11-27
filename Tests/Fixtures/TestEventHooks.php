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
        WebSubRecorder::install($dispatcher);
        RegistrationEventHook::install($dispatcher);
        // The patterns core registered for the pages of the routing fixtures that have no legacy class (a placeholder where core's own registrations are): the first
        // listener of `plugins/loaded`, so before the boundary of plugin time registered below
        $dispatcher->addListener('plugins/loaded', static function (): void {
            foreach (['/_test/unclaimed/?', '/_test/markdown/([^/]+)/?'] as $pattern) {
                Idno::site()->routes()->addRoute($pattern, \Idno\Legacy\ControllerRoute::class);
            }
            // and the shell of the claimable path, which a controller replaces
            Idno::site()->routes()->addRoute('/_test/claimable/?', ReplacedPage::class);
        });
        // a legacy page that only answers, for the lifecycle tests to hold a controller's page to
        $dispatcher->addListener('plugins/loaded', static function (): void {
            Idno::site()->routes()->addRoute('/_test/legacy-plain/?', LegacyPlainPage::class);
            Idno::site()->routes()->addRoute('/_test/legacy-forward/?', LegacyForwardPage::class);
        });
        // The legacy page the drawing tests compare the controller's answer with, in each directory that has a shell of its own
        $dispatcher->addListener('plugins/loaded', static function (): void {
            foreach (['_test/site-page/' => LegacyDrawPage::class, 'admin/_test-site-page/' => LegacyAdminDrawPage::class, 'account/_test-site-page/' => LegacyAccountDrawPage::class] as $directory => $page) {
                Idno::site()->routes()->addRoute('/' . $directory . 'draw-legacy/?', $page);
            }
        });
        // With IDNO_TEST_FIXTURE_SHELL=1 the shell of FixtureShellController's page, registered as core registers a shell it keeps
        if (getenv('IDNO_TEST_FIXTURE_SHELL') === '1') {
            $dispatcher->addListener('plugins/loaded', static function (): void {
                Idno::site()->routes()->addRoute('/_test/fixture-shell/?', FixtureShell::class);
                Idno::site()->routes()->addRoute('/_test/fixture-shell-sub/?', SubclassedFixtureShell::class);
                Idno::site()->routes()->addRoute('/_test/fixture-shell-tokenless/?', FixtureTokenlessShell::class);
                Idno::site()->routes()->addRoute('/account/_test-fixture-shell/?', FixtureAccountShell::class);
                Idno::site()->routes()->addRoute('/_test/fixture-account-shell-sub/?', SubclassedFixtureAccountShell::class);
                Idno::site()->routes()->addRoute('/_test/fixture-shell-public/?', FixturePublicShell::class);
            });
        }
        // With IDNO_TEST_TRANSLATIONS (JSON: locale => {string => translation}) a catalogue of those strings for each locale (TestCatalogue)
        $translations = getenv('IDNO_TEST_TRANSLATIONS');
        if (is_string($translations) && $translations !== '') {
            $dispatcher->addListener('plugins/loaded', static function () use ($translations): void {
                $catalogues = json_decode($translations, true);
                foreach (is_array($catalogues) ? $catalogues : [] as $locale => $strings) {
                    Idno::site()->language()->register(new TestCatalogue((string) $locale, is_array($strings) ? $strings : []));
                }
            });
        }
        // With IDNO_TEST_MENTIONABLE=1 a plugin's legacy page that takes webmentions (MentionablePage), at `/_test/mentionable/:id/?`
        if (getenv('IDNO_TEST_MENTIONABLE') === '1') {
            $dispatcher->addListener('plugins/loaded', static function (): void {
                Idno::site()->routes()->addRoute('/_test/mentionable/:id/?', MentionablePage::class);
            });
        }
        // With IDNO_TEST_ROUTED=1 the pages routed by the legacy table (RoutedFixtureController): a placeholder at an address that holds a stored setting (the
        // permalink structure is one), and a shell, registered as core registers what it keeps
        if (getenv('IDNO_TEST_ROUTED') === '1') {
            $dispatcher->addListener('plugins/loaded', static function (): void {
                $word = Idno::site()->config()->test_routed_word;
                Idno::site()->routes()->addRoute('/_test/routed/' . (is_string($word) && $word !== '' ? $word : 'word') . '/:id/?', RoutedPlaceholder::class);
                Idno::site()->routes()->addRoute('/_test/routed-shell/:id/.*', RoutedFixtureShell::class);
            });
        }
        // With IDNO_TEST_CLAIM_ROUTES the pages of the routing tests (RouteOverrideTest), registered on `plugins/loaded`, the first event the boot
        // fires after every plugin's registerPages() (a plugin registers its pages when it is built), as a plugin's page is:
        //   replaced  the shell ReplacedPage on the path a controller serves, which the controller shadows
        //   1         the shell, then a plugin's hijackRoute() of the same pattern for ClaimingPage
        //   identical the shell, then a plugin's addRoute() of the identical pattern for ClaimingPage
        //   subclass  the shell, then a plugin's hijackRoute() of the same pattern for a subclass of the shell
        //   plugin    a plugin's addRoute() for a path no shell was ever registered for
        //   mdclaim   a plugin's hijackRoute() of the permalink route of the Markdown fixture, for a Markdown permalink of its own
        //   login-hijack  a plugin's hijackRoute() of the login page's address, for ClaimingPage
        //   homepage-hijack  a plugin's hijackRoute() of the root for a subclass of the home page's shell (ThirdPartyHomepage)
        //   homepage-route   a plugin's addRoute() of a path of its own for the same subclass
        //   feeds     a plugin's addRoute() of the feed, search and tag paths whose core pages are deleted, for ClaimingPage
        //   broad     a plugin's addRoute() of a pattern that matches every path (a short-link page, a catch-all): registered after core's, so it never took
        //             a path core had a page for
        //   corehijack     a plugin's hijackRoute() of each pattern core registered for the home page, its streams, the feeds, the search and the tags
        //   coreidentical  a plugin's addRoute() of the identical patterns
        //   feedhijack     a plugin's hijackRoute() of `/feed\.xml` alone
        //   moved-*   a plugin's registrations for a path that moved to a controller, EARLY (`/feed.xml`, registered before the WebFinger component's) and,
        //             in Tests\Fixtures\LateRoutes, LATE (`/.well-known/webfinger`, registered after every core component's): identical addRoute(),
        //             hijackRoute(), an exact unescaped key, a broad pattern, a hijack made before core's later registration, a hijack of the root
        //   routed-hijack  a plugin's hijackRoute() of the pattern of the page routed by the legacy table (for ClaimingPage) and of its shell's (for a subclass of it)
        //   hijackbroad    a plugin's hijackRoute() of a pattern that matches the feed paths and others, a different pattern from core's
        $claim = getenv('IDNO_TEST_CLAIM_ROUTES');
        if (is_string($claim) && $claim !== '') {
            $dispatcher->addListener('plugins/loaded', static function () use ($claim): void {
                $routes = Idno::site()->routes();
                $coreHome = ['/?', '/content/([A-Za-z\-\/]+)+', '/feed\.xml', '/feed/?', '/rss\.xml', '/search/?', '/tag/([^\s]+)\/?'];
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
                    'login-hijack' => $routes->hijackRoute('/session/login', ClaimingPage::class),
                    'homepage-hijack' => $routes->hijackRoute('/', ThirdPartyHomepage::class),
                    'homepage-route' => $routes->addRoute('/third-party-home/?', ThirdPartyHomepage::class),
                    'broad' => $routes->addRoute('/(.*)', ClaimingPage::class),
                    'moved-identical' => $routes->addRoute('/feed\.xml', ClaimingPage::class),
                    'moved-hijack' => $routes->hijackRoute('/feed\.xml', ClaimingPage::class),
                    'moved-exact' => [$routes->addRoute('/feed.xml', ClaimingPage::class), $routes->addRoute('/', ClaimingPage::class)],
                    'moved-broad' => $routes->addRoute('/(.*)', ClaimingPage::class),
                    'moved-prehijack' => [$routes->hijackRoute('/feed\.xml', ClaimingPage::class), $routes->hijackRoute('/\.well\-known/webfinger/?', ClaimingPage::class)],
                    'moved-hijackroot' => $routes->hijackRoute('/', ClaimingPage::class),
                    'corehijack' => array_map(static fn (string $pattern) => $routes->hijackRoute($pattern, ClaimingPage::class), $coreHome),
                    'coreidentical' => array_map(static fn (string $pattern) => $routes->addRoute($pattern, ClaimingPage::class), $coreHome),
                    'feedhijack' => $routes->hijackRoute('/feed\.xml', ClaimingPage::class),
                    'hijackbroad' => $routes->hijackRoute('/(?:feed|search)(.*)', ClaimingPage::class),
                    'routed-hijack' => [$routes->hijackRoute('/_test/routed/word/:id/?', ClaimingPage::class), $routes->hijackRoute('/_test/routed-shell/:id/.*', \Tests\Fixtures\SubclassedRoutedShell::class)],
                    'feeds' => array_map(static fn (string $pattern) => $routes->addRoute($pattern, ClaimingPage::class), ['/feed\.xml', '/search/?', '/tag/([^\s]+)\/?']),
                    default => null,
                };
            });
        }
        // With IDNO_TEST_PERMALINK_ROUTES the pages a plugin registers for the post permalink's class, on `plugins/loaded` (the permalink recordings):
        //   third-party  a plugin's addRoute() of a pattern of its own for the permalink page itself
        //   subclass     a plugin's addRoute() of a pattern of its own for a subclass of the permalink page (ThirdPartyPermalinkPage)
        //   hijack       a plugin's hijackRoute() of the permalink's own route for ClaimingPage
        $permalinkRoutes = getenv('IDNO_TEST_PERMALINK_ROUTES');
        if (is_string($permalinkRoutes) && $permalinkRoutes !== '') {
            $dispatcher->addListener('plugins/loaded', static function () use ($permalinkRoutes): void {
                $routes = Idno::site()->routes();
                match ($permalinkRoutes) {
                    'third-party' => $routes->addRoute('/widget/:id/.*', '\Idno\Pages\Entity\View'),
                    'subclass' => $routes->addRoute('/widgetsub/:id/?', ThirdPartyPermalinkPage::class),
                    'hijack' => $routes->hijackRoute(\Idno\Common\Entity::getPermalinkRoute() . '/?', ClaimingPage::class),
                    default => null,
                };
            });
        }
        // With IDNO_TEST_HOMEPAGE_LISTENERS (a comma-separated list) listeners of the home page's replacement event (`homepage/replace`), with the
        // priorities that put them before (10) and after (-10, -20) the StaticPages plugin's own (0), and a public route for the home page:
        //   first        sets the Response `FIRST-LISTENER-PAGE`
        //   late         sets the Response `LATE-LISTENER-PAGE`
        //   erase        sets `false` (what a listener that gives its default does)
        //   nonresponse  sets the string `NOT-A-RESPONSE` and then an array
        //   throw        throws a RuntimeException `the homepage listener broke`
        //   public       a plugin's addPublicRoute() of the home page, which a private site then lets a visitor reach
        $homepage = getenv('IDNO_TEST_HOMEPAGE_LISTENERS');
        if (is_string($homepage) && $homepage !== '') {
            $modes = explode(',', $homepage);
            $dispatcher->addListener('plugins/loaded', static function () use ($modes): void {
                $events = Idno::site()->events();
                if (in_array('public', $modes, true)) {
                    Idno::site()->routes()->addPublicRoute('Idno\Pages\Homepage');
                }
                if (in_array('first', $modes, true)) {
                    $events->addListener('homepage/replace', static function (\Idno\Core\Event $event): void {
                        $event->setResponse(new \Symfony\Component\HttpFoundation\Response('FIRST-LISTENER-PAGE'));
                    }, 10);
                }
                if (in_array('nonresponse', $modes, true)) {
                    $events->addListener('homepage/replace', static function (\Idno\Core\Event $event): void {
                        $event->setResponse('NOT-A-RESPONSE');
                        $event->setResponse(['NOT-A-RESPONSE']);
                    }, 10);
                }
                if (in_array('throw', $modes, true)) {
                    $events->addListener('homepage/replace', static function (): void {
                        throw new \RuntimeException('the homepage listener broke');
                    }, 10);
                }
                if (in_array('late', $modes, true)) {
                    $events->addListener('homepage/replace', static function (\Idno\Core\Event $event): void {
                        $event->setResponse(new \Symfony\Component\HttpFoundation\Response('LATE-LISTENER-PAGE'));
                    }, -10);
                }
                if (in_array('erase', $modes, true)) {
                    $events->addListener('homepage/replace', static function (\Idno\Core\Event $event): void {
                        $event->setResponse(false);
                    }, -20);
                }
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
