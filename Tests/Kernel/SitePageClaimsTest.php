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


#[SitePage(name: 'Fixture\Moved', legacyPatterns: ['/feeds/?'])]
final class MovedController
{
    public function page(): void
    {
    }
}

#[SitePage(name: 'Fixture\NothingDeclared', legacyPatterns: [])]
final class NothingDeclaredController
{
    public function page(): void
    {
    }
}

/**
 * The legacy route table is exactly what it was before any page moved to a controller: core registers a moved page's pattern where and when it always
 * did, with a placeholder (`ControllerRoute`) in place of the class that was deleted (a kept shell is still registered under its own class), so the
 * legacy router's own rules decide what a plugin's registration does to it. This checks the booted site's real table (core and the plugins the rig enables,
 * Styles and StandardSiteSync not among them) against `fixtures/core-routes.php`, the WHOLE core table as it was at commit 69af0d425 (every key core and the
 * components it builds register, in order, with a marker where the plugins' routes are registered): each key is still there, in that order, on the same side of
 * the marker (a kept legacy page, a shell and a placeholder all count as the key being there), so a page that moves cannot drop its registration or register it
 * on the wrong side of the plugins, whichever component registered it. And every site page route: declares `legacyPatterns` (the patterns core registered for it,
 * as `addRoute()` received them; `[]` when core registered none, which fails if a snapshot pattern matches one of its paths: it must declare that one); has each
 * pattern in the table with a placeholder (a route that replaces no class) or a class the route lists in `replaces`, on the side of the marker the snapshot
 * says (derived, not listed); has the router's choice for a path it serves be a placeholder or shell registered under one of ITS OWN declared patterns; and
 * a declared pattern matches a path the route serves.
 */
final class SitePageClaimsTest extends TestCase
{
    /** The route a fixture registers when the plugins have loaded (the first thing registered after the plugins' own pages): the boundary of plugin time */
    private const PLUGIN_TIME = '/_test/site-page/draw-legacy/?';

    private const MARKER = '@plugins';

    /**
     * @return array{routes: list<string>, omitted: array<string, string>}
     */
    private static function snapshot(): array
    {
        return require __DIR__ . '/fixtures/core-routes.php';
    }

    /**
     * The snapshot's keys as the table keys them in this site (the permalink route of its configuration), the marker kept.
     *
     * @return list<string>
     */
    private static function expectedKeys(): array
    {
        $permalink = \Idno\Common\Entity::getPermalinkRoute();

        return array_map(static fn (string $key): string => $key === self::MARKER ? $key : self::keyOf(str_replace('{permalink}', $permalink, $key)), self::snapshot()['routes']);
    }

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
     * The key the legacy router chooses for a path: Toro's algorithm (an exact key first, then the patterns in order), which `PageHandler::handlerFor()` answers
     * with the handler only. (`testTheChosenKeyIsTheOneHandlerForChooses` pins that the two agree.)
     */
    private static function chosenKey(PageHandler $table, string $path): ?string
    {
        if (isset($table[$path])) {
            return $path;
        }
        foreach ($table as $pattern => $handler) {
            if (preg_match('#^/?' . strtr((string) $pattern, [':string' => '([a-zA-Z]+)', ':number' => '([0-9]+)', ':alpha' => '([a-zA-Z0-9-_]+)']) . '/?$#', $path) === 1) {
                return (string) $pattern;
            }
        }

        return null;
    }

