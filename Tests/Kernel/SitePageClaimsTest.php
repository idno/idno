<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Core\PageHandler;
use Idno\Http\Page\SitePage;
use Idno\Legacy\ControllerRoute;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Tests\Fixtures\ClaimingPage;
use Tests\Fixtures\ReplacedPage;
use Tests\Fixtures\SitePageFixtureController;

/** A controller that replaces a Markdown permalink and does not say it is one (not routed: the convention's check is proven on it) */
#[SitePage(name: 'Fixture\UnmarkedShell', replaces: [ReplacedPage::class], legacyPatterns: ['/_test/claimable/?'])]
final class UnmarkedShellController
{
    public function page(): void
    {
    }
}

#[SitePage(name: 'Fixture\\Undeclared')]
final class UndeclaredController
{
    public function page(): void
    {
    }
}

#[SitePage(name: 'Fixture\\Elsewhere', legacyPatterns: ['/_test/other/?'])]
final class ElsewhereController
{
    public function page(): void
    {
    }
}

/**
 * The legacy route table is exactly what it was before any page moved to a controller: core registers a moved page's pattern where and when it always
 * did, with a placeholder (`ControllerRoute`) in place of the class that was deleted (a kept shell is still registered under its own class), so the
 * legacy router's own rules decide what a plugin's registration does to it. This checks the booted site's real table (core and the plugins the rig enables,
 * Styles and StandardSiteSync not among them): every site page route declares `legacyPatterns` (the patterns core registered for it, as `addRoute()` received
 * them: `[]` when core registered none); each is present in the table with a placeholder (a route that replaces no class) or a class the route lists in
 * `replaces`, before the plugins' routes or after them as core registered it (the late ones are listed below, from the components `LegacyBootstrap::boot()`
 * builds after the plugins); the router's choice for a path the route serves is that placeholder or shell; a declared pattern matches a path the route serves;
 * and the keys of core's own registrations, in order, are those of the recorded snapshot, so that a page that moves cannot silently drop or reorder one.
 */
final class SitePageClaimsTest extends TestCase
{
    /** The declared patterns core registers AFTER the plugins' routes are registered (the component that registers them is built after `Idno`) */
    private const AFTER_THE_PLUGINS = ['/\.well\-known/webfinger/?'];

    /**
     * The keys of core's registrations of `Idno::registerPages()` and `Webfinger`, in the order the tree before any page moved registered them
     * (`{permalink}` is the permalink route of the site's configuration), less `/bookmarklet\.js`, which was removed outright and has no registration.
     */
    private const CORE_ORDER = [
        '/?',
        '/feed\\.xml',
        '/feed/?',
        '/rss\\.xml',
        '/content/([A-Za-z\\-\\/]+)+',
        '/view/:id/?',
        '/s/:id/?',
        '{permalink}/?',
        '/edit/:id/?',
        '/delete/:id/?',
        '/withdraw/:id/?',
        '/attachment/:id/:id/?',
        '/view/:id/annotations/:id?',
        '{permalink}/annotations/:id?',
        '{permalink}/annotations/:id/delete/?',
        '{permalink}/annotation/delete/?',
        '/annotation/post/?',
        '/share/?',
        '/chrome/manifest\\.json',
        '/service-worker(\\.min)?\\.js',
        '/file/mint/?',
        '/file/upload/?',
        '/file/picker/?',
        '/filepicker/?',
        '/file/(:id)(/.*)?',
        '/profile/([^\\/]+)/?',
        '/profile/([^\\/]+)/edit/?',
        '/profile/([^\\/]+)/([A-Za-z\\-\\/]+)+',
        '/actor/([^\\/]+)/?',
        '/search/?',
        '/search/mentions\\.json',
        '/tag/([^\\s]+)\\/?',
        '/search/users/?',
        '/robots\\.txt',
        '/autosave/?',
        '/drafts/?',
        '/begin/?',
        '/begin/register/?',
        '/begin/profile/?',
        '/begin/connect/?',
        '/begin/connect\\-forwarder/?',
        '/begin/publish/?',
        '/service/db/optimise/?',
        '/service/security/csrftoken/?',
        '/service/system/log/?',
        '/service/geo/geocoder/?',
        '/\\.well\\-known/webfinger/?',
    ];

