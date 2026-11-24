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

        /// Whether the request being served asked for a post's Markdown form with the `.md` suffix
        private static $markdown_suffix = false;

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
         * @param  string|object $class
         * @return bool
         */
        function isAccountPage($class) : bool
        {
            if (is_object($class)) {
                $class = get_class($class);
            }

            return is_string($class) && isset($this->account_pages[strtolower(ltrim($class, '\\'))]);
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
            $path = '/';
            if (!empty($_SERVER['PATH_INFO'])) {
                $path = $_SERVER['PATH_INFO'];
            } else if (!empty($_SERVER['ORIG_PATH_INFO']) && $_SERVER['ORIG_PATH_INFO'] !== '/index.php') {
                $path = $_SERVER['ORIG_PATH_INFO'];
            } else if (!empty($_SERVER['REQUEST_URI'])) {
                $path = (strpos($_SERVER['REQUEST_URI'], '?') > 0) ? strstr($_SERVER['REQUEST_URI'], '?', true) : $_SERVER['REQUEST_URI'];
            }
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
                        (new \Idno\Pages\Homepage())->deniedContent();
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
         * Does the specified page handler class represent a public page, even on walled gardens?
         *
         * @param  $class
         * @return bool
         */
        function isRoutePublic(string $class) : bool
        {
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

