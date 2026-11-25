<?php

/**
 * Page handler class
 *
 * @package    idno
 * @subpackage core
 */

namespace Idno\Core {

    /**
     * Routing class.
     */
    class PageHandler extends \Toro implements \ArrayAccess, \Iterator
    {

        private $routes = [];
        private $public_routes = [];

        /// Page classes registered under /account/, as lower-case class names without a leading backslash
        private $account_pages = [];

        /// Page classes registered under /account/ or /admin/ (the settings pages), as lower-case class names without a leading backslash
        private $settings_pages = [];

        /// Whether the request being served asked for a post's Markdown form with the `.md` suffix
        private static $markdown_suffix = false;

        /**
         * Whether the class a controller's page stands for, or one it replaces, was registered in a table of the router (the
         * account pages, the settings pages): what was registered with the legacy router for the class keeps applying to the page
         * that replaced it, so a flag left off the attribute never opens a door the registration closed.
         *
         * @param array<string, bool> $registered lower-case class names without a leading backslash
         */
        private function anyRegistered(array $registered, \Idno\Http\Page\SitePage $page): bool
        {
            foreach ([$page->name, ...$page->replaces] as $class) {
                if (isset($registered[strtolower(ltrim($class, '\\'))])) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Is this pattern for a page under /account/? Those are the account pages: where a
         * user's password, email address, API key and access tokens are managed.
         *
         * @param  string $pattern A route pattern, as it is given to addRoute()
         * @return bool
         */
        static function isAccountPattern(string $pattern) : bool
        {
            return (bool) preg_match('#^/?account(/|$)#i', $pattern);
        }

        /**
         * Is this the class of an account page: one that was registered for a route under
         * /account/? Decided by how the page was registered, not by the address of the
         * current request.
         *
         * A page a controller serves (\Idno\Legacy\ControllerPage) is one when its #[SitePage] says so.
         *
         * @param  string|object $class
         * @return bool
         */
        function isAccountPage($class) : bool
        {
            if ($class instanceof \Idno\Legacy\ControllerPage) {
                $page = $class->context()->page();

                return $page->accountPage || $this->anyRegistered($this->account_pages, $page);
            }
            if (is_object($class)) {
                $class = get_class($class);
            }

            return is_string($class) && isset($this->account_pages[strtolower(ltrim($class, '\\'))]);
        }

        /**
         * Is this pattern for a page under /account/ or /admin/? Those are the settings pages: they hold stored secrets (keys,
         * tokens, passwords) in their forms.
         *
         * @param  string $pattern A route pattern, as it is given to addRoute()
         * @return bool
         */
        static function isSettingsPattern(string $pattern) : bool
        {
            return (bool) preg_match('#^/?(?:account|admin)(/|$)#i', $pattern);
        }

        /**
         * Was this page class registered for a route under /account/ or /admin/ (a plugin's admin page too)? Decided from how the
         * route was registered, never from the address of the request, which can be written in ways that a string comparison misses
         * (`//admin/email`, a request seen as https on a site configured for http). A page a controller serves
         * (\Idno\Legacy\ControllerPage) is one when its #[SitePage] says it is a settings page or an account page (every account
         * page is a settings page, as a route under /account/ is).
         *
         * @param  string|object $class
         * @return bool
         */
        function isSettingsPage($class) : bool
        {
            if ($class instanceof \Idno\Legacy\ControllerPage) {
                $page = $class->context()->page();

                return $page->settingsPage || $page->accountPage
                    || $this->anyRegistered($this->settings_pages, $page) || $this->anyRegistered($this->account_pages, $page);
            }
            if (is_object($class)) {
                $class = get_class($class);
            }

            return is_string($class) && isset($this->settings_pages[strtolower(ltrim($class, '\\'))]);
        }

        /**
         * Whether the path of the request being served had the `.md` suffix of a post's Markdown form (and was routed
         * without it).
         */
        public static function markdownSuffixRequested(): bool
        {
            return self::$markdown_suffix;
        }

        /**
         * The handler Toro will choose for a path: the same lookup as Toro::serve()'s.
         *
         * @param  iterable $routes pattern => handler, also readable as an array
         * @return mixed the handler, or null when no route matches
         */
        public static function handlerFor($routes, string $path)
        {
            if (isset($routes[$path])) {
                return $routes[$path];
            }
            // (Toro replaces its own three tokens in a pattern once more)
            $tokens = [':string' => '([a-zA-Z]+)', ':number' => '([0-9]+)', ':alpha' => '([a-zA-Z0-9-_]+)'];
            foreach ($routes as $pattern => $handler) {
                $pattern = strtr((string) $pattern, $tokens);
                if (preg_match('#^/?' . $pattern . '/?$#', $path)) {
                    return $handler;
                }
            }

            return null;
        }

        /**
         * The path Toro::serve() routes, from the three server values it reads, in its order: PATH_INFO, else ORIG_PATH_INFO unless it is
         * `/index.php`, else REQUEST_URI up to the first `?` after its first byte; `/` when none has a value.
         *
         * @param mixed $pathInfo
         * @param mixed $origPathInfo
         * @param mixed $requestUri
         * @return mixed the path (a string for any server value a SAPI gives)
         */
        private static function pathFrom($pathInfo, $origPathInfo, $requestUri)
        {
            $path = '/';
            if (!empty($pathInfo)) {
                $path = $pathInfo;
            } else if (!empty($origPathInfo) && $origPathInfo !== '/index.php') {
                $path = $origPathInfo;
            } else if (!empty($requestUri)) {
                $path = (strpos($requestUri, '?') > 0) ? strstr($requestUri, '?', true) : $requestUri;
            }

            return $path;
        }

        /**
         * The path the legacy router routes for a request, read from the request's server bag as Toro::serve() reads the
         * superglobals: the path as the server gave it (percent-encoded, with the site's subdirectory and any doubled slash),
         * which is what the registered patterns are matched against. (Once Symfony's `Request::getPathInfo()` has been called, the server bag's
         * `REQUEST_URI` is the path-only form: an absolute-form target and a fragment are taken off, which Toro, reading the superglobal, may
         * still see; `spellingsOf()` calls it first, so every spelling is of the normalised address.)
         */
        public static function toroPath(\Symfony\Component\HttpFoundation\Request $request): string
        {
            return (string) static::pathFrom(
                $request->server->get('PATH_INFO'),
                $request->server->get('ORIG_PATH_INFO'),
                $request->server->get('REQUEST_URI')
            );
        }

        /**
         * The page class the legacy router serves a path with: the handler `handlerFor()` finds, as a class name without a leading
         * backslash, or null when no route matches. A handler that is no class name (Toro also calls a callable) is named
         * `(not a class name)`, which is no page a controller replaces.
         *
         * @param  iterable $routes pattern => handler, also readable as an array
         * @return string|null
         */
        public static function chosenFor($routes, string $path): ?string
        {
            $handler = static::handlerFor($routes, $path);
            if ($handler === null || $handler === '') {
                return null;
            }

            return is_string($handler) ? ltrim($handler, '\\') : '(not a class name)';
        }

        /**
         * Every spelling of the request's path that decides who serves it: the paths the legacy router can be asked about, and the one
         * Symfony's router matched. Two routers read one request:
         *
         *  - The legacy router (Toro, `PageHandler::serve()`) routes `PATH_INFO` if the server sets it, else `ORIG_PATH_INFO` unless it is
         *    `/index.php`, else `REQUEST_URI` up to its first `?` (`toroPath()`): as the server gave it, percent-encoded or not, with the
         *    front controller's name and the site's subdirectory in it, against patterns that `addRoute()` prefixed with the subdirectory.
         *    The built-in server sets `PATH_INFO` decoded and without dot segments or doubled slashes; Apache's rewrite and Caddy split
         *    `/index.php/x` into the script and a `PATH_INFO`; nginx's shipped configuration sets none, so the raw `REQUEST_URI` is routed.
         *  - Symfony's router matches `rawurldecode($request->getPathInfo())`: the `REQUEST_URI` path (an absolute-form target and a fragment
         *    already taken off by `Request::prepareRequestUri()`, which writes the result back into the server bag) without the BASE URL
         *    (`prepareBaseUrl()`: the script's name `/index.php` or `/blog/index.php`, compared case-sensitively and encoded or not, or just
         *    the script's directory), decoded once, and never with slash runs collapsed or dot segments removed.
         *
         * The rule: whatever path Symfony matched, if the legacy router has a page for THAT path the plugin that registered it wins (the
         * path info with the site's subdirectory in front of it, `/blog` or nothing, which is how the legacy table expects it: never the
         * base URL, the script's name), IN ADDITION to what the legacy router itself would be given (`toroPath()`, which is the
         * server's own and is not decoded again: the built-in server's `PATH_INFO` is decoded already) and the request's `REQUEST_URI` (what
         * Toro reads where there is no `PATH_INFO`). Each raw spelling is asked as it is, percent-decoded once and with runs of
         * slashes made one (a pattern's `^/?` allows a single leading slash).
         *
         * @return list<string>
         */
        public static function spellingsOf(\Symfony\Component\HttpFoundation\Request $request): array
        {
            // (the site's subdirectory is what `addRoute()` puts in front of every pattern; the base URL is not it: it is the script's name, which
            // `getBasePath()` shortens to the directory only for the exact name, so `/ind%65x.php` stays in it)
            $raw = [(defined('IDNO_SUBDIRECTORY') ? '/' . IDNO_SUBDIRECTORY : '') . $request->getPathInfo()];
            $uri = $request->server->get('REQUEST_URI');
            if (is_string($uri) && $uri !== '') {
                $raw[] = (strpos($uri, '?') > 0) ? strstr($uri, '?', true) : $uri;
            }
            $spellings = [];
            foreach ($raw as $path) {
                $spellings[] = $path;
                $spellings[] = rawurldecode($path);
            }
            // (the path Toro routes is the server's: PATH_INFO, which a server sets decoded, else the raw REQUEST_URI already above)
            $spellings[] = static::toroPath($request);
            foreach ($spellings as $path) {
                $spellings[] = preg_replace('~/{2,}~', '/', $path) ?? $path;
            }

            return array_values(array_unique($spellings));
        }

        /**
         * The pages the legacy router could serve this request with, under every spelling of its path (`spellingsOf()`), by its own
         * lookup (`handlerFor()`): each as a class name (see `chosenFor()`) with whether it serves the request as the Markdown form of
         * a permalink, as `routeMarkdownSuffix()` decides it for a GET or HEAD request whose path ends in `.md`: the path as given
         * matches a route whose page is a Markdown permalink, or it matches none and without the suffix it routes to one.
         *
         * @param  iterable $routes pattern => handler, also readable as an array
         * @return list<array{class: string, markdown: bool}>
         */
        public static function claimsOf($routes, \Symfony\Component\HttpFoundation\Request $request): array
        {
            $reads = in_array(strtoupper((string) $request->server->get('REQUEST_METHOD', $request->getMethod())), ['GET', 'HEAD'], true);
            $claims = [];
            foreach (static::spellingsOf($request) as $path) {
                $stripped = $reads ? \Idno\Markdown\MarkdownRequest::pathWithoutSuffix($path) : null;
                $class = static::chosenFor($routes, $path);
                if ($class !== null) {
                    $claims[$class . '|' . (int) ($stripped !== null && is_a($class, \Idno\Common\MarkdownPermalink::class, true))] = ['class' => $class, 'markdown' => $stripped !== null && is_a($class, \Idno\Common\MarkdownPermalink::class, true)];
                } elseif ($stripped !== null) {
                    $class = static::chosenFor($routes, $stripped);
                    if ($class !== null && is_a($class, \Idno\Common\MarkdownPermalink::class, true)) {
                        $claims[$class . '|1'] = ['class' => $class, 'markdown' => true];
                    }
                }
            }

            return array_values($claims);
        }

        /**
         * A GET or HEAD request for `<permalink>.md` is routed as a request for `<permalink>`, and remembered as one that
         * asked for Markdown, when the path as given matches no route and the path without the suffix is served by a page
         * that has a Markdown form (a \Idno\Common\MarkdownPermalink). A path ending in `.md` that a permalink page's route
         * matches as given (one that takes anything after the id) is remembered the same way and routed as it is. Any
         * other path is left exactly as it is, and is not a request for Markdown.
         */
        protected static function routeMarkdownSuffix($routes): void
        {
            self::$markdown_suffix = false;
            if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')), ['GET', 'HEAD'], true)) {
                return;
            }

            // The path Toro::serve() will route
            $path = static::pathFrom($_SERVER['PATH_INFO'] ?? null, $_SERVER['ORIG_PATH_INFO'] ?? null, $_SERVER['REQUEST_URI'] ?? null);
            if (!is_string($path)) {
                return;
            }

            $stripped = \Idno\Markdown\MarkdownRequest::pathWithoutSuffix($path);
            if ($stripped === null) {
                return;
            }
            $hasMarkdown = static function ($handler): bool {
                return is_string($handler) && is_a($handler, \Idno\Common\MarkdownPermalink::class, true);
            };
            $given = static::handlerFor($routes, $path);
            if ($given !== null) {
                // The path is an address already. When it is a permalink page's (a route that takes anything after the
                // entity's id, `/entry/:id/.*`), the suffix asks that page for Markdown; the path is routed as it is
                self::$markdown_suffix = $hasMarkdown($given);

                return;
            }
            if ($hasMarkdown(static::handlerFor($routes, $stripped))) {
                // Toro reads PATH_INFO first
                $_SERVER['PATH_INFO'] = $stripped;
                self::$markdown_suffix = true;
            }
        }

        /**
         * Serve the current request. An API request (one authenticated by an API signature
         * or an access token rather than by a browser session) is refused on account pages,
         * so that an API key or token is not enough to take the account over.
         *
         * @param array $routes
         */
        public static function serve($routes)
        {
            static::routeMarkdownSuffix($routes);

            static::hook(
                'before_handler', function ($params = array()) {
                    $site = \Idno\Core\Idno::site();
                    $handler = $params['discovered_handler'] ?? null;
                    if ($site->session()->isAPIRequest() && $site->routes()->isAccountPage($handler)) {
                        $site->logging()->warning('API request to an account page refused: ' . ltrim((string) $handler, '\\'));
                        $site->template()->autodetectTemplateType();
                        $site->session()->addErrorMessage($site->language()->_('Account pages are not available to API requests.'));
                        (new \Idno\Legacy\BarePage())->deniedContent();
                    }
                }
            );

            parent::serve($routes);
        }

        function routeTokens()
        {
            return array(
                ':string' => '([a-zA-Z]+)',
                ':number' => '([0-9]+)',
                ':alpha'  => '([a-zA-Z0-9-_]+)',
                ':id'     => '([A-Za-z0-9\-]+)'
            );
        }

        /**
         * Registers a page handler for a given pattern, using Toro
         * page handling syntax
         *
         * @param string $pattern The pattern to match
         * @param string $handler The name of the Page class that will serve this route
         * @param bool   $public  If set to true, this page is always public, even on non-public sites
         */
        function addRoute(string $pattern, string $handler, bool $public = false)
        {
            if (static::isAccountPattern($pattern) && class_exists($handler)) {
                $this->account_pages[strtolower(ltrim($handler, '\\'))] = true;
            }
            if (static::isSettingsPattern($pattern) && class_exists($handler)) {
                $this->settings_pages[strtolower(ltrim($handler, '\\'))] = true;
            }
            if (defined('IDNO_SUBDIRECTORY')) {
                if (substr($pattern, 0, 1) != '/') {
                    $pattern = '/' . $pattern;
                }
                $pattern = '/' . IDNO_SUBDIRECTORY . $pattern;
            }
            $pattern = strtr($pattern, $this->routeTokens());
            if (class_exists($handler)) {
                $this->routes[$pattern] = $handler;
                if ($public == true) {
                    $this->public_routes[] = $handler;
                }
            } else {
                \Idno\Core\Idno::site()->logging()->error("Could not add $pattern. $handler not found");
            }
        }

        /**
         * Registers a page handler for a given pattern, using Toro
         * page handling syntax - and ensures it will be handled first
         *
         * @param string $pattern The pattern to match
         * @param string $handler The name of the Page class that will serve this route
         * @param bool   $public  If set to true, this page is always public, even on non-public sites
         */
        function hijackRoute(string $pattern, string $handler, bool $public = false)
        {
            if (static::isAccountPattern($pattern) && class_exists($handler)) {
                $this->account_pages[strtolower(ltrim($handler, '\\'))] = true;
            }
            if (static::isSettingsPattern($pattern) && class_exists($handler)) {
                $this->settings_pages[strtolower(ltrim($handler, '\\'))] = true;
            }
            $pattern = strtr($pattern, $this->routeTokens());
            if (class_exists($handler)) {
                unset($this->routes[$pattern]);
                unset($this->public_routes[$pattern]);
                $this->routes = array($pattern => $handler) + $this->routes;
                if ($public == true) {
                    $this->public_routes = array($pattern => $handler) + $this->public_routes;
                }
            }
        }

        /**
         * Mark a page handler class as offering public content even on walled garden sites
         *
         * @param $class
         */
        function addPublicRoute(string $class)
        {
            if (class_exists($class)) {
                $this->public_routes[] = $class;
            }
        }

        /**
         * Retrieve an array of walled garden page handlers
         *
         * @return array
         */
        function getPublicRoute()
        {
            if (!empty($this->public_routes)) {
                return $this->public_routes;
            }

            return array();
        }

        /**
         * Does the specified page handler class represent a public page, even on walled gardens? Takes a class name or the
         * page itself; a page a controller serves (\Idno\Legacy\ControllerPage) is public when its #[SitePage] says so.
         *
         * @param  string|object $class
         * @return bool
         */
        function isRoutePublic(string|object $class) : bool
        {
            if ($class instanceof \Idno\Legacy\ControllerPage) {
                $page = $class->context()->page();
                if ($page->publicOnPrivateSite || $this->isRoutePublic($page->name)) {
                    return true;
                }
                foreach ($page->replaces as $replaced) {
                    if ($this->isRoutePublic($replaced)) {
                        return true;
                    }
                }

                return false;
            }
            if (is_object($class)) {
                $class = get_class($class);
            }
            if (!empty($class)) {
                if (in_array($class, $this->getPublicRoute())) {
                    return true;
                }
                if ($class[0] != "\\") {
                    $class = "\\" . $class;
                    if (in_array($class, $this->getPublicRoute())) {
                        return true;
                    }
                }
            }

            return false;
        }

        /**
         * Retrieves an instantiated version of the page handler class responsible for
         * a particular page (if any). May also be a whole URL.
         *
         * @param  string $path_info The path, including the initial /, or the URL
         * @return bool|\Idno\Common\Page
         */
        function getRoute(string $path_info)
        {
            $path_info = parse_url($path_info, PHP_URL_PATH);
            if ($q = strpos($path_info, '?')) {
                $path_info = substr($path_info, 0, $q);
            }
            $discovered_handler = false;
            $matches = array();
            foreach ($this->routes as $pattern => $handler_name) {
                $pattern = strtr($pattern, $this->routeTokens());
                if (preg_match('#^/?' . $pattern . '/?$#', $path_info, $matches)) {
                    $discovered_handler = $handler_name;
                    $regex_matches = $matches;
                    break;
                }
            }
            if (class_exists($discovered_handler)) {
                $page = new $discovered_handler();
                if ($page instanceof \Idno\Common\Page) {
                    unset($matches[0]);
                    $page->arguments = array_values($matches);

                    return $page;
                }
            }

            return false;
        }

        /**
         * Adds a hook. Maps to ToroHook::add
         *
         * @see ToroHook
         *
         * @param string   $hookName Name of hook
         * @param callable $callable
         */
        static function hook(string $hookName, callable $callable)
        {
            \ToroHook::add($hookName, $callable);
        }

        // ArrayAccess & Iterator interfaces ////////

        public function offsetExists($offset): bool
        {
            return isset($this->routes[$offset]);
        }

        public function offsetGet($offset): mixed
        {
            return isset($this->routes[$offset]) ? $this->routes[$offset] : null;
        }

        public function offsetSet($offset, $value): void
        {
            if (is_null($offset)) {
                $this->routes[] = $value;
            } else {
                $this->routes[$offset] = $value;
            }
        }

        public function offsetUnset($offset): void
        {
            unset($this->routes[$offset]);
        }

        function rewind(): void
        {
            reset($this->routes);
        }

        function current(): mixed
        {
            return current($this->routes);
        }

        function key(): mixed
        {
            return key($this->routes);
        }

        function next(): void
        {
            next($this->routes);
        }

        function valid(): bool
        {
            return key($this->routes) !== null;
        }

        //////////////////////////////////////////////

    }

}