    /** The route a fixture registers when the plugins have loaded (the first thing registered after the plugins' own pages): the boundary of plugin time */
    private const PLUGIN_TIME = '/_test/site-page/draw-legacy/?';

    /**
     * A path the route matches, as the legacy router is asked about it (the controller's `{lead}` and `{slash}` empty): its fixed path, and for each
     * parameter the first sample its requirement accepts. Null when none does.
     */
    private static function sampleOf(Route $route): ?string
    {
        foreach (['abc', '1', 'abc-1', 'a1b2', str_repeat('a', 24), str_repeat('a', 32), 'x.y'] as $value) {
            $path = strtr($route->getPath(), ['{lead}' => '', '{slash}' => '']);
            $path = (string) preg_replace('/\{[A-Za-z_]+\}/', $value, $path);
            if (preg_match($route->compile()->getRegex(), $path) === 1) {
                return $path;
            }
        }

        return null;
    }

    private static function keyOf(string $pattern): string
    {
        return strtr($pattern, (new PageHandler())->routeTokens());
    }

    /**
     * @return list<string> the keys of a table, in order
     */
    private static function keysOf(PageHandler $table): array
    {
        $keys = [];
        foreach ($table as $key => $handler) {
            $keys[] = (string) $key;
        }

        return $keys;
    }

    /**
     * @param  list<string> $afterThePlugins the declared patterns registered after the plugins' routes
     * @return list<string> what is wrong with the site page routes, given the legacy router's table
     */
    public static function violations(RouteCollection $routes, PageHandler $table, array $afterThePlugins = self::AFTER_THE_PLUGINS, ?string $pluginTime = self::PLUGIN_TIME): array
    {
        $keys = self::keysOf($table);
        $boundary = $pluginTime === null ? false : array_search(self::keyOf($pluginTime), $keys, true);
        $found = [];
        foreach ($routes as $name => $route) {
            $controller = $route->getDefault('_controller');
            $page = is_string($controller) || is_array($controller) ? SitePage::of($controller) : null;
            if ($page === null) {
                continue;
            }
            foreach ($page->replaces as $class) {
                if (is_a($class, \Idno\Common\MarkdownPermalink::class, true) && !$page->markdownPermalink) {
                    $found[] = $name . ': replaces ' . $class . ', a Markdown permalink, and does not say it is one (markdownPermalink)';
                }
            }
            if ($page->legacyPatterns === null) {
                $found[] = $name . ': does not declare legacyPatterns (the patterns core registered for it, or [] when it registered none)';
                continue;
            }
            $replaced = array_map(static fn (string $class): string => strtolower(ltrim($class, '\\')), $page->replaces === [] ? [ControllerRoute::class] : $page->replaces);
            foreach ($page->legacyPatterns as $pattern) {
                $key = self::keyOf($pattern);
                if (!isset($table[$key])) {
                    $found[] = $name . ': ' . $pattern . ' is not registered: core registers a moved page\'s pattern, with a placeholder or its shell';
                    continue;
                }
                $handler = ltrim((string) $table[$key], '\\');
                if (!in_array(strtolower($handler), $replaced, true)) {
                    $found[] = $name . ': ' . $pattern . ' is registered for ' . $handler . ', which is neither the placeholder nor a class the controller lists in replaces';
                }
                if ($boundary !== false) {
                    $position = array_search($key, $keys, true);
                    $late = in_array($pattern, $afterThePlugins, true);
                    if ($late !== ($position > $boundary)) {
                        $found[] = $name . ': ' . $pattern . ' is registered ' . ($position > $boundary ? 'after' : 'before') . ' the plugins\', and core registered it ' . ($late ? 'after' : 'before');
                    }
                }
            }
            $sample = self::sampleOf($route);
            if ($sample === null) {
                $found[] = $name . ': no sample path matches the route, so its patterns were not checked against it';
            } elseif ($page->legacyPatterns !== []) {
                $chosen = PageHandler::chosenFor($table, $sample);
                if ($chosen === null || !in_array(strtolower($chosen), $replaced, true)) {
                    $found[] = $name . ': the legacy router chooses ' . ($chosen ?? 'no page') . ' for ' . $sample . ', not the placeholder or a shell the controller lists';
                }
                $matches = false;
                foreach ($page->legacyPatterns as $pattern) {
                    $matches = $matches || PageHandler::handlerFor([self::keyOf($pattern) => 'x'], $sample) !== null;
                }
                if (!$matches) {
                    $found[] = $name . ': none of its legacyPatterns matches ' . $sample . ', a path the route serves';
                }
            }
        }

        return array_values(array_unique($found));
    }

