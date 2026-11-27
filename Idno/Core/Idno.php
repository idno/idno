<?php

    /**
     * Base Idno class
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    use Idno\Common\Page;
    use Idno\Entities\User;

    class Idno extends \Idno\Common\Component
    {
        // Install Idno deprecated functions
        use Deprecated\Idno;

        private $db;
        private $filesystem;
        private $config;
        private $session;
        private $template;
        private $language;
        private $actions;
        private $plugins;
        private $dispatcher;
        private $queue;
        private $routes;
        private $syndication;
        /* @var \Psr\Log\LoggerInterface $logging */
        private $logging;
        /* @var \Idno\Core\Idno $site */
        private static $site;
        /* @var \Psr\Container\ContainerInterface $container */
        private static $container;
        private $currentPage;
        private $cache;
        private $statistics;
        private $site_details;

        function __construct()
        {
            parent::__construct();
            // auth the user after all the plugins and pages have registered so they can respond to events
            $this->session()->tryAuthUser();
            $this->themes()->announceRetiredTheme();
            $this->upgrade();
        }

        function init()
        {
            self::$site       = $this;
            $this->routes     = self::container()->get(PageHandler::class);
            $this->dispatcher = self::container()->get(EventDispatcher::class);
            $this->config     = self::container()->get(Config::class);
            if ($this->config->isDefaultConfig()) {
                // Idno\Legacy\LegacyBootstrap checks this before constructing anything; the
                // entry points send the redirect to the installer.
                throw new \Idno\Legacy\NotInstalledException('Idno is not installed: no config.ini or config.json was found.');
            }

            // We need to load initial values from the .ini files
            $this->config()->loadIniFiles();

            // Services are built by Idno\Legacy\LegacyServiceFactory when first fetched.
            $this->db         = self::container()->get(DataConcierge::class);
            $this->filesystem = self::container()->get(\Idno\Files\FileSystem::class);

            $this->logging = self::container()->get(Logging::class);
            try {
                $this->config->load();
            } catch (\Throwable $e) {
                error_log('Idno: Failed to load config from database: ' . $e->getMessage());
            }

            if (isset($this->config->loglevel) && $this->logging instanceof Logging) {
                $this->logging->setLogLevel($this->config->loglevel);
            }

            $this->session      = self::container()->get(Session::class);
            $this->actions      = self::container()->get(Actions::class);
            $this->site_details = $this->site_details();
            $this->template     = self::container()->get(Template::class);
            $this->language     = self::container()->get(Language::class);
            $this->language()->register(new GetTextTranslation()); // Register the core translations (domain idno)
            $this->syndication  = self::container()->get(Syndication::class);
            $this->queue        = self::container()->get(EventQueue::class);
            $this->statistics   = self::container()->get(\Idno\Stats\StatisticsCollector::class);

            // Log some page statistics
            \Idno\Stats\Timer::start('script');
            register_shutdown_function(
                function () {
                    $stats = \Idno\Core\Idno::site()->statistics();
                    if (!empty($stats)) {
                        $stats->timing('timer.script', \Idno\Stats\Timer::value('script'));
                    }
                }
            );

            // The cache is the old cache API over the site's one cache pool
            $this->cache = self::container()->get(\Idno\Caching\PersistentCache::class);

            // No URL is a critical error, default base fallback is now a warning (Refs #526)
            if (!defined('IDNO_CONSOLE')) {
                if (!$this->config->url) {
                    throw new \Idno\Exceptions\ConfigurationException('Idno was unable to work out your base URL! You might try setting url="http://yourdomain.com/" in your config.ini');
                }
                if ($this->config->url == '/') {
                    $this->logging->warning(
                        'Base URL has defaulted to "/" because Idno was unable to detect your server name. '
                        . 'This may be because you\'re loading Idno via a script. '
                        . 'Try setting url="http://yourdomain.com/" in your config.ini to remove this message'
                    );
                }
            }

            User::registerEvents();
        }

        /**
         * Registers some core page URLs
         */
        function registerPages()
        {
            $permalink_route = \Idno\Common\Entity::getPermalinkRoute();

            /**
             * Homepage
            */
            $this->routes()->addRoute('/?', '\Idno\Pages\Homepage');
            // (the feeds, search, tags, small files and the token endpoint are served by controllers: core's registrations stay where they were, held by a
            // placeholder, so that the legacy router's rules still decide what a plugin's registration does to them: see \Idno\Legacy\ControllerRoute)
            $this->routes()->addRoute('/feed\.xml', \Idno\Legacy\ControllerRoute::class);
            $this->routes()->addRoute('/feed/?', \Idno\Legacy\ControllerRoute::class);
            $this->routes()->addRoute('/rss\.xml', \Idno\Legacy\ControllerRoute::class);
            $this->routes()->addRoute('/content/([A-Za-z\-\/]+)+', '\Idno\Pages\Homepage');

            /**
             * Individual entities / posting / deletion
            */
            $this->routes()->addRoute('/view/:id/?', '\Idno\Pages\Entity\View');
            $this->routes()->addRoute('/s/:id/?', \Idno\Legacy\ControllerRoute::class);
            $this->routes()->addRoute($permalink_route . '/?', '\Idno\Pages\Entity\View');
            $this->routes()->addRoute('/edit/:id/?', '\Idno\Pages\Entity\Edit');
            $this->routes()->addRoute('/delete/:id/?', '\Idno\Pages\Entity\Delete');
            $this->routes()->addRoute('/withdraw/:id/?', '\Idno\Pages\Entity\Withdraw');

            $this->routes()->addRoute('/attachment/:id/:id/?', '\Idno\Pages\Entity\Attachment\Delete');

            /**
             * Annotations
            */
            // (the pages of a post's annotations are served by controllers: core's registrations stay where they were, held by placeholders that name the
            // controller actions, so that the legacy router's rules still decide what a plugin's registration does to them)
            $this->routes()->addRoute('/view/:id/annotations/:id?', \Idno\Legacy\Placeholder\AnnotationView::class);
            $this->routes()->addRoute($permalink_route . '/annotations/:id?', \Idno\Legacy\Placeholder\AnnotationView::class);
            $this->routes()->addRoute($permalink_route . '/annotations/:id/delete/?', \Idno\Legacy\Placeholder\AnnotationDelete::class); // Delete annotation
            $this->routes()->addRoute($permalink_route .'/annotation/delete/?', \Idno\Legacy\Placeholder\AnnotationDelete::class); // Delete annotation alternate
            $this->routes()->addRoute('/annotation/post/?', '\Idno\Pages\Annotation\Post');
            // the other layouts a site could choose for a post's address, before it had one: a post's address, and the address of one of its annotations, are
            // sent to the address they have now. After the annotation routes, so that an address of the one layout is never taken for another's (the list of
            // the annotations of a post whose slug is two digits, `/2026/12/annotations/`, is the annotation route's)
            $this->routes()->addRoute('/[0-9]{4}/[0-9]{2}/([^/.]+)/?', \Idno\Legacy\Placeholder\PermalinkOtherLayout::class);
            $this->routes()->addRoute('/[0-9]{4}/[0-9]{2}/[0-9]{2}/([^/.]+)/?', \Idno\Legacy\Placeholder\PermalinkOtherLayout::class);
            $this->routes()->addRoute('/[0-9]{4}/[0-9]{2}/([^/.]+)/annotations/:id?', \Idno\Legacy\Placeholder\AnnotationOtherLayout::class);
            $this->routes()->addRoute('/[0-9]{4}/[0-9]{2}/[0-9]{2}/([^/.]+)/annotations/:id?', \Idno\Legacy\Placeholder\AnnotationOtherLayout::class);

            /**
             * Bookmarklets and sharing
            */
            $this->routes()->addRoute('/share/?', '\Idno\Pages\Entity\Share');

            /**
             * Mobile integrations
            */
            $this->routes()->addRoute('/chrome/manifest\.json', \Idno\Legacy\ControllerRoute::class);

            /**
             * Service worker
            */
            $this->routes()->addRoute('/service-worker(\.min)?\.js', \Idno\Legacy\ControllerRoute::class);

            /**
             * Files
            */
            $this->routes()->addRoute('/file/mint/?', \Idno\Pages\File\Mint::class);
            $this->routes()->addRoute('/file/upload/?', '\Idno\Pages\File\Upload', true);
            $this->routes()->addRoute('/file/picker/?', '\Idno\Pages\File\Picker', true);
            $this->routes()->addRoute('/filepicker/?', '\Idno\Pages\File\Picker', true);
            $this->routes()->addRoute('/file/(:id)(/.*)?', \Idno\Legacy\ControllerRoute::class, true);

            /**
             * Users
            */
            $this->routes()->addRoute('/profile/([^\/]+)/?', '\Idno\Pages\User\View');
            $this->routes()->addRoute('/profile/([^\/]+)/edit/?', '\Idno\Pages\User\Edit');
            $this->routes()->addRoute('/profile/([^\/]+)/([A-Za-z\-\/]+)+', '\Idno\Pages\User\View');
            $this->routes()->addRoute('/actor/([^\/]+)/?', '\Idno\Pages\User\View');

            /**
             * Search
            */
            $this->routes()->addRoute('/search/?', \Idno\Legacy\ControllerRoute::class);
            $this->routes()->addRoute('/search/mentions\.json', \Idno\Legacy\ControllerRoute::class);
            $this->routes()->addRoute('/tag/([^\s]+)\/?', \Idno\Legacy\ControllerRoute::class);
            $this->routes()->addRoute('/search/users/?', '\Idno\Pages\Search\User');

            /**
             * robots.txt
            */
            $this->routes()->addRoute('/robots\.txt', \Idno\Legacy\ControllerRoute::class);

            /**
             * Autosave / preview
            */
            $this->routes()->addRoute('/autosave/?', '\Idno\Pages\Entity\Autosave');

            /**
             * The member's drafts
            */
            $this->routes()->addRoute('/drafts/?', \Idno\Legacy\ControllerRoute::class);

            /**
             * Add some services
            */
            $this->routes()->addRoute('/service/db/optimise/?', '\Idno\Pages\Service\Db\Optimise');
            $this->routes()->addRoute('/service/security/csrftoken/?', \Idno\Legacy\ControllerRoute::class);
            $this->routes()->addRoute('/service/system/log/?', '\Idno\Pages\Service\System\Log');
            $this->routes()->addRoute('/service/geo/geocoder/?', '\Idno\Pages\Service\Geo\Geocoder');
            // Shared inbox route is registered by the ActivityPub plugin

            // These must be loaded last
            $this->plugins = self::container()->get(Plugins::class);
            $this->themes  = self::container()->get(Themes::class);
        }

        /**
         * Return the database layer loaded as part of this site
         *
         * @return \Idno\Core\DataConcierge
         */
        function &db() : ?DataConcierge
        {
            return $this->db;
        }

        /**
         * Return the event dispatcher loaded as part of this site
         *
         * @return \Idno\Core\EventDispatcher
         */
        function &events() : ?EventDispatcher
        {
            return $this->dispatcher;
        }

        /**
         * Access to the EventQueue for dispatching events
         * asynchronously
         *
         * @return \Idno\Core\EventQueue
         */
        function &queue() : ?EventQueue
        {
            return $this->queue;
        }

        /**
         * Returns the current filesystem
         *
         * @return \Idno\Files\FileSystem
         */
        function &filesystem() : ? \Idno\Files\FileSystem
        {
            return $this->filesystem;
        }

        /**
         * Returns the current logging interface
         *
         * @return \Idno\Core\Logging
         */
        function &logging() : ?Logging
        {
            return $this->logging;
        }

        /**
         * Return a persistent cache object.
         *
         * @return \Idno\Caching\PersistentCache
         */
        function &cache() : ?\Idno\Caching\PersistentCache
        {
            return $this->cache;
        }

        /**
         * Return a statistics collector
         *
         * @return \Idno\Stats\StatisticsCollector
         */
        function &statistics() : ?\Idno\Stats\StatisticsCollector
        {
            return $this->statistics;
        }

        /**
         * Return page handlers
         *
         * @return \Idno\Core\PageHandler
         */
        function &routes() : ?PageHandler
        {
            return $this->routes;
        }

        /**
         * Helper function that returns the current configuration object
         * for this site (or a configuration setting value)
         *
         * @param The configuration setting value to retrieve (optional)
         *
         * @return \Idno\Core\Config|array
         */
        function &config($setting = false)
        {
            if ($setting === false) {
                return $this->config;
            } else {
                return $this->config->$setting;
            }
        }

        /**
         * Helper function that returns the current syndication object for this site
         *
         * @return \Idno\Core\Syndication
         */
        function &syndication() : ?Syndication
        {
            return $this->syndication;
        }

        /**
         * Return the session handler associated with this site
         *
         * @return \Idno\Core\Session
         */
        function &session() : ?Session
        {
            return $this->session;
        }

        /**
         * Return the plugin handler associated with this site
         *
         * @return \Idno\Core\Plugins
         */
        function &plugins() : ?Plugins
        {
            return $this->plugins;
        }

        /**
         * Return the theme handler associated with this site
         *
         * @return \Idno\Core\Themes
         */
        function &themes() : ?Themes
        {
            return $this->themes;
        }

        /**
         * Return the template handler associated with this site
         *
         * @return \Idno\Core\Template
         */

        function &template() : ?Template
        {
            return $this->template;
        }

        /**
         * Return the language handler associated with this site
         *
         * @return \Idno\Core\Language
         */
        function &language() : ?Language
        {
            if (empty($this->language)) {
                $this->language = new Language();
            }

            return $this->language;
        }

        /**
         * Return the action helper associated with this site
         *
         * @return \Idno\Core\Actions
         */
        function &actions() : ?Actions
        {
            return $this->actions;
        }

        /**
         * Return the site object for the current site, creating a new entry if one doesn't exist.
         */
        function &site_details() : ? Site
        {
            if (empty($this->site_details)) {

                $domain = $this->config()->host;

                if (!empty($domain) && !empty($this->session())) {

                    $this->site_details = Site::getOne([ 'domain' => $domain ]);

                    // A console process (the queue's worker, a command) takes its host from the boot `url`, which
                    // may not be the host visitors use (example.com, www.example.com): it never creates a site
                    // record. It uses the site's record when there is exactly one, else it has none.
                    if (empty($this->site_details) && defined('IDNO_CONSOLE')) {
                        $sites = Site::get([], [], 2);
                        $this->site_details = count($sites) === 1 ? $sites[0] : null;

                        return $this->site_details;
                    }

                    if (empty($this->site_details)) {

                        $this->site_details = new Site();
                        $this->site_details->domain = $domain;
                        $this->site_details->save();

                        if (empty($this->site_details)) {
                            throw new \Idno\Exceptions\TechnicalException($this->language()->_('Site entity for "%s" could not be created', [$domain]));
                        }
                    }
                }
            }

            return $this->site_details;
        }

        /**
         * Sets the current page (if any) for access throughout the system
         *
         * @param \Idno\Common\Page $page
         */
        function setCurrentPage(\Idno\Common\Page $page)
        {
            $this->currentPage = $page;
        }

        /**
         * Retrieve the current page
         *
         * @return bool|\Idno\Common\Page
         */
        function currentPage()
        {
            if (!empty($this->currentPage)) {
                return $this->currentPage;
            }

            return false;
        }

        /**
         * Can a specified user (either an explicitly specified user ID
         * or the currently logged-in user if this is left blank) edit
         * this entity?
         *
         * This essentially means 'can the user edit configuration about the site', generally only admins can do this.
         *
         * @param  string $user_id
         * @return true|false
         */
        function canEdit($user_id = '')
        {
            if (!\Idno\Core\Idno::site()->session()->isLoggedOn()) {
                return false;
            }

            if (empty($user_id)) {
                $user_id = \Idno\Core\Idno::site()->session()->currentUserUUID();
            }

            if ($user = \Idno\Entities\User::getByUUID($user_id)) {

                return \Idno\Core\Idno::site()->events()->triggerEvent(
                    'canEdit/site', [
                    'object' => $this,
                    'user_id' => $user_id,
                    'user' => $user
                    ], (function () use ($user) {

                        if ($user->isAdmin()) {
                            return true;
                        }

                        return false;
                    })()
                );

            }

            return false;
        }

        /**
         * Can a specified user (either an explicitly specified user ID
         * or the currently logged-in user if this is left blank) publish
         * content on the site?
         *
         * @param  string $user_id
         * @return true|false
         */
        function canWrite($user_id = '')
        {
            if (!\Idno\Core\Idno::site()->session()->isLoggedOn()) {
                return false;
            }

            if (empty($user_id)) {
                $user_id = \Idno\Core\Idno::site()->session()->currentUserUUID();
            }

            if ($user = \Idno\Entities\User::getByUUID($user_id)) {

                // Make site level canWrite extensible
                return \Idno\Core\Idno::site()->events()->triggerEvent(
                    'canWrite/site', [
                    'object' => $this,
                    'user_id' => $user_id,
                    'user' => $user
                    ], (function () use ($user) {

                        // Remote users can't ever create anything :( - for now
                        if ($user instanceof \Idno\Entities\RemoteUser) {
                            return false;
                        }

                        // But local users can
                        if ($user instanceof \Idno\Entities\User) {
                            if (empty($user->read_only)) {
                                return true;
                            }
                        }

                        return false;

                    })()
                );
            }

            return false;
        }

        /**
         * Can a specified user (either an explicitly specified user ID
         * or the currently logged-in user if this is left blank) view
         * this entity?
         *
         * Always returns true at the moment, but might be a good way to build
         * walled garden functionality.
         *
         * @param  string $user_id
         * @return true|false
         */
        function canRead($user_id = '')
        {
            return true;
        }

        /**
         * Retrieve site icons.
         * Retrieve a set of one or more icon for the current site, allowing plugins and other components
         * access icons for displaying in various contexts
         *
         * @returns array An associative array of various icons => url
         */
        function getSiteIcons() : array
        {
            $icons = [];

            // Set our defaults (TODO: Set these cleaner, perhaps through the template system)
            $icons['defaults'] = [
                'default'     => \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/logos/logo_k.png',
                'default_16'  => \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/logos/logo_k_16.png',
                'default_32'  => \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/logos/logo_k_32.png',
                'default_36'  => \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/logos/logo_k_36.png',
                'default_48'  => \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/logos/logo_k_48.png',
                'default_64'  => \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/logos/logo_k_64.png',
                'default_96'  => \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/logos/logo_k_96.png',

                // Apple logos
                'default_57'  => \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/logos/apple-icon-57x57.png',
                'default_72'  => \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/logos/apple-icon-72x72.png',
                'default_114' => \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/logos/apple-icon-114x114.png',
                'default_144' => \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/logos/apple-icon-144x144.png',

                'default_192' => \Idno\Core\Idno::site()->config()->getStaticURL() . 'gfx/logos/logo_k_192.png',
            ];

            // If we're on a page, see if that has a specific icon
            if ($page = \Idno\Core\Idno::site()->currentPage()) {
                if ($page_icons = $page->getIcon()) {
                    $icons['page'] = $page_icons;
                }
            }

            // Now, return a list of icons, but pass it through an event hook to override
            return $this->events()->triggerEvent('site/icons', ['object' => $this], $icons);
        }

        /**
         * Is this site being run in embedded mode? Hides the navigation bar, maybe more.
         *
         * @return bool
         */
        function embedded()
        {
            if (site()->currentPage()->getInput('unembed')) {
                $_SESSION['embedded'] = false;

                return false;
            }
            if (!empty($_SESSION['embedded'])) {
                return true;
            }
            if (site()->currentPage()->getInput('embedded')) {
                $_SESSION['embedded'] = true;

                return true;
            }

            return false;
        }

        /**
         * Apply updates.
         */
        function upgrade()
        {
            $last_update = 0;
            if (!empty($this->config()->update_version)) {
                $last_update = $this->config()->update_version;
            }
            $machine_version = Version::build();

            if ($last_update < $machine_version) {

                if ($this->events()->triggerEvent(
                    'upgrade', [
                    'last_update' => $last_update,
                    'new_version' => $machine_version
                    ]
                )
                ) {

                    // Save updated
                    $this->config()->update_version = $machine_version;
                    $this->config()->save();

                    $this->logging()->info("Idno upgraded from $last_update to $machine_version");
                } else {
                    $this->logging()->error("There was a problem applying an update.");
                }
            }
        }

        /**
         * This is a state dependant object, and so can not be serialised.
         *
         * @return array
         */
        function __sleep()
        {
            return [];
        }

        /**
         * Helper method that returns the current site object
         *
         * @return \Idno\Core\Idno $site
         */
        static function &site() : ?Idno
        {
            return self::$site;
        }

        /**
         * Sets the service container the legacy services are fetched from.
         * Called by \Idno\Legacy\LegacyBootstrap before the site is constructed.
         *
         * @param \Psr\Container\ContainerInterface $c
         */
        public static function setContainer(\Psr\Container\ContainerInterface $c): void
        {
            self::$container = $c;
        }

        /**
         * Returns the service container. New code should have its services injected
         * rather than fetching them from here.
         *
         * @return \Psr\Container\ContainerInterface
         */
        public static function container(): \Psr\Container\ContainerInterface
        {
            if (self::$container === null) {
                throw new \LogicException('The service container has not been set: boot Idno through \Idno\Kernel.');
            }

            return self::$container;
        }

        /**
         * Is an import running in this process? What an import brings in is history, not news: while it
         * runs, nothing is federated, syndicated, sent as a webmention or pinged to a hub for it. Every place
         * that would announce a post asks this (Idno\Portability\ImportMode is the flag).
         *
         * @return bool
         */
        public static function importInProgress(): bool
        {
            return self::container()->get(\Idno\Portability\ImportMode::class)->active();
        }

        /**
         * Attempt to construct a component.
         * This allows for config configurable, and plugin extensible, system conponents, without the need for a lot of repeat typing.
         *
         * @param string $className            Class name of component, either partial or full namespace
         * @param string $expectedBaseClass    Class type to verify newly created component against
         * @param string $defaultClassNameBase If a full namespace is not provided in $configValue, use this value as base class namespace
         * @param string $defaultClass         If class could not be constructed, return a new instance of this class name
         */
        public function componentFactory($className, $expectedBaseClass = "Idno\\Common\\Component" , $defaultClassNameBase = "Idno\\Core\\", $defaultClass = null)
        {

            $component = null;

            // Try full namespace
            if (!empty($className) && class_exists($className)) {
                if (is_subclass_of($className, $expectedBaseClass)) {
                    $class = $className;
                }
            }

            // Attempt base class creation
            if (empty($class)) {
                if (class_exists($defaultClassNameBase . $className)) {
                    $class = $defaultClassNameBase . $className;
                }
            }

            // Now try and create it
            if (!empty($class)) {
                if (is_subclass_of($class, $expectedBaseClass)) {
                    $component = new $class();
                }
            }

            // Do we have a class yet? otherwise try a default
            if (empty($component)) {

                if (!empty($defaultClass)) {

                    if (is_string($defaultClass)) {
                        $component = new $defaultClass();
                    } else {
                        $component = $defaultClass;
                    }

                    // validate
                    if (!is_subclass_of($component, $expectedBaseClass)) {
                            $component = null;
                    }
                }
            }

            return $component;
        }

        /**
         * Get the current version
         *
         * @return boolean|string
         */
        public function getVersion()
        {
            return Version::version();
        }
    }

    /**
     * Helper function that returns the current site object
     *
     * @deprecated Use \Idno\Core\Idno::site()
     * @return     \Idno\Core\Idno $site
     */
    function &site() : Idno
    {
        return \Idno\Core\Idno::site();
    }
}
