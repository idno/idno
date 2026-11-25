<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Core\PageHandler;
use Idno\Http\Page\SitePage;
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
 * A page that moves to a controller keeps core's place in the legacy router's order. The legacy router chooses the first registered pattern that matches
 * and core registers its pages before any plugin does, so a plugin's page took a core path only under a pattern that is core's own (`hijackRoute()` or an
 * identical `addRoute()` put it there) or a hijacked one. Each site page route therefore declares the patterns core registered for it (`legacyPatterns`),
 * and `LegacyRouteOverride` counts a plugin's claim only under exactly those. This checks every route against the real table (the booted site's: core
 * and the plugins the rig enables, Styles and StandardSiteSync not among them): the patterns are declared, each is registered with a class the controller
 * lists in `replaces` when it keeps a shell, and none is registered when the controller replaces no class (the class is deleted and so is its route:
 * a pattern still registered there would hand the path to a page nobody lists), and a declared pattern matches a path the route serves.
 */
final class SitePageClaimsTest extends TestCase
{
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

    /**
     * @return list<string> what is wrong with the site page routes, given the legacy router's table
     */
    public static function violations(RouteCollection $routes, PageHandler $table): array
    {
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
            $replaced = array_map(static fn (string $class): string => strtolower(ltrim($class, '\\')), $page->replaces);
            foreach ($page->legacyPatterns as $pattern) {
                $handlers = [];
                foreach (PageHandler::keysFor($pattern) as $key) {
                    if (isset($table[$key])) {
                        $handlers[] = ltrim((string) $table[$key], '\\');
                    }
                }
                if ($page->replaces === [] && $handlers !== []) {
                    $found[] = $name . ': replaces no class, and ' . $pattern . ' is registered (' . implode(', ', $handlers) . ')';
                } elseif ($page->replaces !== [] && $handlers === []) {
                    $found[] = $name . ': replaces ' . implode(', ', $page->replaces) . ', and ' . $pattern . ' is not registered';
                }
                foreach ($handlers as $handler) {
                    if ($page->replaces !== [] && !in_array(strtolower($handler), $replaced, true)) {
                        $found[] = $name . ': ' . $pattern . ' is registered for ' . $handler . ', which the controller does not list in replaces';
                    }
                }
            }
            $sample = self::sampleOf($route);
            if ($sample === null) {
                $found[] = $name . ': no sample path matches the route, so its patterns were not checked against it';
            } elseif ($page->legacyPatterns !== []) {
                $matches = false;
                foreach ($page->legacyPatterns as $pattern) {
                    foreach (PageHandler::keysFor($pattern) as $key) {
                        $matches = $matches || PageHandler::handlerFor([$key => 'x'], $sample) !== null;
                    }
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

    public function testAControllerThatKeepsItsShellAndAPluginsLaterPatternsAreFine(): void
    {
        $table = new PageHandler();
        $table->addRoute('/_test/claimable/?', ReplacedPage::class);
        $table->addRoute('/_test/claim(.*)', ClaimingPage::class);

        $this->assertSame([], self::violations(self::fixtureRoutes(), $table));
    }

    public function testAKeptShellThatIsNotRegisteredOrHoldsAnotherClassIsFound(): void
    {
        $found = self::violations(self::fixtureRoutes(), new PageHandler());
        $this->assertStringContainsString('claimable: replaces ' . ReplacedPage::class . ', and /_test/claimable/? is not registered', implode("\n", $found));

        $table = new PageHandler();
        $table->addRoute('/_test/claimable/?', ClaimingPage::class);
        $this->assertStringContainsString('claimable: /_test/claimable/? is registered for ' . ClaimingPage::class . ', which the controller does not list in replaces', implode("\n", self::violations(self::fixtureRoutes(), $table)));
    }

    public function testAPatternStillRegisteredForAClassTheControllerDoesNotReplaceIsFound(): void
    {
        $table = new PageHandler();
        $table->addRoute('/_test/claimable/?', ReplacedPage::class);
        $table->addRoute('/_test/markdown/([^/]+)/?', ClaimingPage::class);

        $this->assertStringContainsString('markdown: replaces no class, and /_test/markdown/([^/]+)/? is registered (' . ClaimingPage::class . ')', implode("\n", self::violations(self::fixtureRoutes(), $table)));
    }

    public function testARouteThatDoesNotDeclareItsPatternsOrDeclaresOnesThatDoNotMatchIsFound(): void
    {
        $routes = new RouteCollection();
        $routes->add('undeclared', new Route('/{lead}_test/claimable{slash}', ['_controller' => UndeclaredController::class . '::page', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?']));
        $routes->add('elsewhere', new Route('/{lead}_test/claimable{slash}', ['_controller' => ElsewhereController::class . '::page', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?']));

        $found = implode("\n", self::violations($routes, new PageHandler()));

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
        $this->assertStringContainsString('never: no sample path matches the route', implode("\n", self::violations($routes, new PageHandler())));
    }

    public function testAControllerThatReplacesAMarkdownPermalinkMustSayItIsOne(): void
    {
        $routes = new RouteCollection();
        $routes->add('unmarked', new Route('/{lead}_test/claimable{slash}', ['_controller' => UnmarkedShellController::class . '::page', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?']));
        $table = new PageHandler();
        $table->addRoute('/_test/claimable/?', ReplacedPage::class);

        $this->assertSame(['unmarked: replaces ' . ReplacedPage::class . ', a Markdown permalink, and does not say it is one (markdownPermalink)'], self::violations($routes, $table));
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
        $this->assertGreaterThan(0, $declared, 'and some with patterns to check against the table (the home page\'s, the fixture\'s)');

        $this->assertSame([], self::violations($routes, Idno::site()->routes()));
    }
}
