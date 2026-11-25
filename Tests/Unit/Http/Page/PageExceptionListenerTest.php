<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Page;

use Idno\Http\Page\DrawnPage;
use Idno\Http\Page\PageContext;
use Idno\Http\Page\PageDrawer;
use Idno\Http\Page\PageError;
use Idno\Http\Page\PageExceptionListener;
use Idno\Http\Page\PageLifecycle;
use Idno\Http\Page\PageRedirect;
use Idno\Http\Page\PageRenderer;
use Idno\Http\Page\SitePage;
use Idno\Kernel;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;

/**
 * A drawer that writes what it draws to a log the lifecycle double shares, and fails on demand: no template is drawn.
 */
final class ExceptionTestDrawer implements PageDrawer
{
    public bool $failing = false;

    /** Fails with an \Error instead of an exception */
    public bool $failingWithAnError = false;

    /** @var list<int> */
    public array $statuses = [];

    /** @var list<string> */
    public array $templates = [];

    public function __construct(private readonly \ArrayObject $calls)
    {
    }

    public function templateType(): string
    {
        return 'default';
    }

    public function isApiRequest(): bool
    {
        return false;
    }

    public function finishSession(): void
    {
    }

    public function forwardTrace(?array $frames): ?string
    {
        return null;
    }

    public function displayUrl(): string
    {
        return 'https://example.com/';
    }

    public function urlWithVar(string $name, string $value, string $url): string
    {
        return $url;
    }

    public function drawTemplate(string $name, array $vars): string
    {
        $this->calls[] = 'draw ' . $name;
        $this->templates[] = $name;
        if ($this->failingWithAnError) {
            throw new \TypeError('the template has a type error');
        }
        if ($this->failing) {
            throw new \LogicException('the template cannot be drawn');
        }

        return '<' . $name . '>';
    }

    public function drawPage(array $vars, int $status, string $shell = 'shell'): DrawnPage
    {
        $this->calls[] = 'page ' . $status;
        $this->statuses[] = $status;

        return new DrawnPage('page:' . ($vars['title'] ?? '') . ':' . ($vars['body'] ?? ''), [['Content-Type', 'text/html']]);
    }
}

/**
 * A lifecycle that records the exceptions it is asked to report, in the log the drawer writes to.
 */
final class ExceptionTestLifecycle implements PageLifecycle
{
    /** @var list<\Throwable> */
    public array $reported = [];

    public function __construct(private readonly \ArrayObject $calls)
    {
    }

    public function begin(PageContext $context): void
    {
    }

    public function refusesApiRequest(PageContext $context): bool
    {
        return false;
    }

    public function mayView(PageContext $context): bool
    {
        return true;
    }

    public function enter(PageContext $context): void
    {
    }

    public function standardHeaders(): array
    {
        return [];
    }

    public function reportException(\Throwable $e): void
    {
        $this->calls[] = 'report ' . $e->getMessage();
        $this->reported[] = $e;
    }
}

/**
 * What answers an exception a site page threw, with a real PageRenderer over a drawer that records and a kernel that says
 * whether the legacy application is booted, without booting anything: each exception its page and status, what is reported
 * and what is not, and what is left to Symfony and to the firewall.
 */
final class PageExceptionListenerTest extends TestCase
{
    private \ArrayObject $calls;

    private ExceptionTestDrawer $drawer;

    private ExceptionTestLifecycle $lifecycle;

    private int $rendererFetched = 0;

    private int $lifecycleFetched = 0;

