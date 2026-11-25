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
use Idno\Http\Page\PageError;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[SitePage('Fixture\ListenerPage')]
final class ListenerPageController
{
    public function page(): Response
    {
        return new Response('page');
    }
}

#[SitePage('Fixture\ListenerPostPage', methods: ['GET', 'POST'])]
final class ListenerPostController
{
    public function page(): Response
    {
        return new Response('posted');
    }
}

#[SitePage('Fixture\ErrorPage')]
final class ListenerErrorController
{
    public function page(Request $request, string $name = 'x'): Response
    {
        throw new \TypeError('Failed for ' . $name);
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

    /** @var array<string, \Throwable> a failure to throw from a method, by its name */
    public array $failures = [];

    public function begin(PageContext $context): void
    {
        $this->context = $context;
        $this->calls[] = 'begin';
        $this->failAt('begin');
    }

    private function failAt(string $method): void
    {
        if (isset($this->failures[$method])) {
            throw $this->failures[$method];
        }
    }

    public function refusesApiRequest(PageContext $context): bool
    {
        $this->calls[] = 'refusesApiRequest';
        $this->failAt('refusesApiRequest');

        return $this->refusesApi;
    }

    public function mayView(PageContext $context): bool
    {
        $this->calls[] = 'mayView';
        $this->failAt('mayView');

        return $this->mayView;
    }

    public function enter(PageContext $context): void
    {
        $this->calls[] = 'enter';
        $this->failAt('enter');
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

        $this->assertSame(['begin', 'refusesApiRequest'], $this->lifecycle->calls, 'no walled garden check, no page/head, no page/get');
        $response = ($event->getController())();
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame('GET, POST, HEAD, OPTIONS, PUT, DELETE', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertSame('86400', $response->headers->get('Access-Control-Max-Age'));
    }

    public function testAnApiRequestIsRefusedForOptionsToo(): void
    {
        $this->lifecycle->refusesApi = true;
        $event = $this->controllerEvent(Request::create('/', 'OPTIONS'), new ListenerPageController(), 'page');

        $this->expectException(AccessDeniedException::class);
        try {
            $this->listener()->onController($event);
        } finally {
            $this->assertSame(['begin', 'refusesApiRequest'], $this->lifecycle->calls);
        }
    }

    public function testAMethodThePageDidNotDeclareIsRefusedBeforeAnyEvent(): void
    {
        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $method) {
            $request = Request::create('/', $method);
            $event = $this->controllerEvent($request, new ListenerPageController(), 'page');

            try {
                $this->listener()->onController($event);
                $this->fail($method . ' is refused');
            } catch (MethodNotAllowedHttpException $refusal) {
                $this->assertSame([], $this->lifecycle->calls, $method . ': not even begun');
                $this->assertFalse(PageContext::has($request), $method);
                $this->assertStringContainsString('GET', (string) $refusal->getHeaders()['Allow']);
            }
        }
    }

    public function testADeclaredMethodIsLetThroughAndHeadFollowsGet(): void
    {
        $post = $this->controllerEvent(Request::create('/', 'POST'), new ListenerPostController(), 'page');
        $this->listener()->onController($post);
        $this->assertSame(['begin', 'refusesApiRequest', 'mayView', 'enter'], $this->lifecycle->calls);

        $this->lifecycle->calls = [];
        $head = $this->controllerEvent(Request::create('/', 'HEAD'), new ListenerPageController(), 'page');
        $this->listener()->onController($head);
        $this->assertSame(['begin', 'refusesApiRequest', 'mayView', 'enter'], $this->lifecycle->calls);

        $this->lifecycle->calls = [];
        $put = $this->controllerEvent(Request::create('/', 'PUT'), new ListenerPostController(), 'page');
        $this->expectException(MethodNotAllowedHttpException::class);
        $this->listener()->onController($put);
    }

    public function testARefusalThrowsBeforeEnter(): void
    {
        $this->lifecycle->mayView = false;
        $event = $this->controllerEvent(Request::create('/'), new ListenerPageController(), 'page');

        try {
            $this->listener()->onController($event);
            $this->fail('the page is refused');
        } catch (AccessDeniedHttpException) {
            // the page's own refusal (`deniedContent()`: PageExceptionListener draws the site's 403 page), not Security's
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
        } catch (AccessDeniedException) {
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

    public function testADefaultHeaderIsGivenOnlyToAResponseThatHasNone(): void
    {
        $listener = $this->listener();
        $request = Request::create('/');
        $listener->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        PageContext::of($request)->addDefaultHeader('X-Default', 'router');
        $bare = new Response('x');
        $own = new Response('x', 200, ['X-Default' => 'page']);

        foreach ([$bare, $own] as $response) {
            $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));
        }

        $this->assertSame('router', $bare->headers->get('X-Default'));
        $this->assertSame('page', $own->headers->get('X-Default'));
    }

    public function testCachingIsLeftToTheHeaderReconciler(): void
    {
        $listener = $this->listener();
        $request = Request::create('/');
        $listener->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        PageContext::of($request)->setCaching(['Cache-Control' => 'no-store, no-cache, must-revalidate', 'Pragma' => 'no-cache', 'Expires' => 'Thu, 19 Nov 1981 08:52:00 GMT']);
        $response = new Response('x', 200, ['Cache-Control' => 'public, max-age=60']);

        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        // the page's caching is sent line by line by Idno\Legacy\LegacyHeaderReconciler, never through the response's own computed Cache-Control
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'));
        $this->assertFalse($response->headers->has('Pragma'));
        $this->assertFalse($response->headers->has('Expires'));
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
        $this->assertSame('', $response->getContent());
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

    public function testAContinuedForwardIsBuiltByTheRuleOfTheRedirectBuilder(): void
    {
        $listener = $this->listener();
        $request = Request::create('/');
        $listener->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));

        // a status that PHP does not leave on a Location is a 302, as PageRedirect::toResponse() makes it
        PageContext::of($request)->setRedirect(new PageRedirect('https://example.com/later', null, 200));
        $plain = new Response('page');
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $plain));
        $this->assertSame(302, $plain->getStatusCode());

        // the JSON of an API request is JSON
        PageContext::of($request)->setRedirect(new PageRedirect('https://example.com/later', '{"a":1}'));
        $api = new Response('page', 200, ['Content-Type' => 'text/html']);
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $api));
        $this->assertSame('{"a":1}page', $api->getContent());
        $this->assertSame('application/json', $api->headers->get('Content-Type'));
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

    public function testAHeaderOnSuccessIsAddedToA200Only(): void
    {
        $listener = $this->listener();
        $request = Request::create('/');
        $listener->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        PageContext::of($request)->addHeaderOnSuccess('Link', '<https://example.com/a>; rel="canonical"');

        $ok = new Response('x');
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $ok));
        $missing = new Response('x', 404);
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $missing));

        $this->assertSame(['<https://example.com/a>; rel="canonical"'], $ok->headers->all('link'));
        $this->assertFalse($missing->headers->has('Link'));
    }

    public function testAResponseWithWorkAfterItTellsTheClientItIsComplete(): void
    {
        $listener = $this->listener();
        $request = Request::create('/');
        $listener->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        $plain = new Response('abc');
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $plain));
        $this->assertFalse($plain->headers->has('Connection'));

        PageContext::of($request)->afterResponse(static fn () => null);
        $response = new Response('abcdé');
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        $this->assertSame('close', $response->headers->get('Connection'));
        $this->assertSame('6', $response->headers->get('Content-Length'), 'bytes, not characters');
    }

    public function testTheLengthIncludesTheBodyOfAForwardTheApiRequestGetsAndWentOnFrom(): void
    {
        $listener = $this->listener();
        $request = Request::create('/');
        $listener->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        $context = PageContext::of($request);
        $context->afterResponse(static fn () => null);
        $context->setRedirect(new PageRedirect('https://example.com/next', '{"location":"x"}', 200));
        $response = new Response('abc');

        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        $this->assertSame('{"location":"x"}abc', $response->getContent());
        $this->assertSame((string) strlen('{"location":"x"}abc'), $response->headers->get('Content-Length'));
    }

    public function testALengthTheRendererSetIsWorkedOutAgainAfterAForwardsBody(): void
    {
        $listener = $this->listener();
        $request = Request::create('/');
        $listener->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        PageContext::of($request)->setRedirect(new PageRedirect('https://example.com/next', '{"location":"x"}', 200));
        $response = new Response('abc', 200, ['Content-Length' => '3']);

        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        $this->assertSame((string) strlen('{"location":"x"}abc'), $response->headers->get('Content-Length'));
        $this->assertFalse($response->headers->has('Connection'), 'no work follows the response');
    }

    public function testTheForwardThatEndsThePageReplacesOneItWentOnFrom(): void
    {
        $listener = $this->listener();
        $request = Request::create('/');
        $listener->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        PageContext::of($request)->setRedirect(new PageRedirect('https://example.com/first'));
        $event = $this->exceptionEvent($request, new PageRedirect('https://example.com/second'));

        $listener->onException($event);
        $response = $event->getResponse();
        $listener->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        $this->assertSame('https://example.com/second', $response->headers->get('Location'));
    }

    public function testAnApiRequestsAnswerMayBeA200(): void
    {
        $event = $this->exceptionEvent(Request::create('/'), new PageRedirect('https://example.com/next', '{}', 200));

        $this->listener()->onException($event);

        $this->assertTrue($event->isAllowingCustomResponseCode());
    }

    private function argumentsEvent(Request $request, object $controller, array $arguments, int $type = HttpKernelInterface::MAIN_REQUEST): ControllerArgumentsEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new ControllerArgumentsEvent($kernel, new ControllerEvent($kernel, [$controller, 'page'], $request, $type), $arguments, $request, $type);
    }

    public function testAnErrorOfASitePageControllerIsTurnedIntoAnExceptionThatCarriesIt(): void
    {
        $request = Request::create('/');
        $this->listener()->onController($this->controllerEvent($request, new ListenerErrorController(), 'page'));
        $event = $this->argumentsEvent($request, new ListenerErrorController(), [$request, 'ada']);

        $this->listener()->onControllerArguments($event);

        try {
            ($event->getController())(...$event->getArguments());
            $this->fail('the error is thrown');
        } catch (PageError $e) {
            $this->assertInstanceOf(\TypeError::class, $e->getPrevious());
            $this->assertSame('Failed for ada', $e->getPrevious()->getMessage(), 'the arguments reach the controller');
            $this->assertSame($e->getPrevious(), $e->error);
        }
    }

    public function testAnExceptionOfASitePageControllerIsNotWrapped(): void
    {
        $controller = new class {
            public function page(): Response
            {
                throw new \RuntimeException('Plain');
            }
        };
        $request = Request::create('/');
        $this->listener()->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        $event = $this->argumentsEvent($request, $controller, []);

        $this->listener()->onControllerArguments($event);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Plain');
        ($event->getController())(...$event->getArguments());
    }

    public function testOtherControllersAreNotWrappedAndNothingIsFetched(): void
    {
        $plain = Request::create('/');
        $event = $this->argumentsEvent($plain, new ListenerErrorController(), [$plain]);
        $original = $event->getController();

        $this->listener()->onControllerArguments($event);
        $this->assertSame($original, $event->getController(), 'no page context: left alone');

        // a site page's request, but the legacy application is not booted, or a sub-request
        $request = Request::create('/');
        $this->listener()->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        foreach ([[false, HttpKernelInterface::MAIN_REQUEST], [true, HttpKernelInterface::SUB_REQUEST]] as [$booted, $type]) {
            $event = $this->argumentsEvent($request, new ListenerErrorController(), [$request], $type);
            $original = $event->getController();

            $this->listener(legacyBooted: $booted)->onControllerArguments($event);

            $this->assertSame($original, $event->getController());
        }
    }

    public function testTheControllersAttributesSurviveTheWrapper(): void
    {
        $request = Request::create('/');
        $this->listener()->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        $event = $this->argumentsEvent($request, new ListenerErrorController(), [$request]);
        $before = $event->getAttributes('*');
        $this->assertNotSame([], $before, 'the controller has attributes to begin with (its #[SitePage])');

        $this->listener()->onControllerArguments($event);

        $this->assertEquals($before, $event->getAttributes('*'), 'the wrapper keeps the controller\'s attributes for the phases after it');
        $this->assertNotNull($request->attributes->get('_controller_attributes'), 'and the request still carries them, which the response phase reads');
    }

    public function testCacheIsTheOneAttributeASitePageDoesNotKeep(): void
    {
        $request = Request::create('/');
        $this->listener()->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
        $event = $this->argumentsEvent($request, new ListenerErrorController(), [$request]);
        $keep = new SitePage('Fixture\Kept');
        $request->attributes->set('_controller_attributes', [$keep, new \Symfony\Component\HttpKernel\Attribute\Cache(maxage: 60)]);

        $this->listener()->onControllerArguments($event);

        $this->assertSame([$keep], $request->attributes->get('_controller_attributes'));
    }

    public function testAnErrorOfAListenerWhileThePageIsBegunOrEnteredIsTurnedIntoAnExceptionThatCarriesIt(): void
    {
        foreach (['begin', 'enter'] as $method) {
            $this->lifecycle->failures = [$method => new \TypeError('A plugin\'s listener failed')];
            $request = Request::create('/');

            try {
                $this->listener()->onController($this->controllerEvent($request, new ListenerPageController(), 'page'));
                $this->fail($method . ': the error is thrown');
            } catch (PageError $e) {
                $this->assertSame($this->lifecycle->failures[$method], $e->error, $method);
                $this->assertTrue(PageContext::has($request), $method . ': the page has its context, which the exception listener needs');
            }
        }
    }

    public function testAnExceptionOfAListenerWhileThePageIsBegunIsNotWrapped(): void
    {
        $this->lifecycle->failures['begin'] = new \RuntimeException('Plain');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Plain');

        $this->listener()->onController($this->controllerEvent(Request::create('/'), new ListenerPageController(), 'page'));
    }
}
