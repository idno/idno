<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Core\PageHandler;
use Idno\Kernel;
use Idno\Legacy\LegacyController;
use Idno\Legacy\LegacyDispatcher;
use Idno\Legacy\LegacyRouteOverride;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Tests\Fixtures\ClaimingPage;
use Tests\Fixtures\ReplacedPage;
use Tests\Fixtures\SitePageFixtureController;
use Tests\Fixtures\SubclassedPage;

/**
 * The listener that keeps the legacy router's page for a path a site page's route matched, in process: when it acts, what it leaves
 * alone, and that it asks the router's table only for a request that needs it.
 */
final class LegacyRouteOverrideTest extends TestCase
{
    private int $fetched = 0;

    private PageHandler $routes;

    protected function setUp(): void
    {
        $this->fetched = 0;
        $this->routes = new PageHandler();
    }

    private function listener(bool $legacyBooted = true): LegacyRouteOverride
    {
        $kernel = new class('test', true, false, $legacyBooted) extends Kernel {
            public function __construct(string $environment, bool $debug, bool $bootLegacy, private readonly bool $legacyIsBooted)
            {
                parent::__construct($environment, $debug, $bootLegacy);
            }

            public function legacyBooted(): bool
            {
                return $this->legacyIsBooted;
            }
        };
        $dispatcher = new LegacyDispatcher($this->routes, Idno::site()->config());

        return new LegacyRouteOverride($kernel, function () use ($dispatcher): LegacyDispatcher {
            ++$this->fetched;

            return $dispatcher;
        });
    }