    /**
     * The snapshot held against a table's keys: every snapshot key is registered, on the side of the marker it was on, in the order it was.
     *
     * @param list<string> $tableKeys the keys of the table, in order
     * @param list<string> $expected  the snapshot's keys as the table keys them, with the marker (`@plugins`) where the plugins' routes are registered
     *
     * @return list<string>
     */
    public static function snapshotViolations(array $tableKeys, array $expected, string $markerKey): array
    {
        $found = [];
        $keys = array_values(array_filter($expected, static fn (string $key): bool => $key !== self::MARKER));
        $markerAt = array_search($markerKey, $tableKeys, true);
        if ($markerAt === false) {
            $found[] = 'the boundary of plugin time (' . $markerKey . ') is not registered';
        }
        $snapshotMarker = array_search(self::MARKER, $expected, true);
        foreach ($keys as $key) {
            $position = array_search($key, $tableKeys, true);
            if ($position === false) {
                $found[] = $key . ' is not registered: core registers it, and a page that moved keeps its registration (a placeholder, or its shell) where it was';
                continue;
            }
            if ($markerAt !== false) {
                $expectedAfter = array_search($key, $expected, true) > $snapshotMarker;
                if ($expectedAfter !== ($position > $markerAt)) {
                    $found[] = $key . ' is registered ' . ($position > $markerAt ? 'after' : 'before') . ' the plugins\' routes, and core registered it ' . ($expectedAfter ? 'after' : 'before');
                }
            }
        }
        if ($found === []) {
            $core = array_values(array_filter($tableKeys, static fn (string $key): bool => $key === $markerKey || in_array($key, $keys, true)));
            $want = array_map(static fn (string $key): string => $key === self::MARKER ? $markerKey : $key, $expected);
            if ($core !== $want) {
                $at = 0;
                while (isset($core[$at], $want[$at]) && $core[$at] === $want[$at]) {
                    ++$at;
                }
                $found[] = 'the order of core\'s registrations changed: at position ' . $at . ' the table has ' . ($core[$at] ?? 'nothing') . ' where core registered ' . ($want[$at] ?? 'nothing');
            }
        }

        return $found;
    }

