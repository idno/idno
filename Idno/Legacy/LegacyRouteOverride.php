<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\PageHandler;
use Idno\Http\Page\SitePage;
use Idno\Kernel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps the legacy router's choice for a path a controller serves as a site page.
 *
 * A controller's route (`#[SitePage]`) is matched by Symfony's router before the legacy router is asked anything, so it would shadow every
 * legacy page registered for the same path, a plugin's too. The legacy route table is exactly what it was before any page moved: core registers a
 * moved page's pattern where and when it always did, with a placeholder (`ControllerRoute`) in place of the class that was deleted (a kept shell
 * is still registered under its own class). So there is ONE question, put to the legacy router's own lookup over that table, which applies every rule
 * it has (an exact key first, then the patterns in registration order; `addRoute()` replaces a key in place, `hijackRoute()` puts one in front):
 * which page would you choose for this path? (`PageHandler::claimsOf()`, `handlerFor()`, pinned against Toro by `RouteLookupTest`.) When the
 * answer is a placeholder or the class the controller's `#[SitePage]` names when it says `shell` (its kept shell), core's place is held and the controller
 * serves; when it is anything else a plugin took the path and the request is handed to the legacy controller, with none of the route's parameters.
 *
 * The paths asked are the ones the two routers can be given (`PageHandler::spellingsOf()` has the derivation from `Request::prepareBaseUrl()`,
 * `preparePathInfo()`, the matcher and Toro): the path Symfony matched (its path info with the site's subdirectory in front, as the table is keyed:
 * never the base URL, the script's name) raw and decoded once, and the path the legacy router itself is given (`toroPath()`: the server's `PATH_INFO`,
 * else the raw `REQUEST_URI`, which carries the front controller's name where the server does not split it: nginx's shipped configuration), each
 * with slash runs collapsed. Any of them naming another page is a claim, so a variant of the address that reaches the controller is a variant the
 * lookup sees, whichever server and whatever the base URL. A request that serves a permalink's Markdown form (`routeMarkdownSuffix()`'s rule) and
 * whose controller is no Markdown permalink is the shell's: handed back too.
 *
 * (`LegacyDispatcher::routes()` also writes the subdirectory's alias of the home page into the router's table, so that a plugin's `hijackRoute('/')`
 * is chosen for `/blog/` by its exact key: it did when the request was dispatched, and does it here a moment earlier, once, whoever asks first.)
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

        if (!self::handsBack($page, ($this->dispatcher)()->routes(), $request)) {
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

    /**
     * Whether the request goes to the legacy router and not to the controller whose `#[SitePage]` this is: the router's choice, for each spelling of the
     * path, is a page that is neither a placeholder nor the shell this controller replaces (a plugin took the path), or serves the request as a permalink's
     * Markdown form where the controller is no Markdown permalink itself.
     *
     * @param iterable $routes pattern => handler, the legacy route table
     */
    public static function handsBack(SitePage $page, iterable $routes, Request $request): bool
    {
        $replaced = array_map(static fn (string $class): string => strtolower(ltrim($class, '\\')), [ControllerRoute::class, ...($page->shell ? [$page->name] : [])]);
        foreach (PageHandler::claimsOf($routes, $request) as $claim) {
            if (!in_array(strtolower($claim['class']), $replaced, true) || ($claim['markdown'] && !$page->markdownPermalink)) {
                return true;
            }
        }

        return false;
    }
}
