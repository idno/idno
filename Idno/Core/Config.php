<?php

    /**
     * Configuration management class
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    class Config extends \Idno\Common\Component
    {

        public $config = array(
            'database'               => 'mysql',
            'dbname'                 => 'idno', // Default database name
            'sessionname'            => 'idno', // Default session name
            'boolean_search'         => true, // Should search be boolean?
            'open_registration'      => true, // Can anyone register for this system?
            'initial_plugins'        => array('Status', 'Text', 'Photo', 'IndiePub', 'ActivityPub'),
            'plugins'                => array(), // Default plugins
            'assets'                 => [      // Assets to be included
                                               'mediaelementplayer' => true,
                                               'fitvids'            => true,
            ],
            'themes'                 => array(),
            'antiplugins'            => array(),
            'alwaysplugins'          => array(),
            'prerequisiteplugins'    => array(),
            'directloadplugins'      => array(),
            'hiddenthemes'           => array(),
            'hiddenplugins'          => array(),
            'items_per_page'         => 10, // Default items per page
            'experimental'           => false, // A common way to enable experimental functions still in development
            'default_config'         => true, // This is a trip-switch - changed to false if configuration is loaded from an ini file / the db
            'loglevel'               => 5,
            'multi_syndication'      => true,
            'wayback_machine'        => false,
            'static_url'             => false,
            'user_avatar_favicons'   => true,
            'form_token_expiry'      => 3600,
            'show_privacy'           => true,
            'bypass_fulltext_search' => false,
            'permalink_structure'    => '/:year/:slug',
            'single_user'            => true,
            'pedantic_mode'          => false, // When true, PHP errors (including notices) will throw exceptions.
        );

        public $ini_config = array();

        /**
         * Configuration pinned by config.ini, config.json and the environment.
         *
         * @var \Idno\Config\BootConfig
         */
        protected $bootConfig;

        /**
         * @param \Idno\Config\BootConfig|null $bootConfig Boot configuration; when null it is read from the
         *                                                  project directory, $_SERVER and $_ENV.
         */
        function __construct(?\Idno\Config\BootConfig $bootConfig = null)
        {
            // Must be set before the parent constructor, which calls init() and so loadIniFiles().
            $this->bootConfig = $bootConfig ?? (new \Idno\Config\LegacyConfigLoader())->load(
                dirname(dirname(dirname(__FILE__))),
                $_SERVER,
                $_ENV
            );
            parent::__construct();
        }

        /**
         * We're overloading the "get" method for the configuration
         * class, so you can simply check $config->property to get
         * a configuration value.
         */

        function &__get($name)
        {
            if ($name == 'config') {
                return $this->config;
            }

            return $this->config[$name];
        }

        /**
         * Overloading the "set" method for the configuration class,
         * so you can simply set $configuration->property = $value to
         * overwrite configuration values.
         */

        function __set($name, $value)
        {
            if ($name == 'config') {
                $this->config = $value;
            }

            return $this->config[$name] = $value;
        }

        /**
         * Overloading the entity property isset check, so that
         * isset($entity->property) and empty($entity->property)
         * work as expected.
         */

        function __isset($name)
        {
            if (!empty($this->config[$name])) {
                return true;
            }

            return false;
        }

        /**
         * Retrieves configuration information from the database, if possible - while
         * ensuring that config.ini overwrites db fields.
         */
        function load()
        {
            if (empty($config)) {
                $config = \Idno\Core\Idno::site()->db()->getAnyRecord('config');
            }
            if ($config) {
                $this->default_config = false;
                unset($config['dbname']); // Ensure we don't accidentally load protected data from db
                unset($config['dbpass']);
                unset($config['dbhost']);
                unset($config['dbstring']);
                unset($config['path']);
                unset($config['url']);
                unset($config['host']);
                unset($config['feed']);
                unset($config['uploadpath']);
                unset($config['antiplugins']);
                unset($config['alwaysplugins']);
                unset($config['session_path']);
                unset($config['session_hash_function']);
                unset($config['sessions_database']);
                unset($config['sessions_storage']);
                unset($config['cookie_jar']);
                unset($config['proxy_string']);
                unset($config['proxy_type']);
                unset($config['disable_ssl_verify']);
                unset($config['upload_tmp_dir']);
                unset($config['bypass_fulltext_search']);
                $this->config = array_replace_recursive($this->config, $config);

                // Crufty hack to allow plugins to be toggled - loaded plugins ALWAYS take precidence (#1427)
                if (!empty($config['plugins'])) {
                    $this->config['plugins'] = $config['plugins'];
                }
            } else {
                // If we don't have a saved config, this is a new site. Set some plugin defaults
                if (!is_array($this->config)) {
                    $this->config = [];
                }
                if (!is_array($this->config['plugins'])) {
                    $this->config['plugins'] = [];
                }
                $this->config['plugins'][] = 'Status';
                $this->config['plugins'][] = 'Text';
                $this->config['plugins'][] = 'Photo';
                $this->config['plugins'][] = 'IndiePub';
            }

            $this->loadIniFiles();

            $this->config['plugins'] = array_unique($this->config['plugins']);

            // If we don't have a site secret, create it
            if (!isset($this->site_secret)) {
                $token_generator   = new TokenProvider();
                $this->site_secret = bin2hex($token_generator->generateToken(64));
                $this->save();
            }
        }

        /**
         * Load configuration from the boot configuration (config.ini, config.json
         * and environment variables, read by \Idno\Config\LegacyConfigLoader).
         */
        function loadIniFiles()
        {
            $this->ini_config = $this->bootConfig->all();

            if (!$this->bootConfig->isDefault()) {
                $this->default_config = false;
            }

            if (!empty($this->ini_config)) {
                $this->config = array_replace_recursive($this->config, $this->ini_config);
            }
        }

        /**
         * Saves configuration information to the database, if possible.
         *
         * @return true|false
         */
        function save()
        {
            $array = $this->config;
            unset($array['dbname']); // Don't save database name
            unset($array['dbpass']);
            unset($array['dbhost']);
            unset($array['dbstring']);
            unset($array['path']); // Don't save the file path to the database
            unset($array['url']); // Don't save the URL to the database
            unset($array['host']); // Don't save the host to the database
            unset($array['feed']); // Don't save the feed URL to the database
            unset($array['uploadpath']); // Don't save the upload path to the database
            unset($array['session_path']); // Don't save the session path in the database
            unset($array['session_hash_function']); // Don't save the session hash to database, we want the ability to upgrade
            unset($array['sessions_database']); // Don't want to save sessions in database
            unset($array['sessions_storage']); // Don't want to save sessions storage in db
            unset($array['cookie_jar']); // Don't save the cookie path in the database
            unset($array['proxy_string']);
            unset($array['proxy_type']);
            unset($array['disable_ssl_verify']);
            unset($array['directloadplugins']);
            unset($array['bypass_fulltext_search']);
            unset($array['filter_shell']);

            /* $array['_id'] = 'config'; // Hardcode configuration ID */ // MP: Removed as it is not compatible with every backend. If hard coded IDs are absolutely necessary, another way needs to be found.

            if (\Idno\Core\Idno::site()->db()->saveRecord('config', $array)) {
                $this->init();
                $this->load();

                return true;
            }

            $this->init();
            $this->load();

            return false;
        }

        function init()
        {
            // Load the config.ini file in the root folder, if it exists.
            // If not, we'll use default values. No skin off our nose.
            $this->path                      = dirname(dirname(dirname(__FILE__))); // Base path
            $this->url                       = $this->detectBaseURL();
            $this->static_url                = false;
            $this->title                     = 'New Idno site'; // A default name for the site
            $this->description               = 'A social website powered by Idno'; // Default description
            $this->timezone                  = 'UTC';
            $this->host                      = parse_url($this->url, PHP_URL_HOST); // The site hostname, without parameters etc
            $this->feed                      = $this->getDisplayURL() . 'content/all/?_t=rss';
            $this->indieweb_citation         = false;
            $this->indieweb_reference        = false;
            $this->hub                       = 'https://withknown.superfeedr.com/';
            $this->session_path              = session_save_path(); // Session path when not storing sessions in the database
            $this->session_hash_function     = 'sha256'; // Default hash function
            $this->sessions_database         = true; // Let the database handle the session
            $this->disable_cleartext_warning = false; // Set to true to disable warning when access credentials are sent in the clear
            $this->multi_syndication         = true; // Do we allow multiple accounts per syndication endpoint?
            $this->wayback_machine           = false; // Automatically ping new pages on public sites to the Internet Archive

            $this->loadIniFiles();
            $this->sanitizeValues();

            if (substr($this->host, 0, 4) == 'www.') {
                $this->host = substr($this->host, 4);
            }

            if (!empty($this->initial_plugins)) {
                if (!empty($this->default_plugins)) {
                    $this->default_plugins = array_merge($this->default_plugins, $this->initial_plugins);
                } else {
                    $this->default_plugins = $this->initial_plugins;
                }
            }
            $this->default_plugins = array_unique($this->default_plugins);
            if (!empty($this->default_plugins)) {
                $this->plugins = $this->default_plugins;
            }

            date_default_timezone_set($this->timezone);
        }

        /**
         * Attempt to detect your idno configuration's server name.
         */
        protected function detectBaseURL()
        {

            // Otherwise, use the standard server name header
            if (!empty($_SERVER['SERVER_NAME'])) {

                // Servername specified, so we can construct things in the normal way.
                $url = (\Idno\Common\Page::isSSL() ? 'https://' : 'http://') . $_SERVER['SERVER_NAME'];
                if (!empty($_SERVER['HTTP_X_FORWARDED_PORT'])) {
                    if ($_SERVER['HTTP_X_FORWARDED_PORT'] != 80 && $_SERVER['HTTP_X_FORWARDED_PORT'] != 443) {
                        $url .= ':' . $_SERVER['HTTP_X_FORWARDED_PORT'];
                    }
                } else if (!empty($_SERVER['SERVER_PORT'])) {
                    if ($_SERVER['SERVER_PORT'] != 80 && $_SERVER['SERVER_PORT'] != 443) {
                        $url .= ':' . $_SERVER['SERVER_PORT'];
                    }
                }
                if (defined('IDNO_SUBDIRECTORY')) {
                    $url .= '/' . IDNO_SUBDIRECTORY;
                }
                $url .= '/'; // A naive default base URL
                return $url;
            }

            $domain = getenv('IDNO_DOMAIN') ?: getenv('KNOWN_DOMAIN');
            if (!empty($domain)) {
                // Server domain specified in environment variable, for cases when SERVER_NAME isn't specified.
                // This allows things like the console plugins to access the correct site when using domain
                // specific configurations.

                $url = (\Idno\Common\Page::isSSL() ? 'https://' : 'http://') . $domain;
                $port = getenv('IDNO_PORT') ?: getenv('KNOWN_PORT');
                if (!$port) {
                    $port = 80;
                }

                if ($port != 80 && $port != 443) {
                    $url .= ':' . $port;
                }

                if (defined('IDNO_SUBDIRECTORY')) {
                    $url .= '/' . IDNO_SUBDIRECTORY;
                }
                $url .= '/'; // A naive default base URL
                return $url;
            }

            // No servername set, try something else
            // TODO: Detect servername using other methods (but don't use HTTP_HOST)

            // Default to root relative urls
            return '/';
        }

        /**
         * Return a version of the URL suitable for displaying in templates etc
         *
         * @return string
         */
        function getDisplayURL()
        {
            $url       = $this->getURL();
            $urischeme = parse_url($url, PHP_URL_SCHEME);
            if (\Idno\Common\Page::isSSL()) {
                $newuri = 'https:';
            } else {
                $newuri = 'http:';
            }

            $url = str_replace($urischeme . ':', $newuri, $url);
            if (substr($url, 0, 1) == ':') {
                $url = substr($url, 1);
            }

            return $url;
            //return str_replace($urischeme . ':', '', $url);
        }

        /**
         * Retrieve the canonical URL of the site
         *
         * @return string
         */
        function getURL()
        {
            if (!empty($this->url)) {
                return $this->url;
            } else {
                return '/';
            }
        }

        /**
         * Returns the upload path for Idno.
         *
         * @return string
         */
        function getUploadPath()
        {
            return $this->uploadpath;
        }

        /**
         * Returns the installation path for Idno.
         *
         * @return string
         */
        function getPath()
        {
            return $this->path;
        }

        /**
         * Make sure configuration values are what you'd expect
         */
        protected function sanitizeValues()
        {
            $this->url        = $this->sanitizeURL($this->url);
            $this->static_url = $this->sanitizeURL($this->static_url);
        }

        /**
         * Given a URL, ensure it fits the content standards we need
         *
         * @param  $url
         * @return bool
         */
        function sanitizeURL($url)
        {
            if (!empty($url)) {
                if ($url_pieces = parse_url($url)) {
                    if (substr($url, -1, 1) != '/') {
                        $url .= '/';
                    }

                    return $url;
                }
            }

            return false;
        }

        /**
         * Make sure attachment URL is pointing to the right place
         *
         * @param  $url
         * @return mixed
         */
        function sanitizeAttachmentURL($url)
        {
            if (!empty(\Idno\Core\Idno::site()->config()->attachment_base_host)) {
                $host = parse_url($url, PHP_URL_HOST);

                return str_replace($host, \Idno\Core\Idno::site()->config()->attachment_base_host, $url);
            }

            return $url;
        }

        /**
         * Get a version of the URL without URI scheme or trailing slash
         *
         * @return string
         */
        function getSchemelessURL($preceding_slashes = false)
        {
            $url       = $this->getURL();
            $urischeme = parse_url($url, PHP_URL_SCHEME);
            if ($preceding_slashes) {
                $url = str_replace($urischeme . ':', '', $url);
            } else {
                $url = str_replace($urischeme . '://', '', $url);
            }
            if (substr($url, -1, 1) == '/') {
                $url = substr($url, 0, strlen($url) - 1);
            }

            return $url;
        }

        /**
         * Retrieves the URL for static assets
         *
         * @return string
         */
        function getStaticURL()
        {
            if (!empty($this->static_url)) {
                return $this->static_url;
            }

            return $this->getDisplayURL();
        }

        /**
         * Adds an email address to the blocked list
         *
         * @param  $email
         * @return array|bool
         */
        function addBlockedEmail($email)
        {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails   = $this->getBlockedEmails();
                $emails[] = trim(strtolower($email));

                return $this->blocked_emails = $emails;
            }

            return false;
        }

        /**
         * Retrieve an array of email addresses that are blocked from registering on this site.
         *
         * @return array
         */
        function getBlockedEmails()
        {
            $emails = [];
            if (!empty($this->blocked_emails)) {
                $emails = $this->blocked_emails;
            }

            return $emails;
        }

        /**
         * Remove an email address from the blocklist
         *
         * @param  $email
         * @return array|bool
         */
        function removeBlockedEmail($email)
        {
            $count = 0;
            $email = trim(strtolower($email));
            if ($emails = $this->getBlockedEmails()) {
                foreach (array_keys($emails, $email, true) as $key) {
                    $count++;
                    unset($emails[$key]);
                }
                site()->config()->blocked_emails = $emails;

                return $count;
            }

            return false;
        }

        /**
         * Is the specified email address blocked from registering?
         *
         * @param  $email
         * @return bool
         */
        function emailIsBlocked($email)
        {
            $email = trim(strtolower($email));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if ($emails = $this->getBlockedEmails()) {
                    if (in_array($email, $emails)) {
                        return true;
                    }
                }
            }

            return false;
        }

        /**
         * Does this site have SSL?
         *
         * @return bool
         */
        function hasSSL()
        {
            if (substr_count(site()->config()->getURL(), 'https://') || !empty($this->config()->force_ssl)) {
                return true;
            }

            return false;
        }

        /**
         * Retrieve the name of this site
         *
         * @return string
         */
        function getTitle()
        {
            if (!empty($this->title)) {
                return $this->title;
            }

            return '';
        }

        /**
         * Retrieve the description of this site
         *
         * @return string
         */
        function getDescription()
        {
            if (!empty($this->description)) {
                return $this->description;
            }

            return '';
        }

        /**
         * Returns the base folder name to use when storing files (usually the site host)
         *
         * @return mixed|string
         */
        function getFileBaseDirName()
        {
            $host = $this->pathHost();
            if (!empty($this->file_path_host)) {
                $host = $this->file_path_host;
            }
            $host = site()->events()->triggerEvent('file/path/host', ['host' => $host], $host);

            return $host;
        }

        /**
         * Return a normalized version of the host, for use in file paths etc
         *
         * @return string
         */
        function pathHost()
        {
            return str_replace('www.', '', $this->host);
        }

        /**
         * Is this site's content available to non-members?
         *
         * @return bool
         */
        function isPublicSite()
        {
            if (empty($this->walled_garden)) {
                return true;
            }

            return false;
        }

        /**
         * Does this site allow users to have multiple syndication accounts?
         *
         * @return bool
         */
        function multipleSyndicationAccounts()
        {
            if (isset($this->multi_syndication)) {
                return $this->multi_syndication;
            }

            return false;
        }

        /**
         * Can new users be added to the site? Defaults to true; uses a hook to determine.
         *
         * @return bool
         */
        function canAddUsers()
        {
            $event = new Event();
            $event->setResponse(true);
            $event = site()->events()->dispatch('users/add/check', $event);

            return $event->response();
        }

        /**
         * Can the site administrator make this site private? Defaults to true; uses a hook to determine.
         *
         * @return bool
         */
        function canMakeSitePrivate()
        {
            $event = new Event();
            $event->setResponse(true);
            $event = site()->events()->dispatch('site/walledgarden/check', $event);

            return $event->response();
        }

        /**
         * Is this the default site configuration?
         *
         * @return bool
         */
        function isDefaultConfig()
        {
            if ($this->default_config) {
                return true;
            }

            return false;
        }

        /**
         * Get the content types that this site should display on its homepage.
         *
         * @return array
         */
        function getHomepageContentTypes()
        {
            $friendly_types = array();
            if ($temp_types = $this->default_feed_content) {
                if (is_array($temp_types)) {
                    foreach ($temp_types as $temp_type) {
                        if ($content_type_class = \Idno\Common\ContentType::categoryTitleToClass($temp_type)) {
                            $friendly_types[] = $content_type_class;
                        }
                    }
                }
            }

            return $friendly_types;
        }

        /**
         * Attempt to get a temporary folder suitable for writing in.
         *
         * @return string
         */
        function getTempDir()
        {
            static $temp;

            $temp = ini_get('upload_tmp_dir');
            if (@is_dir($temp)) {
                return $this->sanitizePath($temp);
            }

            if (function_exists('sys_get_temp_dir')) {
                $temp = sys_get_temp_dir();
                if (is_dir($temp)) {
                    return $this->sanitizePath($temp);
                }
            }

            if (!empty($this->uploadpath)) {
                $temp = $this->uploadpath;
                if (is_dir($temp)) {
                    return $temp;
                }
            }

            $temp = '/tmp/';

            return $temp;
        }

        /**
         * Add a trailing slash to the ends of paths
         *
         * @todo   Further sanitization tasks
         * @param  $path
         * @return string
         */
        function sanitizePath($path)
        {
            if (substr($path, -1) != DIRECTORY_SEPARATOR) {
                $path .= DIRECTORY_SEPARATOR;
            }

            return $path;
        }

        /**
         * Get the configured permalink structure for posts in the
         * format /:tag1/:tag2
         *
         * @return string
         */
        function getPermalinkStructure()
        {
            if (empty($this->permalink_structure)) {
                return '/:year/:slug';
            }

            return $this->permalink_structure;
        }

    }

}