    /**
     * @param list<string>      $expected  the snapshot's keys (as the table keys them) with the marker
     * @param string|null       $markerKey the key that marks plugin time in the table; null to leave the side of the marker unchecked
     *
     * @return list<string> what is wrong with the site page routes, given the legacy router's table
     */
    public static function violations(RouteCollection $routes, PageHandler $table, array $expected, ?string $markerKey = self::PLUGIN_TIME): array
    {
        $keys = self::keysOf($table);
        $boundary = $markerKey === null ? false : array_search($markerKey, $keys, true);
        $snapshotMarker = array_search(self::MARKER, $expected, true);
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
            $declared = array_map(self::keyOf(...), $page->legacyPatterns);
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
                // the side of the plugins' routes core registered it on is the snapshot's: derived, not listed
                $snapshotAt = array_search($key, $expected, true);
                if ($snapshotAt === false && !str_starts_with($pattern, '/_test/')) {
                    $found[] = $name . ': ' . $pattern . ' is not one of the registrations core made before any page moved (fixtures/core-routes.php)';
                } elseif ($boundary !== false && $snapshotMarker !== false) {
                    $expectedAfter = $snapshotAt !== false && $snapshotAt > $snapshotMarker;
                    $position = array_search($key, $keys, true);
                    if ($expectedAfter !== ($position > $boundary)) {
                        $found[] = $name . ': ' . $pattern . ' is registered ' . ($position > $boundary ? 'after' : 'before') . ' the plugins\' routes, and core registered it ' . ($expectedAfter ? 'after' : 'before');
                    }
                }
            }
            $sample = self::sampleOf($route);
            if ($sample === null) {
                $found[] = $name . ': no sample path matches the route, so its patterns were not checked against it';
                continue;
            }
            if ($page->legacyPatterns === []) {
                // core registered nothing for it, so no pattern of core's may match its paths (it would be taking the place of a page that moved: declare that pattern)
                foreach ($expected as $key) {
                    if ($key !== self::MARKER && PageHandler::handlerFor([$key => 'x'], $sample) !== null) {
                        $found[] = $name . ': declares no legacyPatterns, and core\'s pattern ' . $key . ' matches ' . $sample . ', a path the route serves: declare it';
                    }
                }
                continue;
            }
            $chosenKey = self::chosenKey($table, $sample);
            $chosen = $chosenKey === null ? null : ltrim((string) $table[$chosenKey], '\\');
            if ($chosen === null || !in_array(strtolower($chosen), $replaced, true) || !in_array($chosenKey, $declared, true)) {
                $found[] = $name . ': the legacy router chooses ' . ($chosen ?? 'no page') . ' under ' . ($chosenKey ?? 'no pattern') . ' for ' . $sample . ', not the placeholder or shell registered under one of the route\'s own declared patterns';
            }
            $matches = false;
            foreach ($declared as $key) {
                $matches = $matches || PageHandler::handlerFor([$key => 'x'], $sample) !== null;
            }
            if (!$matches) {
                $found[] = $name . ': none of its legacyPatterns matches ' . $sample . ', a path the route serves';
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

    /**
     * @return list<string> a small snapshot: early keys, the marker, one late key
     */
    private static function smallSnapshot(): array
    {
        return ['/early/?', '/feed\.xml', self::MARKER, '/late/?'];
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

    public function testASnapshotKeyThatIsDroppedIsFound(): void
    {
        $this->assertSame([], self::snapshotViolations(['/early/?', '/feed\.xml', '/m', '/late/?'], self::smallSnapshot(), '/m'));

        $found = implode("\n", self::snapshotViolations(['/early/?', '/m', '/late/?'], self::smallSnapshot(), '/m'));
        $this->assertStringContainsString('/feed\.xml is not registered', $found, 'a moved page that deletes its registration');
        $this->assertStringContainsString('/late/? is not registered', implode("\n", self::snapshotViolations(['/early/?', '/feed\.xml', '/m'], self::smallSnapshot(), '/m')), 'a late component\'s page too');
    }

    public function testAKeyOnTheWrongSideOfThePluginsRoutesIsFound(): void
    {
        // a late page registered before the plugins (the placeholder put in `Idno::registerPages()`), and an early one registered after them
        $before = implode("\n", self::snapshotViolations(['/early/?', '/feed\.xml', '/late/?', '/m'], self::smallSnapshot(), '/m'));
        $this->assertStringContainsString('/late/? is registered before the plugins\' routes, and core registered it after', $before);
        $after = implode("\n", self::snapshotViolations(['/early/?', '/m', '/feed\.xml', '/late/?'], self::smallSnapshot(), '/m'));
        $this->assertStringContainsString('/feed\.xml is registered after the plugins\' routes, and core registered it before', $after);
    }

    public function testAnOrderThatChangedOnTheSameSideIsFound(): void
    {
        $found = implode("\n", self::snapshotViolations(['/feed\.xml', '/early/?', '/m', '/late/?'], self::smallSnapshot(), '/m'));

        $this->assertStringContainsString('the order of core\'s registrations changed', $found);
        $this->assertStringContainsString('the boundary of plugin time (/m) is not registered', implode("\n", self::snapshotViolations(['/early/?', '/feed\.xml', '/late/?'], self::smallSnapshot(), '/m')));
        // a plugin's own keys, anywhere, change nothing
        $this->assertSame([], self::snapshotViolations(['/a-plugin', '/early/?', '/feed\.xml', '/plugin-too', '/m', '/late/?', '/another'], self::smallSnapshot(), '/m'));
    }

    public function testThePositionRelativeToThePluginsIsCheckedAgainstTheSnapshotNotAList(): void
    {
        $snapshot = ['/_test/claimable/?', self::MARKER, '/_test/markdown/([^/]+)/?'];
        $table = new PageHandler();
        $table->addRoute('/_test/claimable/?', ReplacedPage::class);
        $table->addRoute(self::PLUGIN_TIME, ClaimingPage::class);
        $table->addRoute('/_test/markdown/([^/]+)/?', ControllerRoute::class);
        $this->assertSame([], self::violations(self::fixtureRoutes(), $table, $snapshot), 'registered after the plugins, as the snapshot has it');

        $early = new PageHandler();
        $early->addRoute('/_test/claimable/?', ReplacedPage::class);
        $early->addRoute('/_test/markdown/([^/]+)/?', ControllerRoute::class);
        $early->addRoute(self::PLUGIN_TIME, ClaimingPage::class);
        $this->assertStringContainsString('markdown: /_test/markdown/([^/]+)/? is registered before the plugins\' routes, and core registered it after', implode("\n", self::violations(self::fixtureRoutes(), $early, $snapshot)));
    }

    public function testADeclaredPatternThatIsNotCoresIsFound(): void
    {
        $routes = new RouteCollection();
        $routes->add('moved', new Route('/{lead}feed{slash}', ['_controller' => MovedController::class . '::page', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?']));
        $table = new PageHandler();
        $table->addRoute('/feeds/?', ControllerRoute::class);

        $this->assertStringContainsString('moved: /feeds/? is not one of the registrations core made before any page moved', implode("\n", self::violations($routes, $table, ['/feed\.xml', self::MARKER], null)));
    }

    public function testARouteThatDeclaresNothingButShadowsASnapshotPatternIsFound(): void
    {
        $routes = new RouteCollection();
        $routes->add('shadow', new Route('/{lead}feed.xml{slash}', ['_controller' => NothingDeclaredController::class . '::page', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?']));
        $routes->add('elsewhere', new Route('/{lead}somewhere{slash}', ['_controller' => NothingDeclaredController::class . '::page', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?']));
        $snapshot = ['/feed\.xml', '/feed/?', self::MARKER];

        $found = implode("\n", self::violations($routes, new PageHandler(), $snapshot, null));

        $this->assertStringContainsString('shadow: declares no legacyPatterns, and core\'s pattern /feed\.xml matches /feed.xml, a path the route serves: declare it', $found);
        $this->assertStringNotContainsString('elsewhere:', $found, 'a path no snapshot pattern matches');
    }

    public function testTheChoiceMustBeUnderTheRoutesOwnDeclaredPattern(): void
    {
        // the placeholder of ANOTHER route's pattern is not this route's place held
        $routes = new RouteCollection();
        $routes->add('feedish', new Route('/{lead}feed{slash}', ['_controller' => MovedController::class . '::page', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?']));
        $table = new PageHandler();
        $table->addRoute('/fe(.*)', ControllerRoute::class);     // another page's place, first in the table
        $table->addRoute('/feed/?', ControllerRoute::class);

        $found = implode("\n", self::violations($routes, $table, ['/fe(.*)', '/feed/?', self::MARKER], null));

        $this->assertStringContainsString('feedish: the legacy router chooses Idno\Legacy\ControllerRoute under /fe(.*) for /feed, not the placeholder or shell registered under one of the route\'s own declared patterns', $found);
    }

    public function testTheChosenKeyIsTheOneHandlerForChooses(): void
    {
        $table = new PageHandler();
        $table->addRoute('/(.*)', ClaimingPage::class);
        $table->addRoute('/feed/?', ControllerRoute::class);
        $table->addRoute('/exact', ReplacedPage::class);

        foreach (['/feed', '/exact', '/zzz', '/'] as $path) {
            $key = self::chosenKey($table, $path);
            $this->assertSame(PageHandler::handlerFor($table, $path), $key === null ? null : $table[$key], $path);
        }
        $this->assertSame('/exact', self::chosenKey($table, '/exact'), 'an exact key before any pattern');
    }

    public function testAHijackedPatternInFrontOfThePlaceholderIsFoundByTheChoice(): void
    {
        $table = self::fixtureTable();
        $table->hijackRoute('/_test/(.*)', ClaimingPage::class);

        $this->assertStringContainsString('the legacy router chooses ' . ClaimingPage::class . ' under /_test/(.*) for /_test/claimable', implode("\n", self::violations(self::fixtureRoutes(), $table, [], null)));
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

    public function testTheSnapshotIsWellFormed(): void
    {
        $snapshot = self::snapshot();

        $this->assertSame(1, count(array_keys($snapshot['routes'], self::MARKER, true)), 'one marker');
        $this->assertSame(array_values(array_unique($snapshot['routes'])), $snapshot['routes'], 'no key twice');
        foreach ($snapshot['omitted'] as $key => $reason) {
            $this->assertNotContains($key, $snapshot['routes'], $key . ' is omitted on purpose and is not in the snapshot');
            $this->assertNotSame('', trim($reason), $key . ' says why');
        }
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

        $this->assertSame([], self::violations($routes, Idno::site()->routes(), self::expectedKeys()));
    }

    public function testCoresRegistrationsAreTheSnapshotOfBeforeAnyPageMoved(): void
    {
        $this->assertSame([], self::snapshotViolations(self::keysOf(Idno::site()->routes()), self::expectedKeys(), self::keyOf(self::PLUGIN_TIME)), 'every core key is still registered, in order, on the same side of the plugins\' routes: a page that moves keeps its place');
    }
}
