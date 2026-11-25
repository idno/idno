<?php

declare(strict_types=1);

namespace Idno\Http\Page;

use Idno\Kernel;
use Idno\Markdown\MarkdownRequest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Runs the page lifecycle for a controller marked as a site page (#[SitePage]): before the controller, what a legacy page did
 * before its handler (PageLifecycle: begin, the API and walled garden checks, enter), and on its response, the headers every
 * page sends and the ones the page asked for.
 *
 * The lifecycle needs the legacy application, so the listener takes it as a service closure and does nothing unless the
 * kernel booted the legacy application and the controller is a site page: every other request (the legacy catch-all, an
 * upgrade step, a fixture route, a kernel with no legacy application) never builds it.
 */
final class PageRequestListener
{
    /**
     * @param \Closure(): PageLifecycle $lifecycle
     */
    public function __construct(
        #[Autowire(service: 'kernel')]
        private readonly Kernel $kernel,
        #[AutowireServiceClosure(PageLifecycle::class)]
        private readonly \Closure $lifecycle,
    )
    {
    }

    /**
     * Before `kernel.controller_arguments`, where Symfony Security's `#[IsGranted]` is checked (priority 20): a page is begun
     * before the security attributes of its controller are asked, as a legacy page was begun before its gatekeepers.
     */
    #[AsEventListener(event: KernelEvents::CONTROLLER, priority: 0)]
    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->kernel->legacyBooted()) {
            return;
        }
        $page = SitePage::of($event->getController());
        if ($page === null) {
            return;
        }
        // Caching is the page's own (`PageContext::setCaching()`, `ControllerPage::setNoCache()`), sent line by line in place of the session's.
        // Symfony's `#[Cache]` is not applied to a site page (the controller wrapper below is not given it, so its listener never runs),
        // so it is refused where a developer sees it: with debug on.
        if ($this->kernel->isDebug() && $event->getAttributes(Cache::class) !== []) {
            throw new \LogicException('#[Cache] is not applied to a site page: ask for caching with PageContext::setCaching() (or setNoCache()).');
        }
        $request = $event->getRequest();
        // A method the page did not declare never reaches it with another method's semantics: nothing is begun, no event fires.
        if (!$page->allows($request->getMethod())) {
            throw new MethodNotAllowedHttpException($page->allowedMethods());
        }
        $lifecycle = ($this->lifecycle)();
        $markdown = $page->markdownPermalink && in_array($request->getMethod(), ['GET', 'HEAD'], true) && self::takeOffMarkdownSuffix($request);
        $context = new PageContext($request, $page);
        $context->attachTo($request);
        $context->setMarkdownSuffix($markdown);
        // An \Error from a listener of a plugin (the page's own events fire in begin() and enter()) is answered as one from the controller is:
        // Symfony dispatches no exception event for an \Error, so it is carried as a PageError.
        try {
            $lifecycle->begin($context);

            // The legacy router refused an API request on an account page for every method, OPTIONS included. A refusal is Symfony
            // Security's, so the firewall answers it (the site's 403 page, or JSON for a caller that wants it, never cached).
            if ($lifecycle->refusesApiRequest($context)) {
                throw new AccessDeniedException();
            }
            if ($request->getMethod() === 'OPTIONS') {
                // What Page::options() answers: the page was begun (its events fired), nothing more.
                $event->setController(static fn (): Response => new Response('', 204, [
                    'Access-Control-Allow-Methods' => 'GET, POST, HEAD, OPTIONS, PUT, DELETE',
                    'Access-Control-Max-Age' => '86400',
                ]));

                return;
            }
            // The walled garden: what `publicGatekeeper()` did was `deniedContent()`, the site's own 403 page in the request's template type
            // (PageExceptionListener draws it), so it is the page's refusal and not Security's.
            if (!$lifecycle->mayView($context)) {
                throw new AccessDeniedHttpException();
            }
            $lifecycle->enter($context);
        } catch (\Error $error) {
            throw new PageError($error);
        }
    }

    /**
     * The request for the Markdown form of a permalink: the last parameter the route matched (the post's slug, its id) ends in `.md`, as the
     * legacy pattern of a permalink took it. The suffix is taken off, so the controller reads the same parameter whichever form is asked.
     */
    private static function takeOffMarkdownSuffix(Request $request): bool
    {
        $parameters = $request->attributes->get('_route_params');
        if (!is_array($parameters)) {
            return false;
        }
        foreach (array_reverse(array_keys($parameters)) as $name) {
            $name = (string) $name;
            if ($name === 'lead' || $name === 'slash' || str_starts_with($name, '_')) {
                continue;
            }
            $value = $parameters[$name];
            $stripped = is_string($value) ? MarkdownRequest::pathWithoutSuffix($value) : null;
            if ($stripped === null) {
                return false;
            }
            $parameters[$name] = $stripped;
            $request->attributes->set('_route_params', $parameters);
            $request->attributes->set($name, $stripped);

            return true;
        }

        return false;
    }

    /**
     * An \Error the controller of a site page throws is turned into a PageError, which PageExceptionListener answers as it answers an
     * exception: Symfony dispatches no exception event for an \Error. This runs after Symfony's attribute listeners (#[IsGranted], at
     * priority -10000, which read the controller's own arguments), so the controller is replaced, with the attributes it had (the phases after this one, the response's, read
     * them) except #[Cache], which a site page does not take (see onController), and the arguments are passed on as they were resolved.
     */
    #[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS, priority: -10100)]
    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->kernel->legacyBooted() || !PageContext::has($event->getRequest())) {
            return;
        }
        $controller = $event->getController();
        $event->setController(static function (mixed ...$arguments) use ($controller): mixed {
            try {
                return $controller(...$arguments);
            } catch (\Error $error) {
                throw new PageError($error);
            }
        }, array_values(array_filter($event->getAttributes('*'), static fn (object $attribute): bool => !$attribute instanceof Cache)));
    }

    // Before Idno\Legacy\LegacyHeaderReconciler (priority 8), which works on the response this leaves
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 16)]
    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->kernel->legacyBooted() || !PageContext::has($request)) {
            return;
        }
        $context = PageContext::of($request);
        $response = $event->getResponse();
        foreach (($this->lifecycle)()->standardHeaders() as $name => $value) {
            $response->headers->set($name, $value);
        }
        foreach ($context->defaultHeaders() as $name => $value) {
            if (!$response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }
        foreach ($context->headers() as $name => $values) {
            $response->headers->set($name, $values, $context->replacesHeader($name));
        }
        // (the caching the page asked for is not set here: it is sent line by line, in place of PHP's, by Idno\Legacy\LegacyHeaderReconciler)
        if ($response->getStatusCode() === 200) {
            foreach ($context->headersOnSuccess() as [$name, $value]) {
                $response->headers->set($name, $value, false);
            }
        }
        // A forward the page made and went on from (`forward($location, false)`): the header is sent with whatever the page answers
        $redirect = $context->redirect();
        if ($redirect !== null) {
            if ($redirect->body !== null) {
                $response->setContent($redirect->body . (string) $response->getContent());
                if ($redirect->contentType() !== null) {
                    $response->headers->set('Content-Type', $redirect->contentType());
                }
            } else {
                $response->headers->set('Location', $redirect->location);
                if ($response->getStatusCode() === 200) {
                    $response->setStatusCode(PageRedirect::statusWithLocation($redirect->status));
                }
            }
        }
        // The length is of the whole body, so it is worked out last, after a forward's body is put before the page's: for a page the
        // renderer drew (it set the length of its own body, which is then too short) and for work after the response, where a legacy
        // page sent `Connection: close` and the length before it worked, so that a server that only flushes (`php -S`, mod_php) lets go
        // of the client; FPM lets go in `Response::send()`.
        if (($context->afterResponseWork() !== [] || $response->headers->has('Content-Length')) && !$response instanceof StreamedResponse && !$response instanceof BinaryFileResponse) {
            if ($context->afterResponseWork() !== []) {
                $response->headers->set('Connection', 'close');
            }
            $response->headers->set('Content-Length', (string) strlen((string) $response->getContent()));
        }
    }

    /**
     * Where a legacy page forwarded and exited, a controller's page throws a PageRedirect: the response is the redirect (or, for
     * an API request, the JSON the page echoed). The headers and caching the page asked for are added on the way out.
     */
    #[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
    public function onException(ExceptionEvent $event): void
    {
        $redirect = $event->getThrowable();
        if (!$redirect instanceof PageRedirect || !$event->isMainRequest()) {
            return;
        }
        $response = $redirect->toResponse();
        // A 200 (the JSON an API request gets, the empty answer of work after the response) is not a redirect: without this the
        // kernel makes a 500 of any 2xx a listener sets for an exception that is not an HTTP one.
        $event->allowCustomResponseCode();
        $event->setResponse($response);
        // The forward that ends the page is the one that counts, over one it made and went on from (a legacy page's second
        // `Location` header replaced the first)
        if (PageContext::has($event->getRequest())) {
            PageContext::of($event->getRequest())->setRedirect(null);
        }
    }

    /**
     * The work a page asked to be done after the visitor has the answer (`AfterResponse::run()`), with no time limit; a failure is
     * reported and never raised. It runs on `kernel.terminate`, which `index.php` calls after sending the response when the
     * page returned (a controller page never exits): a page that exits skips it, so work that must happen lives in the handler or here
     * only for a page a controller serves.
     */
    #[AsEventListener(event: KernelEvents::TERMINATE, priority: 0)]
    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        if (!PageContext::has($request) || PageContext::of($request)->afterResponseWork() === []) {
            return;
        }
        ignore_user_abort(true);
        set_time_limit(0);
        // Under FPM the client was let go in Response::send(); this is for any other SAPI, and does nothing under the CLI
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        flush();
        foreach (PageContext::of($request)->afterResponseWork() as $work) {
            try {
                $work();
            } catch (\Throwable $e) {
                ($this->lifecycle)()->reportException($e);
            }
        }
    }
}