    /** @var \Psr\Log\AbstractLogger&object{lines: list<string>} */
    private \Psr\Log\AbstractLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ': ' . $message;
            }
        };
        $this->calls = new \ArrayObject();
        $this->drawer = new ExceptionTestDrawer($this->calls);
        $this->lifecycle = new ExceptionTestLifecycle($this->calls);
        $this->rendererFetched = 0;
        $this->lifecycleFetched = 0;
    }

    private function listener(bool $legacyBooted = true): PageExceptionListener
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
        $translation = new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return 'T(' . $text . ')';
            }
        };
        $renderer = new PageRenderer($this->drawer, $translation);

        return new PageExceptionListener(
            $kernel,
            function () use ($renderer): PageRenderer {
                ++$this->rendererFetched;

                return $renderer;
            },
            function (): PageLifecycle {
                ++$this->lifecycleFetched;

                return $this->lifecycle;
            },
            function (): \Psr\Log\LoggerInterface {
                return $this->logger;
            }
        );
    }

    private function event(\Throwable $e, bool $sitePage = true, int $type = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        $request = Request::create('/x');
        if ($sitePage) {
            (new PageContext($request, new SitePage('Fixture\Page')))->attachTo($request);
        }

        return new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, $type, $e);
    }

    public function testANotFoundIsTheSites404(): void
    {
        $event = $this->event(new NotFoundHttpException());

        $this->listener()->onException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame("page:T(This page can't be found.):<pages/404>", $response->getContent());
        $this->assertSame(404, \Idno\Http\Page\PageContext::of($event->getRequest())->status());
        $this->assertTrue($event->isAllowingCustomResponseCode(), 'the kernel keeps the status the page drew');
    }

    public function testAGoneIsTheSites410(): void
    {
        $event = $this->event(new GoneHttpException());

        $this->listener()->onException($event);

        $this->assertSame(410, $event->getResponse()?->getStatusCode());
        $this->assertSame("page:T(This content isn't here.):<pages/410>", $event->getResponse()->getContent());
    }

    public function testADeniedIsTheSites403TitledByItsMessage(): void
    {
        $event = $this->event(new AccessDeniedHttpException('No entry'));

        $this->listener()->onException($event);

        $this->assertSame(403, $event->getResponse()?->getStatusCode());
        $this->assertSame('page:No entry:<pages/403>', $event->getResponse()->getContent());
    }

    public function testAnyOtherExceptionIsReportedAndDrawnAs500(): void
    {
        $exception = new \RuntimeException('Broken');
        $event = $this->event($exception);

        $this->listener()->onException($event);

        $this->assertSame(500, $event->getResponse()?->getStatusCode());
        $this->assertSame('page:Exception:<pages/500>', $event->getResponse()->getContent());
        $this->assertSame([$exception], $this->lifecycle->reported);
        $this->assertTrue($event->isAllowingCustomResponseCode());
    }

    public function testTheReportComesBeforeTheErrorPage(): void
    {
        $this->listener()->onException($this->event(new \RuntimeException('Broken')));

        $this->assertSame(['report Broken', 'draw pages/500', 'page 500'], $this->calls->getArrayCopy());
    }

    public function testHttpExceptionsAreNotReported(): void
    {
        foreach ([new NotFoundHttpException(), new GoneHttpException(), new AccessDeniedHttpException()] as $e) {
            $this->listener()->onException($this->event($e));
        }

        $this->assertSame([], $this->lifecycle->reported);
        $this->assertSame(0, $this->lifecycleFetched, 'the lifecycle is not even fetched');
    }

    public function testAnotherHttpExceptionIsLeftToSymfony(): void
    {
        $event = $this->event(new BadRequestHttpException('A bad request'));

        $this->listener()->onException($event);

        $this->assertNull($event->getResponse());
        $this->assertSame([], $this->lifecycle->reported);
        $this->assertSame(0, $this->rendererFetched);
    }

    /**
     * A refusal of Symfony Security on a site page (`#[IsGranted]`, the lifecycle's refusal of an API request, a form token an attribute checked) is
     * drawn as every refusal of a site page is, the site's 403 page, and is not reported: the firewall's responder answers only other controllers.
     */
    public function testSecurityExceptionsAreTheSites403(): void
    {
        foreach ([new AccessDeniedException(), new AuthenticationException(), new InvalidCsrfTokenException()] as $e) {
            $event = $this->event($e);

            $this->listener()->onException($event);

            $this->assertSame(403, $event->getResponse()?->getStatusCode(), $e::class);
            $this->assertSame('page::<pages/403>', $event->getResponse()->getContent(), 'untitled, as deniedContent() with no title');
        }
        $this->assertSame([], $this->lifecycle->reported);
        $this->assertSame(0, $this->lifecycleFetched, 'nothing is reported');
        $this->assertSame([], $this->logger->lines);
    }

    public function testANonSitePageRequestIsLeftAloneAndNothingIsFetched(): void
    {
        foreach ([new \RuntimeException('Broken'), new NotFoundHttpException(), new PageRedirect('/x')] as $e) {
            $event = $this->event($e, sitePage: false);

            $this->listener()->onException($event);

            $this->assertNull($event->getResponse());
        }
        $this->assertSame(0, $this->rendererFetched);
        $this->assertSame(0, $this->lifecycleFetched);
    }

    public function testASubRequestIsLeftAlone(): void
    {
        $event = $this->event(new \RuntimeException('Broken'), type: HttpKernelInterface::SUB_REQUEST);

        $this->listener()->onException($event);

        $this->assertNull($event->getResponse());
        $this->assertSame(0, $this->rendererFetched);
    }

    public function testNothingHappensWithoutTheLegacyApplication(): void
    {
        $event = $this->event(new \RuntimeException('Broken'));

        $this->listener(legacyBooted: false)->onException($event);

        $this->assertNull($event->getResponse());
        $this->assertSame(0, $this->rendererFetched);
        $this->assertSame(0, $this->lifecycleFetched);
    }

    public function testADrawingFailureIsPlainText(): void
    {
        $this->drawer->failing = true;
        $event = $this->event(new \RuntimeException('Broken'));

        $this->listener()->onException($event);

        $response = $event->getResponse();
        $this->assertSame(500, $response?->getStatusCode());
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Internal Server Error', (string) $response->getContent());
        $this->assertStringNotContainsString('Broken', (string) $response->getContent(), 'nothing of the exception is shown');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(['Broken', 'the template cannot be drawn'], array_map(static fn (\Throwable $e): string => $e->getMessage(), $this->lifecycle->reported), 'the exception and the drawing failure, each once');
    }

    public function testAnErrorPageThatCannotBeDrawnKeepsItsStatus(): void
    {
        $this->drawer->failing = true;
        foreach ([[new NotFoundHttpException(), 404, 'Not Found'], [new GoneHttpException(), 410, 'Gone'], [new AccessDeniedHttpException(), 403, 'Forbidden']] as [$e, $status, $text]) {
            $event = $this->event($e);

            $this->listener()->onException($event);

            $this->assertSame($status, $event->getResponse()?->getStatusCode());
            $this->assertStringContainsString($text, (string) $event->getResponse()->getContent());
            $this->assertStringStartsWith('text/plain', (string) $event->getResponse()->headers->get('Content-Type'));
        }
        $this->assertCount(3, $this->lifecycle->reported, 'the drawing failure of each, once');
    }

    public function testAnErrorOfThePageIsReportedAndDrawnAsItsOwn(): void
    {
        $error = new \TypeError('A type error');
        $event = $this->event(new PageError($error));

        $this->listener()->onException($event);

        $this->assertSame(500, $event->getResponse()?->getStatusCode());
        $this->assertSame('page:Exception:<pages/500>', $event->getResponse()->getContent());
        $this->assertSame([$error], $this->lifecycle->reported, 'the original error is what is reported, not the wrapper');
        $this->assertSame(['report A type error', 'draw pages/500', 'page 500'], $this->calls->getArrayCopy());
        $this->assertTrue($event->isAllowingCustomResponseCode());
    }

    public function testAnErrorOfThePageThatCannotBeDrawnIsPlainTextAndTheDrawingFailureIsReported(): void
    {
        $this->drawer->failing = true;
        $error = new \TypeError('A type error');
        $event = $this->event(new PageError($error));

        $this->listener()->onException($event);

        $this->assertSame(500, $event->getResponse()?->getStatusCode());
        $this->assertSame("Internal Server Error\n", $event->getResponse()->getContent());
        $this->assertSame(['A type error', 'the template cannot be drawn'], array_map(static fn (\Throwable $e): string => $e->getMessage(), $this->lifecycle->reported));
    }

    public function testAServerErrorHttpExceptionIsReportedAndDrawnWithItsStatus(): void
    {
        $exception = new ServiceUnavailableHttpException(null, 'Down');
        $event = $this->event($exception);

        $this->listener()->onException($event);

        $this->assertSame(503, $event->getResponse()?->getStatusCode());
        $this->assertSame('page:Exception:<pages/500>', $event->getResponse()->getContent());
        $this->assertSame([503], $this->drawer->statuses);
        $this->assertSame([$exception], $this->lifecycle->reported);
        $this->assertSame(['report Down', 'draw pages/500', 'page 503'], $this->calls->getArrayCopy());
    }

    public function testAServerErrorsOwnHeadersAreCarriedOntoThePage(): void
    {
        $event = $this->event(new ServiceUnavailableHttpException(120, 'Down'));

        $this->listener()->onException($event);

        $this->assertSame(503, $event->getResponse()?->getStatusCode());
        $this->assertSame('120', $event->getResponse()->headers->get('Retry-After'));
    }

    public function testAServerErrorThatCannotBeDrawnKeepsItsHeadersToo(): void
    {
        $this->drawer->failing = true;
        $event = $this->event(new ServiceUnavailableHttpException(120, 'Down'));

        $this->listener()->onException($event);

        $this->assertSame('120', $event->getResponse()?->headers->get('Retry-After'));
    }

    public function testAServerErrorThatCannotBeDrawnKeepsItsStatusAsPlainText(): void
    {
        $this->drawer->failing = true;
        $event = $this->event(new ServiceUnavailableHttpException(null, 'Down'));

        $this->listener()->onException($event);

        $this->assertSame(503, $event->getResponse()?->getStatusCode());
        $this->assertStringContainsString('Service Unavailable', (string) $event->getResponse()->getContent());
    }

    public function testASecurityExceptionWrappedInAnotherIsStillARefusal(): void
    {
        foreach ([new \RuntimeException('Wrapped', 0, new AccessDeniedException()), new \LogicException('Wrapped twice', 0, new \RuntimeException('Inner', 0, new AuthenticationException()))] as $e) {
            $event = $this->event($e);

            $this->listener()->onException($event);

            $this->assertSame(403, $event->getResponse()?->getStatusCode());
        }
        $this->assertSame([], $this->lifecycle->reported);
        // Each is logged once, as a warning that names the wrapping class and nothing of its message.
        $this->assertSame(['warning: A site page\'s exception wraps a refusal, which is answered as the refusal: RuntimeException', 'warning: A site page\'s exception wraps a refusal, which is answered as the refusal: LogicException'], $this->logger->lines);
    }

    public function testAnUnwrappedSecurityExceptionIsNotLogged(): void
    {
        $this->listener()->onException($this->event(new AccessDeniedException()));

        $this->assertSame([], $this->logger->lines);
    }

    public function testAnErrorWhileDrawingTheErrorPageIsPlainTextToo(): void
    {
        $this->drawer->failingWithAnError = true;
        $event = $this->event(new PageError(new \TypeError('The first')));

        $this->listener()->onException($event);

        $this->assertSame("Internal Server Error\n", $event->getResponse()?->getContent());
        $this->assertSame(['The first', 'the template has a type error'], array_map(static fn (\Throwable $e): string => $e->getMessage(), $this->lifecycle->reported));
    }
}
