<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Page;
use Idno\Config\BootConfig;
use Idno\Http\Page\SitePage;
use Idno\Http\Webmention\ReceivedWebmention;
use Idno\Http\Webmention\ReceivesWebmentions;
use Idno\Http\Webmention\TargetHosts;
use Idno\Http\Webmention\WebmentionTarget;
use Idno\Http\Webmention\WebmentionTargets;
use Idno\Core\Idno;
use Idno\Core\PageHandler;
use Idno\Kernel;
use Idno\Portability\SiteFacts;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * Finds the page of this site that an address is, for a webmention aimed at it, as a GET of the address is routed, and builds no page to do it. A target is a page
 * that takes mentions: an address whose page cannot receive one is no target, so a caller answers "no such target" before it fetches the source.
 *
 * The endpoint used to ask the legacy router for the page CLASS of the target (`PageHandler::getRoute()`), which builds the class (its constructor's side
 * effects included) and cannot find a page that has no class. This asks what serves the address:
 *
 * 1. Whose address it is (`TargetHosts`). An http or https address, with no user information, no control character and no dot segment, on the site's own host and no
 *    other (not its `www.` or bare counterpart), on the site's port (when its address names one that is not its scheme's default) or its scheme's default port; for a
 *    site in a subdirectory, under it. The scheme is not compared. An address with no host is refused. The site's address is the boot `url`; without one it is the
 *    address the site detected for itself (`SiteFacts::url()`, which can follow the request's Host header), with ONE warning a process naming the setting: the address
 *    is never matched by its path alone.
 * 2. A page a controller serves. A GET `Request::create()` for the address (the site's directory as the front controller's) is matched by Symfony's router, the
 *    router's `RequestContext` set from it and put back as it was, whatever happens; a route whose controller is a site page (`#[SitePage]`) that
 *    `LegacyRouteOverride::handsBack()` does not hand back is the page: its `#[SitePage]` name, the route's name and its path parameters (but `lead` and `slash`) in the
 *    order of the path. A route that is no site page's is no page of the site's. The page is a target only when the service tagged `idno.webmention_receiver` under its
 *    name takes mentions for that route (`ReceivesWebmentions::receivesFor()`).
 * 3. A page the legacy route table serves. `PageHandler::routedFor()` (the legacy router's own lookup) says the class and the arguments. A class that names a
 *    routed action in its own `ACTION` (`#[RoutedByLegacyTable]`) is that action's page, a target when its receiver takes the route. Any other class that is a
 *    `Page` which declares a `webmentionContent()` of its own is a LEGACY target (a plugin's page, a subclass of a shell, StaticPages' `Pages\View`, a page
 *    of core that is still a class): it receives as the legacy endpoint made it receive (the class built, its `arguments` set, marked a permalink,
 *    `webmentionContent()` called), and nothing is built before it is asked to. A page that does not (the base `Page` answers false), and a placeholder, is no target.
 * 4. Otherwise nothing.
 *
 * It finds nothing when this kernel did not boot the legacy application. The legacy dispatcher, which needs it, is a service closure.
 */
final class LegacyWebmentionTargets implements WebmentionTargets
{
    private bool $warned = false;

    /**
     * @param \Closure(): LegacyDispatcher $dispatcher
     * @param \Closure(): LoggerInterface  $logger
     */
    public function __construct(
        private readonly RouterInterface $router,
        #[AutowireServiceClosure(LegacyDispatcher::class)]
        private readonly \Closure $dispatcher,
        #[Autowire(service: 'kernel')]
        private readonly Kernel $kernel,
        private readonly BootConfig $config,
        private readonly SiteFacts $site,
        #[AutowireLocator('idno.webmention_receiver')]
        private readonly ContainerInterface $receivers,
        #[AutowireServiceClosure(LoggerInterface::class)]
        private readonly \Closure $logger,
    )
    {
    }

    public function resolve(string $target): ?WebmentionTarget
    {
        if (!$this->kernel->legacyBooted()) {
            return null;
        }
        $address = (new TargetHosts($this->siteAddress()))->locate($target);
        if ($address === null) {
            return null;
        }
        $request = $this->requestFor($address);
        if ($request === null) {
            return null;
        }

        $match = $this->match($request);
        if ($match !== null && ($route = $match['_route'] ?? null) !== 'idno_legacy') {
            // a route of Symfony's own that is no page of the site's (and no legacy page) is not one a GET of the address shows
            $controller = $match['_controller'] ?? null;
            $page = is_string($controller) || is_array($controller) ? SitePage::of($controller) : null;
            if ($page === null || !is_string($route)) {
                return null;
            }
            if (LegacyRouteOverride::handsBack($page, ($this->dispatcher)()->routes(), $request)) {
                return $this->legacyTarget($request);
            }

            return $this->receivedBy($page->name, $route, $this->argumentsOf($route, $match));
        }

        return $this->legacyTarget($request);
    }

    /**
     * The page the legacy route table serves the request with.
     */
    private function legacyTarget(Request $request): ?WebmentionTarget
    {
        $routed = PageHandler::routedFor(($this->dispatcher)()->routes(), 'GET', PageHandler::toroPath($request));
        if ($routed === null) {
            return null;
        }
        $class = $routed['class'];
        $action = LegacyRoutedPages::actionOf($class);
        $routedBy = $action !== null ? LegacyRoutedPages::routedBy($action) : null;
        $page = $action !== null ? SitePage::of($action) : null;
        if ($routedBy !== null && $page !== null && $page->allows('GET')) {
            return $this->receivedBy($page->name, $routedBy->route, $routed['arguments']);
        }
        // a page that takes no webmention (the base page answers false) is no target
        if (!is_a($class, Page::class, true) || (new \ReflectionMethod($class, 'webmentionContent'))->getDeclaringClass()->getName() === Page::class) {
            return null;
        }
        $arguments = $routed['arguments'];

        return new WebmentionTarget($class, '', $arguments, static function (ReceivedWebmention $mention) use ($class, $arguments): bool {
            // Building the page is what the legacy router did, and its constructor makes the page the site's current page, installs its exception handler and
            // queues the headers every legacy page sends: what the request being served had made of them is put back, whatever the page does
            $site = Idno::site();
            $current = $site->currentPage();
            $built = false;
            try {
                $page = new $class();
                $built = true;
                $page->arguments = $arguments;
                $page->setPermalink();

                return (bool) $page->webmentionContent($mention->source, $mention->target, $mention->sourceResponse, $mention->sourceMf2);
            } finally {
                if ($built) {
                    restore_exception_handler();
                    $headers = new PhpHeaders();
                    foreach (['X-Powered-By', 'X-Clacks-Overhead', 'X-Idno-Build-Fingerprint'] as $name) {
                        $headers->remove($name);
                    }
                }
                if ($current instanceof Page) {
                    $site->setCurrentPage($current);
                }
            }
        });
    }

    /**
     * A page a controller serves, when the service that receives its mentions takes this route.
     *
     * @param list<string> $arguments
     */
    private function receivedBy(string $page, string $route, array $arguments): ?WebmentionTarget
    {
        if (!$this->receivers->has($page)) {
            return null;
        }
        $receiver = $this->receivers->get($page);

        return $receiver instanceof ReceivesWebmentions && $receiver->receivesFor($route) ? new WebmentionTarget($page, $route, $arguments) : null;
    }

    /**
     * The route's path parameters (but `lead` and `slash`), in the order of the path: the matcher's array puts the defaults first.
     *
     * @param array<string, mixed> $match
     *
     * @return list<string>
     */
    private function argumentsOf(string $route, array $match): array
    {
        $found = $this->router->getRouteCollection()->get($route);
        $names = $found !== null ? $found->compile()->getPathVariables() : [];
        $arguments = [];
        foreach ($names as $name) {
            if (!in_array($name, ['lead', 'slash'], true) && isset($match[$name])) {
                $arguments[] = (string) $match[$name];
            }
        }

        return $arguments;
    }

    /**
     * The site's address: the boot `url`, else the address the site detected for itself (with a warning, once).
     */
    private function siteAddress(): string
    {
        $url = $this->config->get('url');
        if (is_string($url) && $url !== '') {
            return $url;
        }
        if (!$this->warned) {
            $this->warned = true;
            ($this->logger)()->warning('The url setting is not configured: webmention targets are matched against the host the site detected for itself, which can follow the request. Set url in config.ini.');
        }

        return $this->site->url();
    }

    /**
     * A GET request for an address that is the site's.
     *
     * @param array{scheme: string, host: string, port: int|null, path: string, query: string|null, directory: string} $address
     */
    private function requestFor(array $address): ?Request
    {
        $uri = $address['scheme'] . '://' . $address['host'] . ($address['port'] !== null ? ':' . $address['port'] : '') . $address['path'] . ($address['query'] !== null ? '?' . $address['query'] : '');
        $directory = $address['directory'];
        try {
            return Request::create($uri, 'GET', [], [], [], [
                'SCRIPT_NAME' => $directory . '/index.php',
                'PHP_SELF' => $directory . '/index.php',
                'SCRIPT_FILENAME' => '/srv' . $directory . '/index.php',
            ]);
        } catch (\InvalidArgumentException | BadRequestException) {
            // (an address Symfony refuses to make a request of: one with a backslash in it, a malformed one)
            return null;
        }
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
