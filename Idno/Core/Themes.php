<?php

    /**
     * Theme management class
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    class Themes extends \Idno\Common\Component
    {

        public $theme = ''; // Property containing the current theme (blank if none)
        public $themes = array(); // Array containing instantiated theme controllers

        /**
         * Themes that are no longer bundled; a site configured to use one uses Twenty26 instead.
         */
        public const RETIRED = ['Black', 'Cherwell', 'ElkinsPark', 'Fauvists', 'Green', 'Kandinsky', 'MarketStreet', 'Solo'];

        /**
         * The theme to load for a configured theme name: Twenty26 for a retired theme, otherwise
         * the name unchanged (including the empty string, meaning no theme, and the name of a
         * theme that is merely missing).
         *
         * @param  string $configured
         * @return string
         */
        public static function resolve(string $configured): string
        {
            return in_array($configured, self::RETIRED, true) ? 'Twenty26' : $configured;
        }

        /**
         * On initialization, the theme management class loads the current theme and sets it as
         * a template directory
         */
        public function init()
        {

            if (!empty(site()->config()->theme)) {
                $this->theme = self::resolve(site()->config()->theme);
                if ($this->requestHasHostHeader()) {
                    if (file_exists(\Idno\Core\Idno::site()->config()->path . '/Themes/' . $this->theme . '/theme.ini')) {
                        \Idno\Core\Bonita\Main::additionalPath(site()->config()->path . '/Themes/' . $this->theme);
                        $config = parse_ini_file(\Idno\Core\Idno::site()->config()->path . '/Themes/' . $this->theme . '/theme.ini', true);
                    }
                }
                if (!empty($config)) {
                    if (!empty($config['extensions'])) {
                        $extensions = $config['extensions'];
                    } else if (!empty($config['Extensions'])) {
                        $extensions = $config['Extensions'];
                    }
                    if (!empty($extensions)) {
                        foreach ($extensions as $template => $extension) {
                            site()->template()->extendTemplate($template, $extension);
                        }
                    }
                    if (!empty($config['prepend extensions'])) {
                        $prep_extensions = $config['prepend extensions'];
                    } else if (!empty($config['Prepend Extensions'])) {
                        $prep_extensions = $config['Prepend Extensions'];
                    }
                    if (!empty($prep_extensions)) {
                        foreach ($prep_extensions as $template => $extension) {
                            //site()->template()->extendTemplate($template, $extension, true);
                            site()->template()->prependTemplate($template, $extension, true);
                        }
                    }
                    if (is_subclass_of("Themes\\{$this->theme}\\Controller", 'Idno\\Common\\Theme')) {
                        $class                      = "Themes\\{$this->theme}\\Controller";
                        $this->themes[$this->theme] = new $class();
                    }
                }
            }

        }

        /**
         * If the configured theme is a retired one, saves the replacement in the site
         * configuration and tells the administrator, once. Does nothing unless the current
         * user is an admin on a web request, so visitors never write the configuration and
         * see no message. It runs after the user is authenticated (Idno::__construct), which
         * is after init(): the session user is not known when this class is built.
         *
         * @return void
         */
        public function announceRetiredTheme()
        {
            $configured = (string)site()->config()->theme;
            // A theme pinned in config.ini or the environment would override the saved value on
            // every load, so saving and announcing would repeat forever: leave it alone.
            // (Same data as SiteSettings::isOverridden('theme'): ini_config is the boot config.)
            if (array_key_exists('theme', site()->config()->ini_config)) {
                return;
            }
            if (!$this->requestHasHostHeader() || !in_array($configured, self::RETIRED, true) || !site()->session()->isAdmin()) {
                return;
            }
            // For an admin authenticated by API token the setting is saved but the session notice may never be seen.
            $theme = self::resolve($configured);
            site()->config()->config['theme'] = $theme;
            site()->config()->theme           = $theme;
            site()->config()->save();
            site()->session()->addMessage(site()->language()->_('The %s theme has been retired. Your site now uses Idno 2026.', [$configured]));
        }

        /**
         * Whether this process is serving a request that carries a Host header.
         *
         * Preserved legacy condition: the configured theme is loaded only in that case, so a
         * theme is loaded for web requests but not on the console, which has no Host header.
         * (It used to be tested by whether a multi-tenant host constant, defined from the Host
         * header, existed.)
         *
         * @return bool
         */
        private function requestHasHostHeader()
        {
            return !empty($_SERVER['HTTP_HOST']);
        }

        /**
         * Retrieves the array of loaded theme objects
         *
         * @return array
         */
        public function get()
        {
            return $this->theme;
        }

        /**
         * Retrieves a list of stored themes (but not necessarily loaded ones)
         *
         * @return array
         */
        public function getStored()
        {
            $themes = array();
            if ($folders = scandir(\Idno\Core\Idno::site()->config()->path . '/Themes')) {
                foreach ($folders as $folder) {
                    if ($folder != '.' && $folder != '..') {
                        if (file_exists(\Idno\Core\Idno::site()->config()->path . '/Themes/' . $folder . '/theme.ini')) {
                            if (!in_array($folder, site()->config()->hiddenthemes)) {
                                $themes[$folder]                              = parse_ini_file(\Idno\Core\Idno::site()->config()->path . '/Themes/' . $folder . '/theme.ini', true);
                                $themes[$folder]['Theme description']['path'] = \Idno\Core\Idno::site()->config()->path . '/Themes/' . $folder . '/';
                                $themes[$folder]['Theme description']['url']  = \Idno\Core\Idno::site()->config()->getURL() . 'Themes/' . $folder . '/';
                            }
                        }
                    }
                }
            }

            $themes[''] = array(
                'Theme description' => array(
                    'name'         => 'Default theme',
                    'version'      => '0.1',
                    'author'       => "Idno",
                    'author_email' => "hello@idno.co",
                    'author_url'   => "https://idno.co",
                    'description'  => 'The default Idno theme, built to be used as a basis for your designs.'
                )
            );

            ksort($themes);

            return $themes;
        }

    }

}

