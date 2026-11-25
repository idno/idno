<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Core\Webservice;
use Idno\Entities\User;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageRedirect;
use Idno\Http\Page\SitePage;
use Idno\Legacy\BarePage;
use Idno\Legacy\ControllerPage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The current page a controller gives the legacy application (Idno::site()->currentPage()): what templates, helpers and
 * plugins ask of a page, answered from the request. Over the rig's site (the legacy application PHPUnit's bootstrap booted).
 */
final class ControllerPageTest extends TestCase
{
    private mixed $previousPage = false;

    private mixed $previousUri = null;

    private mixed $previousWalledGarden = null;

    private mixed $previousFavicons = null;

    private string $previousType = 'default';

    protected function setUp(): void
    {
        $site = Idno::site();
        $this->previousPage = $site->currentPage();
        $this->previousUri = $_SERVER['REQUEST_URI'] ?? null;
        $this->previousWalledGarden = $site->config()->walled_garden;
        $this->previousFavicons = $site->config()->user_avatar_favicons;
        $this->previousType = (string) $site->template()->getTemplateType();
    }

    protected function tearDown(): void
    {
        $site = Idno::site();
        $site->setCurrentPage($this->previousPage ?: new BarePage());
        $site->config()->walled_garden = $this->previousWalledGarden;
        $site->config()->user_avatar_favicons = $this->previousFavicons;
        $site->template()->setTemplateType($this->previousType);
        if ($this->previousUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->previousUri;
        }
    }

    private function page(string $uri = '/', string $method = 'GET', array $parameters = [], array $server = [], ?SitePage $site = null): ControllerPage
    {
        $request = Request::create($uri, $method, $parameters, [], [], $server);

        return new ControllerPage(new PageContext($request, $site ?? new SitePage('Idno\Pages\Homepage')));
    }

    public function testConstructingRunsNoInit(): void
    {
        $before = Idno::site()->currentPage();
        $ready = 0;
        Idno::site()->events()->addListener('page/ready', static function () use (&$ready): void {
            $ready++;
        });

        $page = $this->page();
        $page->xhr();
        new ControllerPage();

        $this->assertSame(0, $ready);
        $this->assertSame($before, Idno::site()->currentPage());
        $this->assertNotSame($page, Idno::site()->currentPage());
    }

    public function testWithNoContextItIsTheHomePageOfAnEmptyRequest(): void
    {
        $page = new ControllerPage();

        $this->assertSame('Idno\Pages\Homepage', $page->pageClassName());
        $this->assertSame('/', $page->context()->request()->getPathInfo());
        $this->assertSame('/', $page->context()->requestUri());
    }

    public function testInputIsTheContexts(): void
    {
        $page = $this->page('/x?a=query&n=1', 'POST', ['a' => 'post']);

        $this->assertSame('post', $page->getInput('a'));
        $this->assertSame('d', $page->getInput('nope', 'd'));
        $this->assertNull($page->getInput('nope'));
        $page->setInput('late', 'set');
        $this->assertSame('set', $page->getInput('late'));
        $this->assertSame('set', $page->context()->input('late'));
        $this->assertSame('FILTERED:post', $page->getInput('a', null, static fn ($name, $value) => 'FILTERED:' . $value));
        $this->assertSame([], $page->data());
    }

