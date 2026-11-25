<?php

declare(strict_types=1);

namespace Idno\Http\Page;

use Idno\Kernel;
use Idno\Security\DeniedResponder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Answers what a site page (a controller marked #[SitePage]) throws with the site's own pages, as a legacy page answered
 * the same things: `noContent()` (a NotFoundHttpException) is the 404 page, `goneContent()` (GoneHttpException) the 410
 * page, `deniedContent()` (AccessDeniedHttpException, its message the page's title) the 403 page, each in the request's
 * template type and logged as nothing; and any other exception is what `Page::exception()` did: reported (the critical line,
 * the statistic, the alert to the administrator: PageLifecycle::reportException()) and answered with the 500 page.
 *
 * It runs at priority 2: above Symfony's ErrorListener (0), which logs every exception it sees, so a page's exception is never
 * logged twice (and an ordinary 404 never as an error), and above the firewall's listener (1), which answers the Security
 * exceptions this one leaves alone (a refusal of the lifecycle, a failed form token: DeniedResponder). It does nothing for a
 * request that is not a site page, in a kernel that did not boot the legacy application, or for an HTTP exception below 500 that is
 * not one of those (a bad request, a method not allowed), which stay Symfony's; a server error (5xx) HTTP exception is a failure of the
 * page, reported and drawn as the 500 page at its own status. A redirect is made into its response by PageRequestListener, which runs
 * first; it is answered here too, should this listener ever see one.
 *
 * Only an \Exception reaches it: `framework.handle_all_throwables` is off, so Symfony dispatches nothing for an \Error. PageRequestListener
 * wraps the controller of a site page so that an \Error it throws arrives here as a PageError, and is answered like any exception.
 *
 * Drawing a page can fail (an exception in a template, in a plugin's extension of the shell): the answer is then a plain-text
 * response of the same status, and the failure of the drawing is reported, once. It never draws another error page.
 */
final class PageExceptionListener
{
    /**
     * @param \Closure(): PageRenderer  $renderer
     * @param \Closure(): PageLifecycle $lifecycle
     */
    public function __construct(
        #[Autowire(service: 'kernel')]
        private readonly Kernel $kernel,
        #[AutowireServiceClosure(PageRenderer::class)]
        private readonly \Closure $renderer,
        #[AutowireServiceClosure(PageLifecycle::class)]
        private readonly \Closure $lifecycle,
    )
    {
    }

    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 2)]
    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->kernel->legacyBooted() || !PageContext::has($request)) {
            return;
        }
        $e = $event->getThrowable();
        // A Security exception anywhere in the chain is a refusal, whatever wrapped it: the firewall's listener reads the chain the same way.
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof AccessDeniedException || $cause instanceof AuthenticationException) {
                return;
            }
        }
        // The \Error of the page's controller, carried here by PageRequestListener: what is reported and drawn is the error itself.
        if ($e instanceof PageError) {
            $e = $e->error;
        }
        $failure = 500;
        if ($e instanceof HttpExceptionInterface && !$e instanceof NotFoundHttpException && !$e instanceof GoneHttpException && !$e instanceof AccessDeniedHttpException) {
            // A server error a page chose to throw is a failure of the page, as any exception was; a bad request and the other client errors are Symfony's.
            if ($e->getStatusCode() < 500) {
                return;
            }
            $failure = $e->getStatusCode();
        }

        $context = PageContext::of($request);
        [$status, $draw] = match (true) {
            $e instanceof PageRedirect => [0, fn (PageRenderer $renderer): Response => $renderer->redirectFor($e)],
            $e instanceof NotFoundHttpException => [404, fn (PageRenderer $renderer): Response => $renderer->notFound($context)],
            $e instanceof GoneHttpException => [410, fn (PageRenderer $renderer): Response => $renderer->gone($context)],
            $e instanceof AccessDeniedHttpException => [403, fn (PageRenderer $renderer): Response => $renderer->denied($context, $e->getMessage())],
            default => [$failure, fn (PageRenderer $renderer): Response => $renderer->error($context, $e, $failure)],
        };
        if ($status >= 500) {
            // Before the page, as a legacy page logged first: a failure of the page itself is then a second line, never the only one.
            ($this->lifecycle)()->reportException($e);
        }

        try {
            $response = $draw(($this->renderer)());
        } catch (\Throwable $drawing) {
            ($this->lifecycle)()->reportException($drawing);
            $response = self::plain($request, $status);
        }

        // What a server error HTTP exception asks to be sent with it (`Retry-After` of a 503) goes with the page, as Symfony's own answer would send it
        if ($status >= 500 && $e instanceof HttpExceptionInterface) {
            foreach ($e->getHeaders() as $name => $value) {
                $response->headers->set($name, is_array($value) ? array_map('strval', $value) : (string) $value);
            }
        }

        // The status is the page's: without this the kernel makes a 500 of any other answer to an exception that is not an HTTP one
        $event->allowCustomResponseCode();
        $event->setResponse($response);
    }

    /**
     * What is answered when the site's page cannot be drawn: the status's own words, as plain text, never cached. Its `Cache-Control` is
     * the site's own and not a page's ask for caching, so the request is marked as a refusal's is (`DeniedResponder::ATTRIBUTE`): the header
     * reconciler takes it off like any response's and sends the session's no-store lines, and never refuses it.
     */
    private static function plain(Request $request, int $status): Response
    {
        $status = $status === 0 ? 500 : $status;
        $request->attributes->set(DeniedResponder::ATTRIBUTE, true);

        return new Response((Response::$statusTexts[$status] ?? 'Error') . "\n", $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
