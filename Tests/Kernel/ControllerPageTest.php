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

    /** @var int counts `page/ready` while a test listens; the listener cannot be removed, so it is switched off in tearDown() */
    private int $ready = 0;

    private ?\Closure $readyListener = null;

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
        if ($this->readyListener !== null) {
            Idno::site()->events()->removeListener('page/ready', $this->readyListener);
            $this->readyListener = null;
        }
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

    private function listenForReady(): void
    {
        $this->ready = 0;
        $this->readyListener = function (): void {
            $this->ready++;
        };
        Idno::site()->events()->addListener('page/ready', $this->readyListener);
    }

    private function page(string $uri = '/', string $method = 'GET', array $parameters = [], array $server = [], ?SitePage $site = null): ControllerPage
    {
        $request = Request::create($uri, $method, $parameters, [], [], $server);

        return new ControllerPage(new PageContext($request, $site ?? new SitePage('Idno\Pages\Homepage')));
    }

    public function testConstructingRunsNoInit(): void
    {
        $before = Idno::site()->currentPage();
        $this->listenForReady();

        $page = $this->page();
        $page->xhr();
        new ControllerPage();

        $this->assertSame(0, $this->ready);
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

    public function testTheXhrFlagCanWaitForTheLifecycleToStartIt(): void
    {
        $request = Request::create('/x', 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $page = new ControllerPage(new PageContext($request, new SitePage('Idno\Pages\Homepage')), false);

        $this->assertFalse($page->xhr());
        $this->assertFalse($page->xhr);

        $page->startXhr();
        $this->assertTrue($page->xhr());
        $this->assertTrue($page->xhr);
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
            ['Cache-Control' => ['no-store, no-cache, must-revalidate, max-age=0', 'post-check=0, pre-check=0'], 'Pragma' => 'no-cache'],
            $page->context()->caching(),
            'the two Cache-Control lines and the Pragma that `Page::setNoCache()` sent'
        );
    }

    public function testSetLastModifiedHeaderAddsTheHeader(): void
    {
        $page = $this->page();

        $page->setLastModifiedHeader(1700000000);
        $page->lastModifiedGatekeeper(1700000000);

        $this->assertSame(['Last-Modified' => [\Idno\Core\Time::timestampToRFC2616(1700000000)]], $page->context()->headers());
    }

    public function testTheGatekeeperStopsThePageForACurrentCopy(): void
    {
        $page = $this->page('/', 'GET', [], ['HTTP_IF_MODIFIED_SINCE' => \Idno\Http\Page\HttpDate::format(1700000000 + 86400)]);

        $this->expectException(\Idno\Http\Page\PageNotModified::class);

        $page->lastModifiedGatekeeper(1700000000);
    }

    public function testTheGatekeeperGoesOnForACopyFromBeforeTheChange(): void
    {
        $page = $this->page('/', 'GET', [], ['HTTP_IF_MODIFIED_SINCE' => \Idno\Http\Page\HttpDate::format(1700000000 - 1)]);

        $page->lastModifiedGatekeeper(1700000000);
        $page->lastModifiedGatekeeper('1700000000');

        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider timesThatAreNoDate
     */
    public function testTheGatekeeperNeverAsksWithoutADate(mixed $timestamp): void
    {
        // a post with no `updated` gives null or 0: it has no change to compare with, and the legacy page never answered 304 for one
        $page = $this->page('/', 'GET', [], ['HTTP_IF_MODIFIED_SINCE' => \Idno\Http\Page\HttpDate::format(PHP_INT_MAX >> 33)]);

        $page->lastModifiedGatekeeper($timestamp);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function timesThatAreNoDate(): array
    {
        return ['null' => [null], 'an empty string' => [''], 'zero' => [0], 'zero as text' => ['0'], 'a negative number' => [-5], 'text' => ['yesterday'], 'false' => [false]];
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
            $page->forward($home . 'somewhere');
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
        $this->listenForReady();

        $page = new BarePage();

        $this->assertSame($page, Idno::site()->currentPage());
        $this->assertGreaterThanOrEqual(1, $this->ready);
        $this->assertSame('Idno\Pages\Homepage', $page->pageClassName());
        $this->assertSame('idno_pages_homepage', str_replace('\\', '_', strtolower($page->pageClassName())));
    }

    public function testAPlainPagesClassNameIsItsClass(): void
    {
        $this->assertSame(\Idno\Pages\Homepage::class, (new \Idno\Pages\Homepage())->pageClassName());
    }

    public function testForwardWithNoExitRecordsTheRedirectAndReturns(): void
    {
        $page = $this->page();
        $location = Idno::site()->config()->getDisplayURL() . 'later';

        $page->forward($location, false);

        $redirect = $page->context()->redirect();
        $this->assertNotNull($redirect);
        $this->assertSame($location, $redirect->location);
        $this->assertNull($redirect->body);
        $this->assertSame(302, $redirect->status);
    }

    public function testAnXhrPostCannotForward(): void
    {
        $request = Request::create('/x', 'POST', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $page = new ControllerPage(new PageContext($request, new SitePage('Idno\Pages\Homepage')));

        $page->forward('/somewhere');
        $page->forward('/somewhere', false);

        $this->assertNull($page->context()->redirect());
    }

    public function testAnApiRequestGetsTheJsonALegacyPageEchoed(): void
    {
        $session = Idno::site()->session();
        $session->authenticateStatelessly(new User());
        try {
            $page = $this->page();
            $location = Idno::site()->config()->getDisplayURL() . 'api-place';
            try {
                $page->forward($location);
                $this->fail('the page forwards');
            } catch (PageRedirect $redirect) {
                $this->assertSame(['location' => $location], json_decode((string) $redirect->body, true));
            }
        } finally {
            $session->resetStatelessAuthentication();
        }
    }

    public function testForwardTraceIsRecordedAsAHeaderAndLogged(): void
    {
        $config = Idno::site()->config();
        $previous = $config->forward_trace;
        $config->forward_trace = true;
        try {
            $page = $this->page();
            $page->forward($config->getDisplayURL() . 'traced', false);
        } finally {
            $config->forward_trace = $previous;
        }

        $trace = $page->context()->headers()['X-Idno-Forward-Trace'][0] ?? '';
        $this->assertStringContainsString('Forward at ' . __FILE__ . ':', $trace);
        $this->assertStringContainsString('called by', $trace);
    }

    public function testAfterResponseWorkIsKeptNotSkippedAndThePageForwards(): void
    {
        $page = $this->page();
        $ran = false;

        try {
            \Idno\Core\AfterResponse::run($page, Idno::site()->config()->getDisplayURL() . 'done', function () use (&$ran): void {
                $ran = true;
            });
            $this->fail('the page forwards');
        } catch (PageRedirect $redirect) {
            $this->assertSame(Idno::site()->config()->getDisplayURL() . 'done', $redirect->location);
        }

        $this->assertFalse($ran, 'the work waits for the response');
        $this->assertCount(1, $page->context()->afterResponseWork());
        ($page->context()->afterResponseWork()[0])();
        $this->assertTrue($ran);
    }

    public function testDeniedContentCarriesTheTitle(): void
    {
        try {
            $this->page()->deniedContent('Not for you');
            $this->fail('refused');
        } catch (AccessDeniedHttpException $e) {
            $this->assertSame('Not for you', $e->getMessage());
        }
    }

    public function testMarkdownIsWantedFromTheRequestNotTheProcess(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $this->assertTrue($this->page('/x', 'GET', [], ['HTTP_ACCEPT' => 'text/markdown'])->wantsMarkdown());
        $this->assertTrue($this->page('/x?format=markdown')->wantsMarkdown());
        $this->assertTrue($this->page('/x?_t=md')->wantsMarkdown());
        $this->assertFalse($this->page('/x', 'GET', [], ['HTTP_ACCEPT' => 'text/html'])->wantsMarkdown());

        $page = $this->page('/x', 'GET', [], ['HTTP_ACCEPT' => 'text/html']);
        $this->assertFalse($page->wantsMarkdown());
        $page->context()->setMarkdownSuffix();
        $this->assertTrue($page->wantsMarkdown());
        unset($_SERVER['HTTP_ACCEPT']);
    }

    public function testSendVaryAcceptIsRecordedOnTheContext(): void
    {
        $page = $this->page();
        $page->sendVaryAccept();
        $page->sendVaryAccept();

        $this->assertSame(['Vary' => ['Accept', 'Accept']], $page->context()->headers());
        $this->assertFalse($page->context()->replacesHeader('Vary'));
    }

    public function testSelectMarkdownIfWantedSelectsTheType(): void
    {
        $page = $this->page('/x?format=markdown');

        $this->assertTrue($page->selectMarkdownIfWanted());
        $this->assertSame('markdown', Idno::site()->template()->getTemplateType());
    }

    public function testDrawMarkdownReturnsTheDocumentAndRecordsItsHeaders(): void
    {
        $entity = new \IdnoPlugins\Status\Status();
        $entity->body = 'Hello';
        $entity->setOwner(new User());
        $page = $this->page('/x');
        Idno::site()->setCurrentPage($page);

        $markdown = $page->drawMarkdown($entity);

        $this->assertIsString($markdown);
        $this->assertStringStartsWith('---', $markdown);
        $headers = $page->context()->headers();
        $this->assertSame(['text/markdown; charset=utf-8'], $headers['Content-Type']);
        $this->assertSame(['nosniff'], $headers['X-Content-Type-Options']);
        $this->assertArrayNotHasKey('Link', $headers, 'the canonical link waits for the response that is returned');
        $link = $page->context()->headersOnSuccess();
        $this->assertCount(1, $link);
        $this->assertSame('Link', $link[0][0]);
        $this->assertStringContainsString('rel="canonical"', $link[0][1]);

        $missing = $this->page('/x');
        $missing->setResponse(404);
        Idno::site()->setCurrentPage($missing);
        $missing->drawMarkdown($entity);
        $this->assertSame($link, $missing->context()->headersOnSuccess(), 'whatever the page\'s own status, the response decides');
        $this->assertSame(404, $missing->response(), 'the process status is not read');
    }

    public function testTheStatusTheTemplateAsksIsTheControllerPagesNotTheProcess(): void
    {
        $page = $this->page();
        $page->setResponse(404);
        Idno::site()->setCurrentPage($page);
        $method = new \ReflectionMethod(Idno::site()->template(), 'pageStatus');
        $method->setAccessible(true);

        $this->assertSame(404, $method->invoke(Idno::site()->template()));

        Idno::site()->setCurrentPage(new BarePage());
        $this->assertSame(http_response_code() ?: 200, $method->invoke(Idno::site()->template()));
    }

    public function testTheRouterDispatchIsNotAvailable(): void
    {
        $page = $this->page();

        foreach (['head', 'get', 'post', 'put', 'delete', 'options', 'webmention', 'flushBrowser', 'parseJSONPayload', 'get_xhr', 'post_xhr', 'put_xhr', 'delete_xhr', 'head_xhr'] as $method) {
            try {
                $page->$method();
                $this->fail($method . ' must not run');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('Page::' . $method . '()', $e->getMessage());
            }
        }
    }

    public function testIsSecureReadsTheRequest(): void
    {
        $this->assertFalse($this->page()->isSecure());
        $this->assertTrue($this->page('/x', 'GET', [], ['HTTPS' => 'on'])->isSecure());
        $this->assertTrue($this->page('/x', 'GET', [], ['HTTP_X_FORWARDED_PROTO' => 'HTTPS'])->isSecure());
        $this->assertTrue($this->page('/x', 'GET', [], ['SERVER_PORT' => '443'])->isSecure());
        $this->assertFalse($this->page('/x', 'GET', [], ['HTTPS' => 'off', 'SERVER_PORT' => '443'])->isSecure());
    }

    public function testSslGatekeeperRedirectsWith307WhenTheSiteWantsHttps(): void
    {
        $config = Idno::site()->config();
        $previous = $config->url;
        $config->url = 'https://' . preg_replace('~^https?://~', '', (string) $previous);
        try {
            $page = $this->page('/secret');
            try {
                $page->sslGatekeeper();
                $this->fail('the page redirects');
            } catch (PageRedirect $redirect) {
                $this->assertSame(307, $redirect->status);
                $this->assertStringStartsWith('https://', $redirect->location);
                $this->assertStringEndsWith('/secret', $redirect->location);
            }
            $this->page('/secret', 'GET', [], ['HTTPS' => 'on'])->sslGatekeeper();
        } finally {
            $config->url = $previous;
        }
        $this->addToAssertionCount(1);
    }

    public function testWhatWasRegisteredWithTheRouterForTheClassStillApplies(): void
    {
        $routes = Idno::site()->routes();
        $routes->addRoute('/account/controller-page-fixture', ControllerPageRegisteredFixture::class);
        $routes->addRoute('/admin/controller-page-fixture-admin', ControllerPageRegisteredAdminFixture::class);
        $routes->addPublicRoute(ControllerPagePublicFixture::class);

        $account = $this->page('/', 'GET', [], [], new SitePage(ControllerPageRegisteredFixture::class));
        $admin = $this->page('/', 'GET', [], [], new SitePage('\\' . ControllerPageRegisteredAdminFixture::class, shell: true));
        $public = $this->page('/', 'GET', [], [], new SitePage(ControllerPagePublicFixture::class, shell: true));
        $publicByName = $this->page('/', 'GET', [], [], new SitePage(ControllerPagePublicFixture::class));
        $none = $this->page('/', 'GET', [], [], new SitePage('Some\\New\\Name'));

        $this->assertTrue($routes->isAccountPage($account), 'registered under /account/, the flag left off');
        $this->assertTrue($routes->isSettingsPage($account));
        $this->assertFalse($routes->isAccountPage($admin));
        $this->assertTrue($routes->isSettingsPage($admin), 'a replaced class registered under /admin/');
        $this->assertTrue($routes->isRoutePublic($public), 'a replaced class a plugin made public');
        $this->assertTrue($routes->isRoutePublic($publicByName));
        $this->assertFalse($routes->isAccountPage($none));
        $this->assertFalse($routes->isSettingsPage($none));
        $this->assertFalse($routes->isRoutePublic($none));
    }

    public function testAnApiRequestOnAnAccountPageThatOnlyTheRouterKnowsIsRefused(): void
    {
        Idno::site()->routes()->addRoute('/account/controller-page-fixture', ControllerPageRegisteredFixture::class);
        $page = $this->page('/', 'GET', [], [], new SitePage(ControllerPageRegisteredFixture::class));
        Idno::site()->setCurrentPage($page);
        $session = Idno::site()->session();
        $session->authenticateStatelessly(new User());
        try {
            $this->assertTrue((new \Idno\Legacy\LegacyPageLifecycle())->refusesApiRequest($page->context()));
        } finally {
            $session->resetStatelessAuthentication();
        }
        $this->assertFalse((new \Idno\Legacy\LegacyPageLifecycle())->refusesApiRequest($page->context()), 'not an API request');
    }

    public function testDrawMarkdownUsesUpTheSessionsMessages(): void
    {
        $session = Idno::site()->session();
        $session->addMessage('A waiting message');
        $entity = new \IdnoPlugins\Status\Status();
        $entity->body = 'Hello';
        $page = $this->page('/x');
        Idno::site()->setCurrentPage($page);

        $page->drawMarkdown($entity);

        $this->assertSame([], $session->getMessages(), 'drawn, so used up, as drawPage() did');
    }

    public function testAnExitingForwardAfterOneThatWentOnReplacesIt(): void
    {
        $page = $this->page();
        $home = Idno::site()->config()->getDisplayURL();
        $page->forward($home . 'first', false);
        $this->assertSame($home . 'first', $page->context()->redirect()?->location);

        try {
            $page->forward($home . 'second');
            $this->fail('the page forwards');
        } catch (PageRedirect $redirect) {
            $this->assertSame($home . 'second', $redirect->location);
        }

        $this->assertNull($page->context()->redirect(), 'the first is forgotten');
    }

    public function testAnEmptyAcceptFallsBackToTheOneAWebServerPassedOn(): void
    {
        $page = $this->page('/x', 'GET', [], ['HTTP_ACCEPT' => '', 'REDIRECT_HTTP_ACCEPT' => 'application/json']);

        $this->assertTrue($page->isAcceptedContentType('application/json'));
        $this->assertFalse($page->isAcceptedContentType('text/html'));
    }

    public function testTheFlagOfTheAttributeStillRefusesWhenTheCurrentPageWasReplaced(): void
    {
        $page = $this->page('/', 'GET', [], [], new SitePage('Some\\Flagged\\Page', accountPage: true));
        Idno::site()->setCurrentPage(new BarePage());
        $session = Idno::site()->session();
        $session->authenticateStatelessly(new User());
        try {
            $this->assertTrue((new \Idno\Legacy\LegacyPageLifecycle())->refusesApiRequest($page->context()));
        } finally {
            $session->resetStatelessAuthentication();
        }
    }

}

final class ControllerPageRegisteredFixture extends \Idno\Common\Page
{
}

final class ControllerPageRegisteredAdminFixture extends \Idno\Common\Page
{
}

final class ControllerPagePublicFixture extends \Idno\Common\Page
{
}
