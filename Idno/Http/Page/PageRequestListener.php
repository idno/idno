<?php

declare(strict_types=1);

namespace Idno\Http\Page;

use Idno\Kernel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

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
        $request = $event->getRequest();
        $lifecycle = ($this->lifecycle)();
        $context = new PageContext($request, $page);
        $context->attachTo($request);
        $lifecycle->begin($context);

        if ($request->getMethod() === 'OPTIONS') {
            // What Page::options() answers: the page was begun (its events fired), nothing more.
            $event->setController(static fn (): Response => new Response('', 204, [
                'Access-Control-Allow-Methods' => 'GET, POST, HEAD, OPTIONS, PUT, DELETE',
                'Access-Control-Max-Age' => '86400',
            ]));

            return;
        }
        if ($lifecycle->refusesApiRequest($context) || !$lifecycle->mayView($context)) {
            throw new AccessDeniedHttpException();
        }
        $lifecycle->enter($context);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 0)]
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
        foreach ($context->headers() as $name => $values) {
            $response->headers->set($name, $values, $context->replacesHeader($name));
        }
        if ($context->ownsCaching()) {
            foreach ($context->caching() as $name => $value) {
                $response->headers->set($name, $value);
            }
        }
    }
}
