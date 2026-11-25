<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Page;

use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageLifecycle;
use Idno\Http\Page\PageRedirect;
use Idno\Http\Page\PageRequestListener;
use Idno\Http\Page\SitePage;
use Idno\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[SitePage('Fixture\ListenerPage')]
final class ListenerPageController
{
    public function page(): Response
    {
        return new Response('page');
    }
}

final class ListenerPlainController
{
    public function plain(): Response
    {
        return new Response('plain');
    }
}

/**
 * A lifecycle that records what it is asked, in order, and answers what the test says.
 */
final class RecordingLifecycle implements PageLifecycle
{
    /** @var list<string> */
    public array $calls = [];

    public bool $refusesApi = false;

    public bool $mayView = true;

    public ?PageContext $context = null;

    public function begin(PageContext $context): void
    {
        $this->context = $context;
        $this->calls[] = 'begin';
    }

    public function refusesApiRequest(PageContext $context): bool
    {
        $this->calls[] = 'refusesApiRequest';

        return $this->refusesApi;
    }

    public function mayView(PageContext $context): bool
    {
        $this->calls[] = 'mayView';

        return $this->mayView;
    }

    public function enter(PageContext $context): void
    {
        $this->calls[] = 'enter';
    }

    public function standardHeaders(): array
    {
        return ['X-Standard-One' => 'one', 'X-Standard-Two' => 'two'];
    }

    public function reportException(\Throwable $e): void
    {
        $this->calls[] = 'reportException';
    }
}

/**
 * The listener that runs the page lifecycle around a controller marked as a site page, with a lifecycle that records
 * and a kernel that says whether the legacy application is booted, without booting anything.
 */
final class PageRequestListenerTest extends TestCase
{
    private RecordingLifecycle $lifecycle;

    private int $fetched = 0;

    protected function setUp(): void
    {
        $this->lifecycle = new RecordingLifecycle();
        $this->fetched = 0;
    }

    private function listener(bool $legacyBooted = true): PageRequestListener
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