    private static function fixtureRoutes(): RouteCollection
    {
        $routes = new RouteCollection();
        $routes->add('claimable', new Route('/{lead}_test/claimable{slash}', ['_controller' => SitePageFixtureController::class . '::claimable', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?']));
        $routes->add('markdown', new Route('/{lead}_test/markdown/{name}{slash}', ['_controller' => SitePageFixtureController::class . '::markdown', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?', 'name' => '[^/]+']));

        return $routes;
    }

    private static function fixtureTable(): PageHandler
    {
        $table = new PageHandler();
        $table->addRoute('/_test/claimable/?', ReplacedPage::class);
        $table->addRoute('/_test/markdown/([^/]+)/?', ControllerRoute::class);

        return $table;
    }

    public function testAKeptShellAndAPlaceholderHoldCoresPlace(): void
    {
        $table = self::fixtureTable();
        $table->addRoute('/_test/claim(.*)', ClaimingPage::class);   // a plugin's later pattern changes nothing

        $this->assertSame([], self::violations(self::fixtureRoutes(), $table, [], null));
    }

    public function testAPatternThatIsNotRegisteredOrHoldsAnotherClassIsFound(): void
    {
        $found = implode("\n", self::violations(self::fixtureRoutes(), new PageHandler(), [], null));
        $this->assertStringContainsString('claimable: /_test/claimable/? is not registered', $found);
        $this->assertStringContainsString('markdown: /_test/markdown/([^/]+)/? is not registered', $found);

        $table = self::fixtureTable();
        $table->addRoute('/_test/claimable/?', ClaimingPage::class);
        $table->addRoute('/_test/markdown/([^/]+)/?', ReplacedPage::class);
        $found = implode("\n", self::violations(self::fixtureRoutes(), $table, [], null));
        $this->assertStringContainsString('claimable: /_test/claimable/? is registered for ' . ClaimingPage::class . ', which is neither the placeholder nor a class the controller lists in replaces', $found);
        $this->assertStringContainsString('markdown: /_test/markdown/([^/]+)/? is registered for ' . ReplacedPage::class, $found, 'a controller that replaces no class holds only the placeholder');
    }

    public function testThePositionRelativeToThePluginsIsCheckedAgainstWhatCoreRegistered(): void
    {
        $table = new PageHandler();
        $table->addRoute('/_test/claimable/?', ReplacedPage::class);
        $table->addRoute(self::PLUGIN_TIME, ClaimingPage::class);
        $table->addRoute('/_test/markdown/([^/]+)/?', ControllerRoute::class);

        $found = implode("\n", self::violations(self::fixtureRoutes(), $table));
        $this->assertStringContainsString('markdown: /_test/markdown/([^/]+)/? is registered after the plugins\', and core registered it before', $found);
        $this->assertSame([], self::violations(self::fixtureRoutes(), $table, ['/_test/markdown/([^/]+)/?']), 'declared as registered after the plugins');
    }

    public function testAHijackedPatternInFrontOfThePlaceholderIsFoundByTheChoice(): void
    {
        $table = self::fixtureTable();
        $table->hijackRoute('/_test/(.*)', ClaimingPage::class);

        $this->assertStringContainsString('the legacy router chooses ' . ClaimingPage::class . ' for /_test/claimable', implode("\n", self::violations(self::fixtureRoutes(), $table, [], null)));
    }

    public function testARouteThatDoesNotDeclareItsPatternsOrDeclaresOnesThatDoNotMatchIsFound(): void
    {
        $routes = new RouteCollection();
        $routes->add('undeclared', new Route('/{lead}_test/claimable{slash}', ['_controller' => UndeclaredController::class . '::page', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?']));
        $routes->add('elsewhere', new Route('/{lead}_test/claimable{slash}', ['_controller' => ElsewhereController::class . '::page', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?']));
        $table = new PageHandler();
        $table->addRoute('/_test/other/?', ControllerRoute::class);

        $found = implode("\n", self::violations($routes, $table, [], null));

        $this->assertStringContainsString('undeclared: does not declare legacyPatterns', $found);
        $this->assertStringContainsString('elsewhere: none of its legacyPatterns matches /_test/claimable', $found);
    }

    public function testASampleIsGeneratedFromTheRequirementAndFailsWhenNoneMatches(): void
    {
        $routes = new RouteCollection();
        $hex = new Route('/{lead}_test/claimable/{id}{slash}', ['_controller' => ElsewhereController::class . '::page', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?', 'id' => '[a-f0-9]{24}']);
        $never = new Route('/{lead}_test/claimable/{id}{slash}', ['_controller' => ElsewhereController::class . '::page', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?', 'id' => '[A-Z]{3}']);
        $routes->add('never', $never);

        $this->assertSame('/_test/claimable/' . str_repeat('a', 24), (new \ReflectionMethod(self::class, 'sampleOf'))->invoke(null, $hex));
        $this->assertStringContainsString('never: no sample path matches the route', implode("\n", self::violations($routes, new PageHandler(), [], null)));
    }

    public function testAControllerThatReplacesAMarkdownPermalinkMustSayItIsOne(): void
    {
        $routes = new RouteCollection();
        $routes->add('unmarked', new Route('/{lead}_test/claimable{slash}', ['_controller' => UnmarkedShellController::class . '::page', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?']));
        $table = new PageHandler();
        $table->addRoute('/_test/claimable/?', ReplacedPage::class);

        $this->assertSame(['unmarked: replaces ' . ReplacedPage::class . ', a Markdown permalink, and does not say it is one (markdownPermalink)'], self::violations($routes, $table, [], null));
    }

    public function testNoSitePageRouteOfTheSiteBreaksItsDeclaration(): void
    {
        $routes = Idno::container()->get('router')->getRouteCollection();
        $pages = 0;
        $declared = 0;
        foreach ($routes as $route) {
            $controller = $route->getDefault('_controller');
            $page = (is_string($controller) || is_array($controller)) ? SitePage::of($controller) : null;
            if ($page !== null) {
                ++$pages;
                $declared += (int) ($page->legacyPatterns !== null && $page->legacyPatterns !== []);
            }
        }
        $this->assertGreaterThan(0, $pages, 'the scan saw site page routes');
        $this->assertGreaterThan(10, $declared, 'and routes with patterns to check against the table (the home page\'s, the small endpoints\', the fixture\'s)');

        $table = Idno::site()->routes();
        $this->assertContains(self::keyOf(self::PLUGIN_TIME), self::keysOf($table), 'the boundary of plugin time is registered');
        $this->assertSame([], self::violations($routes, $table));
    }

    public function testCoresRegistrationsAreInTheOrderTheyWereBeforeAnyPageMoved(): void
    {
        $permalink = \Idno\Common\Entity::getPermalinkRoute();
        $expected = array_map(static fn (string $pattern): string => self::keyOf(str_replace('{permalink}', $permalink, $pattern)), self::CORE_ORDER);

        $actual = array_values(array_filter(self::keysOf(Idno::site()->routes()), static fn (string $key): bool => in_array($key, $expected, true)));

        $this->assertSame($expected, $actual, 'the keys of core\'s registrations, and their order, are the recorded snapshot: a page that moves keeps its place');
    }
}
