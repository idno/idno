<?php

// No strict_types here, on purpose: the code below is moved unchanged from index.php and
// must coerce exactly as it did there.

namespace Idno\Legacy;

use Idno\Core\Config;
use Idno\Core\PageHandler;

/**
 * Serves a request with the legacy router: the routing that index.php did before the
 * Symfony kernel handled requests, moved here unchanged.
 */
final class LegacyDispatcher
{
    public function __construct(private PageHandler $routes, private Config $config)
    {
    }

    /**
     * The path of the site URL without a trailing slash; '' at the domain root.
     */
    public function basePath(): string
    {
        // Get subdirectory

        $url = $this->config->getURL();
        $path = parse_url($url, PHP_URL_PATH);
        if (substr((string) $path, -1) == '/') {
            $path = substr($path, 0, -1);
        }

        return (string) $path;
    }

    /**
     * The routes to serve: when the site is in a subdirectory, the '/' route is also
     * registered at basePath() . '/'.
     */
    public function routes(): PageHandler
    {
        // Get page routes

        $routes = $this->routes;

        $path = $this->basePath();
        if (!empty($path)) {
            if (!empty($routes['/'])) {
                $routes[$path . '/'] = $routes['/'];
            }
        }

        return $routes;
    }

    /**
     * The choice recorded on the request for this router's table (`PageHandler::choiceFor()`), when it is still the router's for the request's method and
     * path; null when none was recorded.
     *
     * @return array<string, mixed>|null
     */
    public function recordedChoice(\Symfony\Component\HttpFoundation\Request $request): ?array
    {
        return PageHandler::recordedChoice($this->routes, $request);
    }

    /**
     * Registers the Toro 404 hook and serves the request. May not return: legacy pages
     * call exit.
     *
     * `$choice` is what the router already decided for this request (`PageHandler::recordedChoice()`): Toro is given the one entry it chose, or no entry for a
     * request it has no page for (`[]`), so the table is looked up once for the request. Without one it is given the whole table, as it always was.
     *
     * @param array<string, mixed>|null $choice
     */
    public function dispatch(?array $choice = null): void
    {
        // Only the first kernel booted in a process has legacy services wired to
        // Idno::site() (see LegacyBootstrap::boot()). A dispatcher from any other kernel
        // holds an empty route table and would answer every request with a 404.
        $site = \Idno\Core\Idno::site();
        if ($site === null || $site->routes() !== $this->routes) {
            throw new \LogicException(
                'LegacyDispatcher can only serve the routes of the booted legacy application (Idno::site()->routes()); '
                . 'this one comes from a kernel that did not boot it. Use the first kernel booted in the process, '
                . 'or a kernel with bootLegacy: false that never reaches the legacy router.'
            );
        }

        $routes = $this->routes();
        if ($choice !== null) {
            $routes = $choice === [] ? [] : [$choice['pattern'] => $this->routes[$choice['pattern']]];
        }

        // Manage routing

        PageHandler::hook(
            '404', function ($params = array()) {
                http_response_code(404);
                $t = \Idno\Core\Idno::site()->template();

                // Take over page detection
                \Idno\Core\Idno::site()->template()->autodetectTemplateType();

                (new BarePage())->noContent();
            }
        );
        PageHandler::serve($routes);
    }
}
