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
#[SitePage(name: 'Fixture\UnmarkedShell', replaces: [ReplacedPage::class])]
final class UnmarkedShellController
{
    public function page(): void
    {
    }
}

/**
 * A page that moves to a controller must not lose its path to the legacy router. The legacy router's choice for a path is the first
 * registered pattern that matches, so when a shell's own registration is gone (or was never there) a BROADER pattern registered after it
 * (a profile's general page for its edit page) takes the path through `LegacyRouteOverride`, and the page's controller is never reached; and a
 * page that was a Markdown permalink answers its `.md` form only if the controller says it is one. For the representative paths of every
 * site page's route (its fixed path, a sample for each parameter) this asks the real table (the booted application: core and the plugins the
 * site enables) which pages the legacy router would serve, as `LegacyRouteOverride` does, and fails for one the controller does not list in
 * `replaces`, which is not a kept shell. The paths are samples that match the route (a route no sample matches is a violation too), and the table
 * is the booted site's: core and the plugins the rig enables (Styles and StandardSiteSync are not among them).
 */
final class SitePageClaimsTest extends TestCase
{
    /**
     * A path the route matches, as the legacy router is asked about it (the controller's `{lead}` and `{slash}` empty): its fixed path, and for each
     * parameter the first sample its requirement accepts. Null when none does (a sample that matches nothing proves nothing).
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
     * @return list<string> what a route of a site page loses to the legacy router's table
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
            $replaced = array_map(static fn (string $class): string => strtolower(ltrim($class, '\\')), $page->replaces);
            foreach ($page->replaces as $class) {
                if (is_a($class, \Idno\Common\MarkdownPermalink::class, true) && !$page->markdownPermalink) {
                    $found[] = $name . ': replaces ' . $class . ', a Markdown permalink, and does not say it is one (markdownPermalink)';
                }
            }
            $sample = self::sampleOf($route);
            if ($sample === null) {
                $found[] = $name . ': no sample path matches the route, so what the legacy router would do with it was not asked';
                continue;
            }
            foreach ([$sample, $sample . '/'] as $path) {
                $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $path]);
                foreach (PageHandler::claimsOf($table, $request) as $claim) {
                    if (!in_array(strtolower($claim['class']), $replaced, true)) {
                        $found[] = $name . ': the legacy router serves ' . $path . ' with ' . $claim['class'] . ', which the controller does not list in replaces';
                    }
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

    public function testAControllerThatKeepsItsShellLosesNothing(): void
    {
        $table = new PageHandler();
        $table->addRoute('/_test/claimable/?', ReplacedPage::class);
        $table->addRoute('/_test/claim(.*)', ClaimingPage::class);

        $this->assertSame([], self::violations(self::fixtureRoutes(), $table));
    }

    public function testABroaderPatternThatTakesTheShellsPlaceIsFound(): void
    {
        // the shell's registration is gone, and a general page registered after it takes the path
        $table = new PageHandler();
        $table->addRoute('/_test/(.*)', ClaimingPage::class);

        $found = self::violations(self::fixtureRoutes(), $table);

        $this->assertNotSame([], $found);
        $this->assertStringContainsString('claimable: the legacy router serves /_test/claimable with ' . ClaimingPage::class, implode("\n", $found));
        $this->assertStringContainsString('markdown: the legacy router serves /_test/markdown/abc', implode("\n", $found));
    }

    public function testAPluginsPageOnThePathIsFoundToo(): void
    {
        $table = new PageHandler();
        $table->addRoute('/_test/claimable/?', ReplacedPage::class);
        $table->hijackRoute('/_test/claimable/?', ClaimingPage::class);

        $this->assertStringContainsString(ClaimingPage::class, implode("\n", self::violations(self::fixtureRoutes(), $table)));
    }

    public function testASampleIsGeneratedFromTheRequirementAndFailsWhenNoneMatches(): void
    {
        $routes = new RouteCollection();
        $hex = new Route('/{lead}_test/claimable/{id}{slash}', ['_controller' => SitePageFixtureController::class . '::claimable', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?', 'id' => '[a-f0-9]{24}']);
        $never = new Route('/{lead}_test/claimable/{id}{slash}', ['_controller' => SitePageFixtureController::class . '::claimable', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?', 'id' => '[A-Z]{3}']);
        $routes->add('hex', $hex);
        $routes->add('never', $never);

        $this->assertSame('/_test/claimable/' . str_repeat('a', 24), (new \ReflectionMethod(self::class, 'sampleOf'))->invoke(null, $hex));
        $found = self::violations($routes, new PageHandler());
        $this->assertSame(['never: no sample path matches the route, so what the legacy router would do with it was not asked'], array_values(array_filter($found, static fn (string $line): bool => str_starts_with($line, 'never'))));
    }

    public function testAControllerThatReplacesAMarkdownPermalinkMustSayItIsOne(): void
    {
        $routes = new RouteCollection();
        $routes->add('unmarked', new Route('/{lead}_test/claimable{slash}', ['_controller' => UnmarkedShellController::class . '::page', 'lead' => '', 'slash' => ''], ['lead' => '/?', 'slash' => '/?']));

        $this->assertSame(['unmarked: replaces ' . ReplacedPage::class . ', a Markdown permalink, and does not say it is one (markdownPermalink)'], self::violations($routes, new PageHandler()));
    }

    public function testNoSitePageRouteOfTheSiteLosesItsPathToTheLegacyRouter(): void
    {
        $routes = Idno::container()->get('router')->getRouteCollection();
        $pages = 0;
        foreach ($routes as $route) {
            $controller = $route->getDefault('_controller');
            if ((is_string($controller) || is_array($controller)) && SitePage::of($controller) !== null) {
                ++$pages;
            }
        }
        $this->assertGreaterThan(0, $pages, 'the scan saw site page routes (the fixture\'s)');

        $this->assertSame([], self::violations($routes, Idno::site()->routes()));
    }
}
