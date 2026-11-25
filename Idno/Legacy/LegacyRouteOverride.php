<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\PageHandler;
use Idno\Http\Page\SitePage;
use Idno\Kernel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps the legacy router's choice for a path a controller serves as a site page.
 *
 * A controller's route (`#[SitePage]`) is matched by Symfony's router before the legacy router is asked anything, so it would
 * shadow every legacy page registered for the same path, a plugin's too. A plugin that took the path with `addRoute()` of an
 * identical pattern, with `hijackRoute()`, or with a subclass of the page the controller replaces, must still be served as it is
 * today. This listener runs right after the router (`RouterListener`, priority 32) and, for a request whose route is a site
 * page's, asks the legacy router which page it would serve the path with: `PageHandler::chosenFor()`, the router's own lookup
 * (the exact key, then the patterns in the order they were registered) on the path the router reads (`PageHandler::claimsOf()`: every spelling of the
 * server's path, with the site's subdirectory). When that page is not one the controller's `#[SitePage]` `replaces`, the legacy
 * page keeps the path: the request is handed to the legacy controller, with none of the route's parameters.
 *
 * (`LegacyDispatcher::routes()` also writes the subdirectory's alias of the home page into the router's table: it did when the request
 * was dispatched, and does it here a moment earlier, once, whoever asks first.)
 *
 * It does nothing, and builds nothing, for a request that is not a site page's (every other route, the legacy catch-all among them,
 * is left alone before the router's table is touched) or when this kernel did not boot the legacy application (a `bootLegacy: false`
 * kernel, the schema gate closed, an upgrade step): the dispatcher, which needs it, is a service closure, fetched only for a site page.
 * Logging nothing is deliberate: a path a plugin took is not an event.
 */
final class LegacyRouteOverride
{
    /**
     * @param \Closure(): LegacyDispatcher $dispatcher
     */
    public function __construct(
        #[Autowire(service: 'kernel')]
        private readonly Kernel $kernel,
        #[AutowireServiceClosure(LegacyDispatcher::class)]
        private readonly \Closure $dispatcher,
    )
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 31)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->kernel->legacyBooted()) {
            return;
        }
        $request = $event->getRequest();
        $controller = $request->attributes->get('_controller');
        if (!is_string($controller) && !is_array($controller)) {
            return;
        }
        $page = SitePage::of($controller);
        if ($page === null) {
            return;
        }

        // Every page the legacy router could serve this request with, under every spelling of its path it could be given (the router
        // matched the DECODED path, the legacy router reads the raw one or, where the server sets it, a decoded PATH_INFO): one that is not a
        // page this controller replaces keeps the path, and so does one that serves the request as a permalink's Markdown form where
        // the controller is no Markdown permalink itself
        $replaced = array_map(static fn (string $class): string => strtolower(ltrim($class, '\\')), $page->replaces);
        $kept = false;
        foreach (PageHandler::claimsOf(($this->dispatcher)()->routes(), $request) as $claim) {
            if (!in_array(strtolower($claim['class']), $replaced, true) || ($claim['markdown'] && !$page->markdownPermalink)) {
                $kept = true;
                break;
            }
        }
        if (!$kept) {
            return;
        }

        // The legacy router's page keeps the path: what the catch-all route would have matched, with none of this route's parameters
        $parameters = $request->attributes->get('_route_params');
        foreach (is_array($parameters) ? array_keys($parameters) : [] as $name) {
            $request->attributes->remove((string) $name);
        }
        $path = substr($request->getPathInfo(), 1);
        $request->attributes->add(['_controller' => LegacyController::class, '_route' => 'idno_legacy', '_route_params' => ['path' => $path], 'path' => $path]);
    }
}