    public function testXhrAndArgumentsAreSetByTheConstructor(): void
    {
        $request = Request::create('/x', 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $request->attributes->set('_route_params', ['lead' => '/', 'id' => 'abc']);
        $page = new ControllerPage(new PageContext($request, new SitePage('Idno\Pages\Homepage')));

        $this->assertTrue($page->xhr());
        $this->assertTrue($page->xhr);
        $this->assertSame(['abc'], $page->arguments);
        $this->assertSame(['abc'], $page->arguments());

        $plain = $this->page();
        $this->assertFalse($plain->xhr());
        $this->assertFalse($plain->xhr);
        $this->assertSame([], $plain->arguments);
    }

    public function testResponseIsTheContextsStatus(): void
    {
        $page = $this->page();
        $this->assertSame(200, $page->response());

        $page->setResponse(404);

        $this->assertSame(404, $page->response());
        $this->assertSame(404, $page->context()->status());
    }

    public function testEntityAndPermalinkAreTheContexts(): void
    {
        $page = $this->page();
        $entity = $this->createStub(\Idno\Common\Entity::class);

        $this->assertNull($page->getEntity());
        $this->assertFalse($page->isPermalink());

        $page->setPermalink(true, $entity);
        $this->assertTrue($page->isPermalink());
        $this->assertSame($entity, $page->getEntity());
        $this->assertSame($entity, $page->context()->entity());

        $page->removeEntity();
        $this->assertNull($page->getEntity());
        $page->setEntity($entity);
        $this->assertSame($entity, $page->getEntity());
    }

    public function testOwnerIsFalseWhenNoneAndAUserWhenSet(): void
    {
        $page = $this->page();
        $this->assertFalse($page->getOwner());

        $page->setOwner('not a user');
        $this->assertFalse($page->getOwner());

        $user = new User();
        $page->setOwner($user);
        $this->assertSame($user, $page->getOwner());
        $this->assertSame($user, $page->context()->owner());
    }

    public function testAssetsAreFalseWhenNoneAndTheContextsWhenSet(): void
    {
        $page = $this->page();
        $this->assertFalse($page->getAssets('css'));

        $page->setAsset('site', '/site.css', 'css');

        $this->assertSame(['site' => '/site.css'], $page->getAssets('css'));
        $this->assertSame(['site' => '/site.css'], $page->context()->assets('css'));
        $this->assertFalse($page->getAssets('javascript'));
    }

    public function testCurrentUrlIsTheConfiguredSiteWithTheRawRequestUri(): void
    {
        $page = $this->page('/tag/a%2Bb?x=1&y=%20');
        $parts = parse_url((string) Idno::site()->config()->url);
        $expected = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/tag/a%2Bb?x=1&y=%20';

        $this->assertSame($expected, $page->currentUrl());
        $this->assertSame('/tag/a%2Bb?x=1&y=%20', $page->currentUrl(true)['path']);
    }

    public function testDoesPathMatchUsesTheRequestUri(): void
    {
        $page = $this->page('/admin/email');

        $this->assertTrue((bool) $page->doesPathMatch('/admin/email'));
        $this->assertFalse((bool) $page->doesPathMatch('/admin'));
        $this->assertSame(1, $this->page('/admin/email?x=1')->matchUrl('admin/email\?x=1'));
    }

    public function testReferrerIsTheRequestsOrEmpty(): void
    {
        $with = $this->page('/x', 'GET', [], ['HTTP_REFERER' => 'https://example.org/from']);
        $this->assertSame('https://example.org/from', $with->referrer());
        $this->assertSame('https://example.org/from', $with->getReferrer());

        $without = $this->page();
        $this->assertSame('', $without->referrer());
        $this->assertSame('', $without->getReferrer());
    }

    public function testIsAcceptedContentTypeReadsTheRequestsAccept(): void
    {
        $page = $this->page('/x', 'GET', [], ['HTTP_ACCEPT' => 'text/html;q=0.5,application/json;q=0.9']);

        $this->assertTrue($page->isAcceptedContentType('application/json'));
        $this->assertFalse($page->isAcceptedContentType('text/html'));
        $this->assertTrue($page->isAcceptedContentType('text/html', true));
        $this->assertFalse($this->page('/x', 'GET', [], ['HTTP_ACCEPT' => ''])->isAcceptedContentType('text/html'));
    }

    public function testSetNoCacheTakesOverCaching(): void
    {
        $page = $this->page();
        $this->assertFalse($page->context()->ownsCaching());

        $page->setNoCache();

        $this->assertTrue($page->context()->ownsCaching());
        $this->assertSame(
            ['Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0', 'Pragma' => 'no-cache'],
            $page->context()->caching()
        );
    }

    public function testSetLastModifiedHeaderAddsTheHeader(): void
    {
        $page = $this->page();

        $page->setLastModifiedHeader(1700000000);
        $page->lastModifiedGatekeeper(1700000000);

        $this->assertSame(['Last-Modified' => [\Idno\Core\Time::timestampToRFC2616(1700000000)]], $page->context()->headers());
    }

    public function testGetIconUsesTheContextsOwner(): void
    {
        Idno::site()->config()->user_avatar_favicons = true;
        $user = new User();
        $page = $this->page();
        $page->setOwner($user);
        Idno::site()->setCurrentPage($page);

        $this->assertSame($user->getIcon(), $page->getIcon());
    }

    public function testForwardToLoginAsAString(): void
    {
        $page = $this->page();
        $expected = Idno::site()->config()->getDisplayURL() . 'session/login/?fwd=' . Webservice::encodeValue('/a/b?c=1');

        $this->assertSame($expected, $page->forwardToLogin('/a/b?c=1', true));

        try {
            $page->forwardToLogin('/a/b?c=1');
            $this->fail('the page forwards');
        } catch (PageRedirect $redirect) {
            $this->assertSame($expected, $redirect->location);
        }
    }

    public function testDeniedNoContentGoneThrow(): void
    {
        $page = $this->page();

        foreach ([
            [fn () => $page->deniedContent(), AccessDeniedHttpException::class, 403],
            [fn () => $page->noContent(), NotFoundHttpException::class, 404],
            [fn () => $page->goneContent(), GoneHttpException::class, 410],
            [fn () => $page->gatekeeper(), AccessDeniedHttpException::class, 403],
        ] as [$call, $class, $status]) {
            try {
                $call();
                $this->fail('the page throws ' . $class);
            } catch (\Throwable $e) {
                $this->assertInstanceOf($class, $e);
                $this->assertSame($status, $page->response());
            }
        }
    }

    public function testForwardThrowsWithTheTemplateTypeCarried(): void
    {
        $page = $this->page();
        $home = Idno::site()->config()->getDisplayURL();

        try {
            $page->forward();
            $this->fail('the page forwards');
        } catch (PageRedirect $redirect) {
            $this->assertSame($home, $redirect->location);
        }

        Idno::site()->template()->setTemplateType('json');
        try {
            $page->forward($home . 'somewhere', false);
            $this->fail('the page forwards');
        } catch (PageRedirect $redirect) {
            $this->assertSame($home . 'somewhere?_t=json', $redirect->location);
        }
    }

    public function testExceptionRethrows(): void
    {
        $e = new \RuntimeException('boom');
        try {
            $this->page()->exception($e);
            $this->fail('the page rethrows');
        } catch (\RuntimeException $caught) {
            $this->assertSame($e, $caught);
        }
    }

    public function testBodyClassesNameTheSitePage(): void
    {
        $_SERVER['REQUEST_URI'] = '/account/settings/';
        $page = $this->page('/account/settings/', 'GET', [], [], new SitePage('Idno\Pages\Account\Settings'));
        Idno::site()->setCurrentPage($page);

        $classes = (string) Idno::site()->template()->getBodyClasses();

        $this->assertStringStartsWith('idno_pages_account_settings ', $classes);
        $this->assertStringContainsString('page-account-settings', $classes);
    }

    public function testIsSettingsPageAndIsAccountPageReadTheAttribute(): void
    {
        $routes = Idno::site()->routes();
        $account = $this->page('/', 'GET', [], [], new SitePage('A', accountPage: true));
        $settings = $this->page('/', 'GET', [], [], new SitePage('B', settingsPage: true));
        $plain = $this->page('/', 'GET', [], [], new SitePage('C'));

        $this->assertTrue($routes->isAccountPage($account));
        $this->assertTrue($routes->isSettingsPage($account), 'an account page is a settings page');
        $this->assertFalse($routes->isAccountPage($settings));
        $this->assertTrue($routes->isSettingsPage($settings));
        $this->assertFalse($routes->isAccountPage($plain));
        $this->assertFalse($routes->isSettingsPage($plain));
    }

    public function testIsRoutePublicReadsTheAttributeAndStillTakesAClassName(): void
    {
        $routes = Idno::site()->routes();

        $this->assertTrue($routes->isRoutePublic($this->page('/', 'GET', [], [], new SitePage('A', publicOnPrivateSite: true))));
        $this->assertFalse($routes->isRoutePublic($this->page()));
        $this->assertFalse($routes->isRoutePublic('Idno\Pages\Nonesuch'));
        $this->assertSame($routes->isRoutePublic(\Idno\Pages\Session\Login::class), $routes->isRoutePublic(new \Idno\Pages\Session\Login()));
    }

    public function testPublicGatekeeperHonoursTheAttribute(): void
    {
        Idno::site()->config()->walled_garden = true;
        $this->assertFalse(Idno::site()->session()->isLoggedOn());

        Idno::site()->setCurrentPage($this->page('/', 'GET', [], [], new SitePage('Public', publicOnPrivateSite: true)));
        Idno::site()->session()->publicGatekeeper();
        $this->addToAssertionCount(1);

        Idno::site()->setCurrentPage($this->page());
        $this->expectException(AccessDeniedHttpException::class);
        Idno::site()->session()->publicGatekeeper();
    }

    public function testBarePageRunsInitLikeAPage(): void
    {
        $ready = 0;
        Idno::site()->events()->addListener('page/ready', static function () use (&$ready): void {
            $ready++;
        });

        $page = new BarePage();

        $this->assertSame($page, Idno::site()->currentPage());
        $this->assertGreaterThanOrEqual(1, $ready);
        $this->assertSame('Idno\Pages\Homepage', $page->pageClassName());
        $this->assertSame('idno_pages_homepage', str_replace('\\', '_', strtolower($page->pageClassName())));
    }

    public function testAPlainPagesClassNameIsItsClass(): void
    {
        $this->assertSame(\Idno\Pages\Homepage::class, (new \Idno\Pages\Homepage())->pageClassName());
    }
}