    /**
     * A request the router matched to a route of the fixture's site page, as the router leaves it.
     *
     * @param array<string, string> $server
     */
    private function matched(string $uri, string $method = 'GET', array $server = [], string $controller = SitePageFixtureController::class . '::claimable'): RequestEvent
    {
        $request = new Request([], [], [], [], [], $server + ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php', 'HTTP_HOST' => 'localhost']);
        $request->attributes->add(['_controller' => $controller, '_route' => 'idno_test_claimable', '_route_params' => ['lead' => '', 'slash' => ''], 'lead' => '', 'slash' => '']);

        return new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function assertLeftAlone(RequestEvent $event, string $why): void
    {
        $attributes = $event->getRequest()->attributes;
        $this->assertSame(SitePageFixtureController::class . '::claimable', $attributes->get('_controller'), $why);
        $this->assertSame('idno_test_claimable', $attributes->get('_route'), $why);
        $this->assertTrue($attributes->has('lead') && $attributes->has('slash'), $why . ': the route\'s parameters stay');
    }

    private function assertHandedToTheLegacyRouter(RequestEvent $event, string $why): void
    {
        $attributes = $event->getRequest()->attributes;
        $this->assertSame(LegacyController::class, $attributes->get('_controller'), $why);
        $this->assertSame('idno_legacy', $attributes->get('_route'), $why);
        $this->assertFalse($attributes->has('lead') || $attributes->has('slash'), $why . ': none of the route\'s parameters are left');
        $this->assertSame(['path'], array_keys($attributes->get('_route_params')), $why . ': what the catch-all route would have given');
        $this->assertSame(rtrim($attributes->get('path'), '/'), '_test/claimable', $why);
    }

    public function testAPathNoLegacyPageWasRegisteredForStaysTheControllers(): void
    {
        $this->routes->addRoute('/_test/other/?', ClaimingPage::class);
        $event = $this->matched('/_test/claimable');

        $this->listener()->onRequest($event);

        $this->assertLeftAlone($event, 'nothing matches');
        $this->assertSame(1, $this->fetched);
    }

    public function testTheShellTheControllerReplacesDoesNotKeepThePath(): void
    {
        $this->routes->addRoute('/_test/claimable/?', ReplacedPage::class);

        foreach (['/_test/claimable', '/_test/claimable/', '//_test/claimable'] as $uri) {
            $event = $this->matched($uri);
            $this->listener()->onRequest($event);
            $this->assertLeftAlone($event, $uri);
        }
    }

    public function testTheNameOfTheReplacedClassIsComparedWithoutALeadingBackslashOrCase(): void
    {
        $this->routes->addRoute('/_test/claimable/?', '\\' . strtoupper(ReplacedPage::class));

        $event = $this->matched('/_test/claimable');
        $this->listener()->onRequest($event);

        $this->assertLeftAlone($event, 'a class name is no case-sensitive');
    }

    public function testAHijackedPathKeepsTheLegacyPage(): void
    {
        $this->routes->addRoute('/_test/claimable/?', ReplacedPage::class);
        $this->routes->hijackRoute('/_test/claimable/?', ClaimingPage::class);

        foreach (['/_test/claimable', '/_test/claimable/', '//_test/claimable/'] as $uri) {
            $event = $this->matched($uri);
            $this->listener()->onRequest($event);
            $this->assertHandedToTheLegacyRouter($event, $uri);
        }
    }

    public function testAnIdenticalPatternRegisteredLaterKeepsTheLegacyPage(): void
    {
        $this->routes->addRoute('/_test/claimable/?', ReplacedPage::class);
        $this->routes->addRoute('/_test/claimable/?', ClaimingPage::class);

        $event = $this->matched('/_test/claimable');
        $this->listener()->onRequest($event);

        $this->assertHandedToTheLegacyRouter($event, 'identical');
    }

    public function testASubclassOfTheShellKeepsTheLegacyPage(): void
    {
        $this->routes->addRoute('/_test/claimable/?', ReplacedPage::class);
        $this->routes->hijackRoute('/_test/claimable/?', SubclassedPage::class);

        $event = $this->matched('/_test/claimable');
        $this->listener()->onRequest($event);

        $this->assertHandedToTheLegacyRouter($event, 'a class is never matched with its subclasses');
    }

    public function testADifferentPatternRegisteredAfterTheShellDoesNotKeepThePath(): void
    {
        $this->routes->addRoute('/_test/claimable/?', ReplacedPage::class);
        $this->routes->addRoute('/_test/(claimable|other)/?', ClaimingPage::class);

        $event = $this->matched('/_test/claimable');
        $this->listener()->onRequest($event);

        $this->assertLeftAlone($event, 'the router would have chosen the shell');
    }

    public function testThePathIsTheOneTheLegacyRouterSeesWithTheSubdirectory(): void
    {
        // a site in /blog registers its patterns under the subdirectory (PageHandler::addRoute() prefixes them), and its requests carry it
        $this->routes['/blog/_test/claimable/?'] = ClaimingPage::class;
        $server = ['SCRIPT_NAME' => '/blog/index.php', 'PHP_SELF' => '/blog/index.php'];

        $event = $this->matched('/blog/_test/claimable', 'GET', $server);
        $this->listener()->onRequest($event);
        $this->assertSame(LegacyController::class, $event->getRequest()->attributes->get('_controller'));

        $other = $this->matched('/_test/claimable', 'GET', $server);
        $this->listener()->onRequest($other);
        $this->assertLeftAlone($other, 'the path outside the subdirectory is not the page\'s');
    }

    public function testPathInfoIsReadBeforeTheRequestUri(): void
    {
        $this->routes->addRoute('/_test/claimable/?', ClaimingPage::class);

        $event = $this->matched('/index.php/elsewhere', 'GET', ['PATH_INFO' => '/_test/claimable']);
        $this->listener()->onRequest($event);

        $this->assertSame(LegacyController::class, $event->getRequest()->attributes->get('_controller'));
    }

    public function testEveryMethodOfAClaimedPathIsHandedOver(): void
    {
        $this->routes->addRoute('/_test/claimable/?', ClaimingPage::class);

        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $event = $this->matched('/_test/claimable', $method);
            $this->listener()->onRequest($event);
            $this->assertHandedToTheLegacyRouter($event, $method);
        }
    }

    public function testNothingIsAskedOfTheRouterForARequestThatIsNotASitePages(): void
    {
        $this->routes->addRoute('/_test/claimable/?', ClaimingPage::class);

        $ping = $this->matched('/_test/ping', 'GET', [], 'Tests\Fixtures\PingController::ping');
        $catchAll = $this->matched('/_test/claimable', 'GET', [], LegacyController::class);
        $closure = $this->matched('/_test/claimable');
        $closure->getRequest()->attributes->set('_controller', static fn (): null => null);
        $none = $this->matched('/_test/claimable');
        $none->getRequest()->attributes->remove('_controller');
        $unknown = $this->matched('/_test/claimable', 'GET', [], 'No\Such\Controller::page');

        foreach ([$ping, $catchAll, $closure, $none, $unknown] as $event) {
            $this->listener()->onRequest($event);
        }

        $this->assertSame(0, $this->fetched, 'the legacy router\'s table is never built for them');
        $this->assertSame(LegacyController::class, $catchAll->getRequest()->attributes->get('_controller'));
        $this->assertSame('Tests\Fixtures\PingController::ping', $ping->getRequest()->attributes->get('_controller'));
    }

    public function testASubRequestIsLeftAlone(): void
    {
        $this->routes->addRoute('/_test/claimable/?', ClaimingPage::class);
        $main = $this->matched('/_test/claimable');
        $sub = new RequestEvent($this->createMock(HttpKernelInterface::class), $main->getRequest(), HttpKernelInterface::SUB_REQUEST);

        $this->listener()->onRequest($sub);

        $this->assertLeftAlone($sub, 'a sub-request');
        $this->assertSame(0, $this->fetched);
    }

    public function testNothingHappensWithoutTheLegacyApplication(): void
    {
        $this->routes->addRoute('/_test/claimable/?', ClaimingPage::class);
        $event = $this->matched('/_test/claimable');

        $this->listener(legacyBooted: false)->onRequest($event);

        $this->assertLeftAlone($event, 'the legacy router is not there to serve it');
        $this->assertSame(0, $this->fetched, 'and its table is never asked for');
    }
}
