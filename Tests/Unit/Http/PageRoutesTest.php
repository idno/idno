<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use Idno\Http\Page\SitePage;
use Idno\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

#[SitePage('Fixture\RoutesConvention')]
final class RoutesConventionController
{
    public function page(): void
    {
    }
}

#[SitePage('Fixture\RoutesConventionPost', methods: ['GET', 'POST'])]
final class RoutesConventionPostController
{
    public function page(): void
    {
    }
}

/**
 * The shape every route of a site page has, so that one controller answers the spellings of an address that the legacy router
 * answered (`/x`, `/x/`, `//x`, `//x/`) and nothing else about the address changes: a `{lead}` that may hold the doubled leading
 * slash and a `{slash}` that may hold the trailing one, both optional and empty by default, so the router redirects nothing; GET,
 * HEAD and OPTIONS for a page that reads, and exactly the methods a page declares otherwise; and a route whose last parameter may
 * hold any byte is not UTF-8 only, as the legacy router took any path.
 */
final class PageRoutesTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    private function router(): RouterInterface
    {
        $this->kernel = new Kernel('test', true, bootLegacy: false);
        $this->kernel->boot();

        return $this->kernel->getContainer()->get('router');
    }

    /**
     * @param array<string, string> $server
     */
    private function request(string $uri, string $method = 'GET', array $server = []): Request
    {
        return new Request([], [], [], [], [], $server + ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => '/srv/index.php', 'PHP_SELF' => '/index.php', 'HTTP_HOST' => 'localhost']);
    }

    /** Routes that break the convention on purpose, each with why */
    private const EXEMPT = [
        // routes more methods than its page declares, to prove the lifecycle refuses a method the page did not
        'idno_test_site_page_undeclared' => true,
    ];

    /**
     * @return list<string> what a route of a site page has that the convention does not allow
     */
    public static function violations(RouteCollection $routes): array
    {
        $found = [];
        foreach ($routes as $name => $route) {
            if (isset(self::EXEMPT[$name])) {
                continue;
            }
            $controller = $route->getDefault('_controller');
            $page = is_string($controller) || is_array($controller) ? SitePage::of($controller) : null;
            if ($page === null) {
                continue;
            }
            $path = $route->getPath();
            if (!str_starts_with($path, '/{lead}') && $path !== '/{slash}') {
                $found[] = $name . ': the path starts with {lead} (or is {slash} for the root)';
            }
            if (!str_ends_with($path, '{slash}')) {
                $found[] = $name . ': the path ends with {slash}';
            }
            foreach (['lead', 'slash'] as $parameter) {
                if (($route->getRequirement($parameter) ?? null) !== '/?') {
                    $found[] = $name . ': the requirement of {' . $parameter . '} is /?';
                }
                if (($route->getDefault($parameter) ?? null) !== '') {
                    $found[] = $name . ': the default of {' . $parameter . '} is empty';
                }
            }
            $methods = $route->getMethods();
            sort($methods);
            $declared = $page->allowedMethods();
            sort($declared);
            if ($methods !== $declared) {
                $found[] = $name . ': the methods are those the page declares plus HEAD and OPTIONS (' . implode(',', $declared) . '), not ' . implode(',', $methods);
            }
            // A free parameter: one whose requirement can match a byte that is not a letter, a digit or one of `_-`
            foreach ($route->getRequirements() as $parameter => $requirement) {
                if (in_array($parameter, ['lead', 'slash'], true)) {
                    continue;
                }
                if (preg_match('~^(?:[A-Za-z0-9_\-|]|\[[A-Za-z0-9_\-\\\\]+\]|\(\?:|\)|[+*?]|\{\d+(?:,\d*)?\})+$~', (string) $requirement) !== 1 && $route->getOption('utf8') !== false) {
                    $found[] = $name . ': the requirement of {' . $parameter . '} may hold any byte, so the route sets utf8: false';
                }
            }
        }

        return $found;
    }

    private static function route(string $path, array $options = [], array $methods = ['GET', 'HEAD', 'OPTIONS'], array $requirements = ['lead' => '/?', 'slash' => '/?'], array $defaults = ['lead' => '', 'slash' => ''], string $controller = RoutesConventionController::class): Route
    {
        return new Route($path, ['_controller' => $controller . '::page'] + $defaults, $requirements, $options, '', [], $methods);
    }

    public function testTheConventionsViolationsAreFound(): void
    {
        $routes = new RouteCollection();
        $routes->add('ok', self::route('/{lead}x{slash}'));
        $routes->add('ok_root', self::route('/{slash}', defaults: ['slash' => '', 'lead' => '']));
        $routes->add('ok_post', self::route('/{lead}x/post{slash}', methods: ['GET', 'HEAD', 'OPTIONS', 'POST'], controller: RoutesConventionPostController::class));
        $routes->add('ok_tag', self::route('/{lead}tag/{name}{slash}', ['utf8' => false], requirements: ['lead' => '/?', 'slash' => '/?', 'name' => '[^/]+']));
        $routes->add('ok_ascii', self::route('/{lead}n/{n}{slash}', requirements: ['lead' => '/?', 'slash' => '/?', 'n' => '[0-9]+']));
        $routes->add('no_lead', self::route('/x{slash}'));
        $routes->add('no_slash', self::route('/{lead}x'));
        $routes->add('bad_requirement', self::route('/{lead}x{slash}', requirements: ['lead' => '/?', 'slash' => '.*']));
        $routes->add('bad_default', self::route('/{lead}x{slash}', defaults: ['lead' => '/', 'slash' => '']));
        $routes->add('missing_method', self::route('/{lead}x{slash}', methods: ['GET']));
        $routes->add('undeclared_method', self::route('/{lead}x{slash}', methods: ['GET', 'HEAD', 'OPTIONS', 'POST']));
        $routes->add('free_but_utf8', self::route('/{lead}tag/{name}{slash}', requirements: ['lead' => '/?', 'slash' => '/?', 'name' => '[^/]+']));
        $routes->add('free_dot', self::route('/{lead}tag/{name}{slash}', requirements: ['lead' => '/?', 'slash' => '/?', 'name' => '.+']));
        $routes->add('not_a_page', new Route('/anything', ['_controller' => 'Idno\Legacy\LegacyController']));

        $found = self::violations($routes);

        $named = [];
        foreach ($found as $line) {
            $named[strstr($line, ':', true)][] = $line;
        }
        $this->assertSame(['no_lead', 'no_slash', 'bad_requirement', 'bad_default', 'missing_method', 'undeclared_method', 'free_but_utf8', 'free_dot'], array_keys($named), implode("\n", $found));
    }

    public function testEverySitePageRouteFollowsTheConvention(): void
    {
        $routes = $this->router()->getRouteCollection();
        $pages = 0;
        foreach ($routes as $route) {
            $controller = $route->getDefault('_controller');
            if ((is_string($controller) || is_array($controller)) && SitePage::of($controller) !== null) {
                ++$pages;
            }
        }

        $this->assertGreaterThan(0, $pages, 'the scan saw site page routes (the fixture\'s)');
        $this->assertSame([], self::violations($routes));
    }

    public function testAllFourSpellingsMatchWithoutRedirect(): void
    {
        $router = $this->router();

        foreach (['/_test/unclaimed', '//_test/unclaimed', '/_test/unclaimed/', '//_test/unclaimed/'] as $uri) {
            foreach (['GET', 'HEAD'] as $method) {
                $match = $router->matchRequest($this->request($uri, $method));
                $this->assertSame('idno_test_unclaimed', $match['_route'], $method . ' ' . $uri);
                $this->assertArrayNotHasKey('_controller_redirect', $match);
            }
        }
        $this->assertSame('idno_legacy', $router->matchRequest($this->request('///_test/unclaimed'))['_route'], 'a third slash is no address of the page, as the legacy router saw it');
    }

    public function testTheRouteMatchesABytePathThatIsNotUtf8(): void
    {
        $match = $this->router()->matchRequest($this->request('/_test/site-page/bytes/ab%FF'));

        $this->assertSame('idno_test_site_page_bytes', $match['_route']);
        $this->assertSame("ab\xFF", $match['rest']);
    }

    public function testOtherMethodsReachTheCatchAll(): void
    {
        $router = $this->router();

        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            foreach (['/_test/unclaimed', '//_test/unclaimed/', '/_test/claimable'] as $uri) {
                $this->assertSame('idno_legacy', $router->matchRequest($this->request($uri, $method))['_route'], $method . ' ' . $uri);
            }
        }
    }

    public function testOptionsIsTheControllers(): void
    {
        $router = $this->router();

        foreach (['/_test/unclaimed', '//_test/unclaimed/', '/_test/claimable'] as $uri) {
            $this->assertNotSame('idno_legacy', $router->matchRequest($this->request($uri, 'OPTIONS'))['_route'], $uri);
        }
    }

    public function testABaseUrlIsHonoured(): void
    {
        $router = $this->router();
        $server = ['SCRIPT_NAME' => '/blog/index.php', 'SCRIPT_FILENAME' => '/srv/blog/index.php', 'PHP_SELF' => '/blog/index.php'];

        $request = $this->request('/blog/_test/unclaimed/', 'GET', $server);
        $this->assertSame('/blog', $request->getBaseUrl());
        $this->assertSame('idno_test_unclaimed', $router->matchRequest($request)['_route']);
        $this->assertSame('idno_test_unclaimed', $router->matchRequest($this->request('/blog/_test/unclaimed', 'HEAD', $server))['_route']);
        $this->assertSame('idno_legacy', $router->matchRequest($this->request('/blog/_test/unclaimed', 'POST', $server))['_route']);
    }
}
