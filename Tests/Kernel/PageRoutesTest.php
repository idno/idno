<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Http\Page\SitePage;
use Idno\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Tests\Unit\Http\PageRouteConvention;

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
        $this->assertSame([], PageRouteConvention::violations($routes));
    }

    public function testNoRouteServesAnActionRoutedByTheLegacyTable(): void
    {
        // an action routed by the legacy table is reached through the table, never through the router's collection (the convention is for routes)
        foreach ($this->router()->getRouteCollection() as $name => $route) {
            $controller = $route->getDefault('_controller');
            if (!is_string($controller) || !str_contains($controller, '::')) {
                continue;
            }
            [$class, $method] = explode('::', $controller, 2);
            if (class_exists($class) && method_exists($class, $method)) {
                $this->assertSame([], (new \ReflectionMethod($class, $method))->getAttributes(\Idno\Http\Page\RoutedByLegacyTable::class), $name . ' is a route and is routed by the legacy table');
            }
        }
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
