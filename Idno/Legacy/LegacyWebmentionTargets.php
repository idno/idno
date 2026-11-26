<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Page;
use Idno\Config\BootConfig;
use Idno\Http\Page\SitePage;
use Idno\Http\Webmention\ReceivedWebmention;
use Idno\Http\Webmention\WebmentionTarget;
use Idno\Http\Webmention\WebmentionTargets;
use Idno\Core\PageHandler;
use Idno\Kernel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Finds the page of this site that an address is, for a webmention aimed at it, as a GET of the address is routed, and builds no page to do it.
 *
 * The endpoint used to ask the legacy router for the page CLASS of the target (`PageHandler::getRoute()`), which builds the class (its constructor's side
 * effects included) and cannot find a page that has no class. This asks what serves the address:
 *
 * 1. Whose address it is. The target's host is the site's (the `url` setting's host, compared ignoring case) and its scheme is NOT compared, so the `http://` and
 *    `https://` spellings of the site's own pages are both the site's, as the legacy lookup, which looked at the path alone, took them. A port matters only when the target names
 *    one that is not its scheme's default: it must then equal the site's effective port (its explicit one, else its scheme's default); a target that names no port, or its
 *    scheme's own, is on the host whatever port the site is served from. Another host, a host-less address and a scheme that is not http or https are not the site's, and
 *    neither is an address outside the directory a site in a subdirectory is served from. A site with no `url` setting has no address to compare with and finds
 *    no target.
 * 2. A page a controller serves. A GET request for the address is made (`Request::create()`, with the site's directory as the front controller's) and matched by Symfony's
 *    router (its `RequestContext` set from that request and put back as it was, whatever happens); a site-page route that `LegacyRouteOverride::handsBack()` does not hand
 *    back to the legacy router (a plugin did not take the path) is the target: the page's `#[SitePage]` name, the route's name and its parameters.
 * 3. A page the legacy route table serves. `PageHandler::routedFor()` (the legacy router's own lookup) says the class and the arguments. A class that names a controller
 *    action routed by the table (`#[RoutedByLegacyTable]`, in its own `ACTION`) is that action's page. Any other class is a LEGACY target: a plugin's page, a subclass of a shell, a page
 *    of a bundled plugin that is still a class; it receives as the legacy endpoint made it receive (the class built, its `arguments` set, marked a permalink, `webmentionContent()`
 *    called), and nothing is built before it is asked to. A placeholder, which is no page, is no target.
 * 4. Otherwise nothing.
 *
 * It finds nothing when this kernel did not boot the legacy application. The legacy dispatcher, which needs it, is a service closure.
 */
final class LegacyWebmentionTargets implements WebmentionTargets
{
    /**
     * @param \Closure(): LegacyDispatcher $dispatcher
     */
    public function __construct(
        private readonly RouterInterface $router,
        #[AutowireServiceClosure(LegacyDispatcher::class)]
        private readonly \Closure $dispatcher,
        #[Autowire(service: 'kernel')]
        private readonly Kernel $kernel,
        private readonly BootConfig $config,
    )
    {
    }

    public function resolve(string $target): ?WebmentionTarget
    {
        if (!$this->kernel->legacyBooted()) {
            return null;
        }
        $request = $this->requestFor($target);
        if ($request === null) {
            return null;
        }

        $match = $this->match($request);
        if ($match !== null && ($route = $match['_route'] ?? null) !== 'idno_legacy' && is_string($route)) {
            $controller = $match['_controller'] ?? null;
            $page = is_string($controller) || is_array($controller) ? SitePage::of($controller) : null;
            if ($page !== null && !LegacyRouteOverride::handsBack($page, ($this->dispatcher)()->routes(), $request)) {
                $arguments = [];
                foreach ($match as $name => $value) {
                    if (!str_starts_with((string) $name, '_') && !in_array($name, ['lead', 'slash'], true)) {
                        $arguments[] = (string) $value;
                    }
                }

                return new WebmentionTarget($page->name, $route, $arguments);
            }
        }

        $routed = PageHandler::routedFor(($this->dispatcher)()->routes(), 'GET', PageHandler::toroPath($request));
        if ($routed === null) {
            return null;
        }
        $class = $routed['class'];
        $action = LegacyRoutedPages::actionOf($class);
        $routedBy = $action !== null ? LegacyRoutedPages::routedBy($action) : null;
        $page = $action !== null ? SitePage::of($action) : null;
        if ($routedBy !== null && $page !== null && $page->allows('GET')) {
            return new WebmentionTarget($page->name, $routedBy->route, $routed['arguments']);
        }
        if (!is_a($class, Page::class, true)) {
            return null;
        }
        $arguments = $routed['arguments'];

        return new WebmentionTarget($class, '', $arguments, static function (ReceivedWebmention $mention) use ($class, $arguments): bool {
            $page = new $class();
            $page->arguments = $arguments;
            $page->setPermalink();

            return (bool) $page->webmentionContent($mention->source, $mention->target, $mention->sourceResponse, $mention->sourceMf2);
        });
    }

    /**
     * A GET request for the target, when it is an address of this site (see the class's first rule): null when it is not.
     */
    private function requestFor(string $target): ?Request
    {
        $site = parse_url((string) $this->config->get('url', ''));
        $parts = parse_url($target);
        if (!is_array($site) || !is_array($parts) || empty($site['host']) || empty($parts['host']) || empty($site['scheme']) || empty($parts['scheme'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true) || strtolower($site['host']) !== strtolower($parts['host'])) {
            return null;
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && $port !== self::defaultPort($scheme) && $port !== ($site['port'] ?? self::defaultPort(strtolower($site['scheme'])))) {
            return null;
        }

        // the directory a site in a subdirectory is served from is the `url`'s path: an address outside it is no page of the site
        $directory = rtrim($site['path'] ?? '', '/');
        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        if ($directory !== '' && $path !== $directory && !str_starts_with($path, $directory . '/')) {
            return null;
        }

        $uri = $scheme . '://' . $parts['host'] . ($port !== null ? ':' . $port : '') . $path . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
        try {
            return Request::create($uri, 'GET', [], [], [], [
                'SCRIPT_NAME' => $directory . '/index.php',
                'PHP_SELF' => $directory . '/index.php',
                'SCRIPT_FILENAME' => '/srv' . $directory . '/index.php',
            ]);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }

    /**
     * What Symfony's router matches the request to, with the router's context set from the request for the match and put back as it was afterwards (also when
     * matching throws); null when no route matches.
     *
     * @return array<string, mixed>|null
     */
    private function match(Request $request): ?array
    {
        $context = $this->router->getContext();
        $saved = clone $context;
        try {
            $context->fromRequest($request);

            return $this->router->match($request->getPathInfo());
        } catch (ResourceNotFoundException | MethodNotAllowedException) {
            return null;
        } catch (\UnexpectedValueException) {
            // (a host Symfony refuses to read, which the site's own is not)
            return null;
        } finally {
            self::restore($context, $saved);
        }
    }

    private static function restore(RequestContext $context, RequestContext $saved): void
    {
        $context->setBaseUrl($saved->getBaseUrl());
        $context->setPathInfo($saved->getPathInfo());
        $context->setMethod($saved->getMethod());
        $context->setHost($saved->getHost());
        $context->setScheme($saved->getScheme());
        $context->setHttpPort($saved->getHttpPort());
        $context->setHttpsPort($saved->getHttpsPort());
        $context->setQueryString($saved->getQueryString());
        $context->setParameters($saved->getParameters());
    }
}
