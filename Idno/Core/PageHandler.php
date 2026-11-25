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
         * Serve the current request. An API request (one authenticated by an API signature
         * or an access token rather than by a browser session) is refused on account pages,
         * so that an API key or token is not enough to take the account over.
         *
         * @param array $routes
         */
        public static function serve($routes)
        {
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
            // As addRoute() does: a site in a subdirectory is routed with it in the pattern
            if (defined('IDNO_SUBDIRECTORY')) {
                if (substr($pattern, 0, 1) != '/') {
                    $pattern = '/' . $pattern;
                }
                $pattern = '/' . IDNO_SUBDIRECTORY . $pattern;
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

