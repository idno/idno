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
         * Whether the class a controller's page stands for (its name, which is its shell's when it has one) was registered in a table of the router (the
         * account pages, the settings pages): what was registered with the legacy router for the class keeps applying to the page
         * that replaced it, so a flag left off the attribute never opens a door the registration closed.
         *
         * @param array<string, bool> $registered lower-case class names without a leading backslash
         */
        private function anyRegistered(array $registered, \Idno\Http\Page\SitePage $page): bool
        {
            return isset($registered[strtolower(ltrim($page->name, '\\'))]);
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
            return static::matchFor($routes, $path)['handler'] ?? null;
        }

        /**
         * Toro's lookup for a path with what it keeps of the match: the exact key first (`pattern` the key, `matches` empty), then the first
         * pattern, in order, that matches (`matches` as `preg_match()` gives them: the whole match first, an unmatched trailing group absent, an
         * unmatched middle one empty; named groups, which Toro would pass by name, are not used by any registered pattern and arrive positionally). The ONE implementation of the lookup: `handlerFor()` and `routedFor()` read it.
         *
         * @param  iterable $routes pattern => handler, also readable as an array
         * @return array{handler: mixed, pattern: string|int, matches: array}|null
         */
        private static function matchFor($routes, string $path): ?array
        {
            if (isset($routes[$path])) {
                return ['handler' => $routes[$path], 'pattern' => $path, 'matches' => []];
            }
            // (Toro replaces its own three tokens in a pattern once more)
            $tokens = [':string' => '([a-zA-Z]+)', ':number' => '([0-9]+)', ':alpha' => '([a-zA-Z0-9-_]+)'];
            foreach ($routes as $pattern => $handler) {
                if (preg_match('#^/?' . strtr((string) $pattern, $tokens) . '/?$#', $path, $matches)) {
                    return ['handler' => $handler, 'pattern' => $pattern, 'matches' => $matches];
                }
            }

            return null;
        }

        /**
         * What the legacy router will do with a request: its choice for the path (`matchFor()`), the arguments it calls the page with (the captured
         * groups less the whole match, positional; none for an exact key), and, for a GET or HEAD (`$method` is the REAL method, as Toro reads
         * `REQUEST_METHOD`), the `.md` rule: the path as given matched a `MarkdownPermalink` page (`markdown` true, routed as given), or matched
         * nothing and the path without the suffix routes to one (that route, `markdown` true, `path` the path without the suffix). Pure over its arguments.
         *
         * @param  iterable $routes pattern => handler, also readable as an array
         * @return array{class: string, pattern: string|int, arguments: list<string>, markdown: bool, path: string}|null null when nothing matches
         */
        public static function routedFor($routes, string $method, string $path): ?array
        {
            $reads = in_array(strtoupper($method), ['GET', 'HEAD'], true);
            $stripped = $reads ? \Idno\Markdown\MarkdownRequest::pathWithoutSuffix($path) : null;
            $hasMarkdown = static function ($handler): bool {
                return is_string($handler) && is_a($handler, \Idno\Common\MarkdownPermalink::class, true);
            };

            $match = static::matchFor($routes, $path);
            $markdown = false;
            if ($match !== null) {
                $markdown = $stripped !== null && $hasMarkdown($match['handler']);
            } elseif ($stripped !== null) {
                $match = static::matchFor($routes, $stripped);
                if ($match === null || !$hasMarkdown($match['handler'])) {
                    return null;
                }
                $markdown = true;
                $path = $stripped;
            }
            if ($match === null) {
                return null;
            }

            // (Toro serves the 404 for a matching route whose handler is false in PHP, and tries no later pattern)
            $handler = $match['handler'];
            if (!$handler) {
                return null;
            }

            return [
                'class' => is_string($handler) ? ltrim($handler, '\\') : '(not a class name)',
                'pattern' => $match['pattern'],
                'arguments' => array_values(array_slice($match['matches'], 1)),
                'markdown' => $markdown,
                'path' => $path,
            ];
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
         * The paths of the request that decide who serves it, for the legacy router to be asked about. Two routers read one request: Symfony's matches
         * `rawurldecode($request->getPathInfo())` (the `REQUEST_URI` path, an absolute-form target and a fragment taken off by
         * `Request::prepareRequestUri()`, without the BASE URL, the script's name `/index.php` or `/blog/index.php`, decoded once, never with slash
         * runs collapsed), and the legacy router (Toro) routes what `toroPath()` says (`PATH_INFO` where the server sets it, decoded by the built-in
         * server, split off by Apache's rewrite, unset under nginx's shipped configuration; else the raw `REQUEST_URI`). The table is keyed with the site's
         * subdirectory in front of every pattern `addRoute()` registers, so the paths asked are: the path Symfony matched (the path info with the
         * subdirectory in front: never the base URL) raw and decoded once, and what the legacy router is given (`toroPath()`, as it is: a server's
         * `PATH_INFO` is decoded already), each with runs of slashes made one (a pattern's `^/?` allows one leading slash). The request's own
         * `REQUEST_URI` is not asked: the path info is that, less the base URL, which no pattern holds.
         *
         * @return list<string>
         */
        public static function spellingsOf(\Symfony\Component\HttpFoundation\Request $request): array
        {
            $matched = (defined('IDNO_SUBDIRECTORY') ? '/' . IDNO_SUBDIRECTORY : '') . $request->getPathInfo();
            $spellings = [$matched, rawurldecode($matched), static::toroPath($request)];
            foreach ($spellings as $path) {
                $spellings[] = preg_replace('~/{2,}~', '/', $path) ?? $path;
            }

            return array_values(array_unique($spellings));
        }

        /**
         * The pages the legacy router would choose for this request, under every spelling of its path (`spellingsOf()`), by its own lookup
         * (`handlerFor()`: the exact key first, then the patterns in registration order, Toro's algorithm over the whole table, pinned by
         * `RouteLookupTest`): each as a class name (see `chosenFor()`) with whether it serves the request as the Markdown form of a permalink
         * (`routeMarkdownSuffix()`: for a GET or HEAD whose path ends in `.md`, the path as given matches a route whose page is a `MarkdownPermalink`, or
         * matches none and without the suffix it does).
         *
         * The table is what it was before any page moved to a controller (core registered the page's pattern where it always did, with a placeholder
         * for the class: `\Idno\Legacy\ControllerRoute`), so the answer is what the legacy router has always answered, whoever registered the pattern
         * and whenever: a plugin's `addRoute()` of the identical pattern, `hijackRoute()`, an exact key, a pattern registered before core's.
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
                    $markdown = $stripped !== null && is_a($class, \Idno\Common\MarkdownPermalink::class, true);
                    $claims[$class . '|' . (int) $markdown] = ['class' => $class, 'markdown' => $markdown];
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

            // (a path without the suffix is no Markdown request: the table is not asked, Toro asks it once)
            if (\Idno\Markdown\MarkdownRequest::pathWithoutSuffix($path) === null) {
                return;
            }

            $routed = static::routedFor($routes, (string) ($_SERVER['REQUEST_METHOD'] ?? ''), $path);
            if ($routed !== null && $routed['markdown']) {
                self::$markdown_suffix = true;
                if ($routed['path'] !== $path) {
                    // Toro reads PATH_INFO first
                    $_SERVER['PATH_INFO'] = $routed['path'];
                }
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
            static::beginStatelessRequestIfTheShellTakesNoFormToken($routes);

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

        /**
         * A request the router is about to serve with a kept shell whose controller action takes state-changing requests without the form token
         * (`#[SitePage(formTokenReplacedBy: ...)]`) is made nobody's, ONCE, before the router builds the page (so `page/ready` never sees the session's user), as
         * the controller's path does; GET, HEAD and OPTIONS keep the browser's user. The page is the one Toro will choose (`handlerFor()` is Toro's lookup).
         * A shell built for any other purpose (`getRoute()`, a `new`) changes nothing.
         *
         * @param array $routes
         */
        private static function beginStatelessRequestIfTheShellTakesNoFormToken($routes): void
        {
            $handler = static::handlerFor($routes, (string) static::pathFrom($_SERVER['PATH_INFO'] ?? null, $_SERVER['ORIG_PATH_INFO'] ?? null, $_SERVER['REQUEST_URI'] ?? null));
            if (is_string($handler) && is_subclass_of($handler, \Idno\Legacy\PageShell::class) && $handler::takesNoFormToken(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')))) {
                \Idno\Core\Idno::site()->session()->beginStatelessRequest();
            }
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
                return $page->publicOnPrivateSite || $this->isRoutePublic($page->name);
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