        return new PageRequestListener($kernel, function (): PageLifecycle {
            ++$this->fetched;

            return $this->lifecycle;
        });
    }

    private function controllerEvent(Request $request, object $controller, string $method, int $type = HttpKernelInterface::MAIN_REQUEST): ControllerEvent
    {
        return new ControllerEvent($this->createMock(HttpKernelInterface::class), [$controller, $method], $request, $type);
    }

    public function testAPlainControllerIsLeftAloneAndTheLifecycleIsNotFetched(): void
    {
        $request = Request::create('/');
        $event = $this->controllerEvent($request, new ListenerPlainController(), 'plain');

        $this->listener()->onController($event);

        $this->assertSame(0, $this->fetched);
        $this->assertSame([], $this->lifecycle->calls);
        $this->assertFalse(PageContext::has($request));
        $this->assertSame('plain', ($event->getController())()->getContent());
    }

    public function testNothingHappensWithoutTheLegacyApplication(): void
    {
        $request = Request::create('/');
        $event = $this->controllerEvent($request, new ListenerPageController(), 'page');
        $listener = $this->listener(legacyBooted: false);

        $listener->onController($event);
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new Response()));

        $this->assertSame(0, $this->fetched);
        $this->assertFalse(PageContext::has($request));
    }

    public function testASitePageBeginsThenChecksThenEnters(): void
    {
        $request = Request::create('/');
        $event = $this->controllerEvent($request, new ListenerPageController(), 'page');

        $this->listener()->onController($event);

        $this->assertSame(['begin', 'refusesApiRequest', 'mayView', 'enter'], $this->lifecycle->calls);
        $this->assertTrue(PageContext::has($request));
        $this->assertSame($this->lifecycle->context, PageContext::of($request));
        $this->assertSame('Fixture\ListenerPage', PageContext::of($request)->page()->name);
        $this->assertSame('page', ($event->getController())()->getContent(), 'the controller is the page\'s own');
    }

    public function testOptionsBeginsThenAnswers204WithoutEntering(): void
    {
        $request = Request::create('/', 'OPTIONS');
        $event = $this->controllerEvent($request, new ListenerPageController(), 'page');

        $this->listener()->onController($event);

        $this->assertSame(['begin'], $this->lifecycle->calls, 'no check, no page/head, no page/get');
        $response = ($event->getController())();
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame('GET, POST, HEAD, OPTIONS, PUT, DELETE', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertSame('86400', $response->headers->get('Access-Control-Max-Age'));
    }

    public function testARefusalThrowsBeforeEnter(): void
    {
        $this->lifecycle->mayView = false;
        $event = $this->controllerEvent(Request::create('/'), new ListenerPageController(), 'page');

        try {
            $this->listener()->onController($event);
            $this->fail('the page is refused');
        } catch (AccessDeniedHttpException) {
            $this->assertSame(['begin', 'refusesApiRequest', 'mayView'], $this->lifecycle->calls);
        }
    }

    public function testAnApiRefusalOnAnAccountPageThrowsBeforeEnter(): void
    {
        $this->lifecycle->refusesApi = true;
        $event = $this->controllerEvent(Request::create('/'), new ListenerPageController(), 'page');

        try {
            $this->listener()->onController($event);
            $this->fail('the request is refused');
        } catch (AccessDeniedHttpException) {
            $this->assertSame(['begin', 'refusesApiRequest'], $this->lifecycle->calls, 'the walled garden is not asked: the API refusal comes first');
        }
    }

    public function testStandardHeadersAreAddedToSitePageResponsesOnly(): void
    {
        $listener = $this->listener();
        $site = Request::create('/');
        $listener->onController($this->controllerEvent($site, new ListenerPageController(), 'page'));
        $response = new Response('x');
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $site, HttpKernelInterface::MAIN_REQUEST, $response));

        $this->assertSame('one', $response->headers->get('X-Standard-One'));
        $this->assertSame('two', $response->headers->get('X-Standard-Two'));

        $other = new Response('y');
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), HttpKernelInterface::MAIN_REQUEST, $other));
        $this->assertFalse($other->headers->has('X-Standard-One'));
    }

    public function testTheContextsHeadersReachTheResponseAndOnlyAReplacingOneReplaces(): void
    {
        $listener = $this->listener();
        $request = Request::create('/');
        $listener->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        $context = PageContext::of($request);
        $context->addHeader('X-Replaced', 'page');
        $context->addHeader('X-Added', 'page', false);
        $context->addHeader('Link', '<https://example.com/a>; rel="a"', false);
        $context->addHeader('Link', '<https://example.com/b>; rel="b"', false);
        $response = new Response('x', 200, ['X-Replaced' => 'controller', 'X-Added' => 'controller', 'Link' => '<https://example.com/c>; rel="c"']);

        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        $this->assertSame(['page'], $response->headers->all('x-replaced'));
        $this->assertSame(['controller', 'page'], $response->headers->all('x-added'));
        $this->assertSame(['<https://example.com/c>; rel="c"', '<https://example.com/a>; rel="a"', '<https://example.com/b>; rel="b"'], $response->headers->all('link'));
    }

    public function testCachingFromTheContextReachesTheResponse(): void
    {
        $listener = $this->listener();
        $request = Request::create('/');
        $listener->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        $untouched = new Response('x', 200, ['Cache-Control' => 'public, max-age=60']);
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $untouched));
        $this->assertTrue($untouched->headers->hasCacheControlDirective('public'), 'a page that asked for nothing leaves the controller\'s caching');

        PageContext::of($request)->setCaching(['Cache-Control' => 'no-store, no-cache, must-revalidate', 'Pragma' => 'no-cache', 'Expires' => 'Thu, 19 Nov 1981 08:52:00 GMT']);
        $response = new Response('x', 200, ['Cache-Control' => 'public, max-age=60']);
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('must-revalidate'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('Thu, 19 Nov 1981 08:52:00 GMT', $response->headers->get('Expires'));
    }

    public function testSubRequestsAreLeftAlone(): void
    {
        $request = Request::create('/');
        $listener = $this->listener();

        $listener->onController($this->controllerEvent($request, new ListenerPageController(), 'page', HttpKernelInterface::SUB_REQUEST));
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::SUB_REQUEST, new Response()));

        $this->assertSame(0, $this->fetched);
        $this->assertFalse(PageContext::has($request));
    }

    private function exceptionEvent(Request $request, \Throwable $e, int $type = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        return new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, $type, $e);
    }

    public function testAPageRedirectBecomesARedirectResponse(): void
    {
        $event = $this->exceptionEvent(Request::create('/'), new PageRedirect('https://example.com/next'));

        $this->listener()->onException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://example.com/next', $response->headers->get('Location'));
        $this->assertSame(302, $response->getStatusCode());

        $keep = $this->exceptionEvent(Request::create('/'), new PageRedirect('https://example.com/post', null, 307));
        $this->listener()->onException($keep);
        $this->assertSame(307, $keep->getResponse()->getStatusCode());
    }

    public function testAnApiRequestGetsTheBodyInsteadOfARedirect(): void
    {
        $event = $this->exceptionEvent(Request::create('/'), new PageRedirect('https://example.com/next', '{"location":"https:\/\/example.com\/next"}', 200));

        $this->listener()->onException($event);

        $this->assertSame(200, $event->getResponse()->getStatusCode());
        $this->assertFalse($event->getResponse()->headers->has('Location'));
        $this->assertSame('{"location":"https:\/\/example.com\/next"}', $event->getResponse()->getContent());
    }

    public function testOtherExceptionsAndSubRequestsAreLeftAlone(): void
    {
        $other = $this->exceptionEvent(Request::create('/'), new \RuntimeException('x'));
        $this->listener()->onException($other);
        $this->assertNull($other->getResponse());

        $sub = $this->exceptionEvent(Request::create('/'), new PageRedirect('/x'), HttpKernelInterface::SUB_REQUEST);
        $this->listener()->onException($sub);
        $this->assertNull($sub->getResponse());
    }

    public function testARecordedForwardIsCarriedByTheResponseThePageMade(): void
    {
        $listener = $this->listener();
        $request = Request::create('/');
        $listener->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        PageContext::of($request)->setRedirect(new PageRedirect('https://example.com/later'));
        $response = new Response('page');

        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        $this->assertSame('https://example.com/later', $response->headers->get('Location'));
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('page', $response->getContent());

        $error = new Response('no', 404);
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $error));
        $this->assertSame(404, $error->getStatusCode(), 'only a 200 becomes the redirect');

        PageContext::of($request)->setRedirect(new PageRedirect('https://example.com/later', '{"a":1}'));
        $api = new Response('page');
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $api));
        $this->assertSame('{"a":1}page', $api->getContent());
        $this->assertFalse($api->headers->has('Location'));
    }

    public function testTheWorkAfterTheResponseRunsOnTerminateAndAFailureIsReported(): void
    {
        $listener = $this->listener();
        $request = Request::create('/');
        $listener->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        $ran = [];
        PageContext::of($request)->afterResponse(function () use (&$ran): void {
            $ran[] = 'first';
            throw new \RuntimeException('the first fails');
        });
        PageContext::of($request)->afterResponse(function () use (&$ran): void {
            $ran[] = 'second';
        });
        $this->lifecycle->calls = [];

        $listener->onTerminate(new TerminateEvent($this->createMock(HttpKernelInterface::class), $request, new Response()));

        $this->assertSame(['first', 'second'], $ran);
        $this->assertSame(['reportException'], $this->lifecycle->calls);
    }

    public function testNothingRunsOnTerminateWithoutWork(): void
    {
        $listener = $this->listener();
        $request = Request::create('/');
        $listener->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        $this->lifecycle->calls = [];

        $listener->onTerminate(new TerminateEvent($this->createMock(HttpKernelInterface::class), $request, new Response()));
        $listener->onTerminate(new TerminateEvent($this->createMock(HttpKernelInterface::class), Request::create('/'), new Response()));

        $this->assertSame([], $this->lifecycle->calls);
    }
}
