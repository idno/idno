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
         * On initialization, the theme management class loads the current theme and sets it as
         * a template directory
         */
        public function init()
        {

            if (!empty(site()->config()->theme)) {
                $this->theme = site()->config()->theme;
                if (defined('IDNO_MULTITENANT_HOST')) {
                    $host = IDNO_MULTITENANT_HOST;
                    if (file_exists(\Idno\Core\Idno::site()->config()->path . '/hosts/' . $host . '/Themes/' . $this->theme)) {
                        \Idno\Core\Bonita\Main::additionalPath(site()->config()->path . '/hosts/' . $host . '/Themes/' . $this->theme);
                        $config = parse_ini_file(\Idno\Core\Idno::site()->config()->path . '/hosts/' . $host . '/Themes/' . $this->theme . '/theme.ini', true);
                    } else if (file_exists(\Idno\Core\Idno::site()->config()->path . '/Themes/' . $this->theme . '/theme.ini')) {
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
                    // [Extensions] (template = extension): the extension is drawn BEFORE the
                    // template it extends, after the extensions that were added earlier
                    // (see DefaultTemplate::draw() for the whole order)
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
                    // [Prepend Extensions]: drawn ahead of everything else of the template,
                    // its other extensions included. Each one is put at the front, so of
                    // several for one template the one added last is drawn first
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
            if (defined('IDNO_MULTITENANT_HOST')) {
                $host = IDNO_MULTITENANT_HOST;
                if (file_exists(\Idno\Core\Idno::site()->config()->path . '/hosts/' . $host . '/Themes')) {
                    if ($folders = scandir(\Idno\Core\Idno::site()->config()->path . '/hosts/' . $host . '/Themes')) {
                        foreach ($folders as $folder) {
                            if ($folder != '.' && $folder != '..') {
                                if (file_exists(\Idno\Core\Idno::site()->config()->path . '/hosts/' . $host . '/Themes/' . $folder . '/theme.ini')) {
                                    $themes[$folder]                              = parse_ini_file(\Idno\Core\Idno::site()->config()->path . '/hosts/' . $host . '/Themes/' . $folder . '/theme.ini', true);
                                    $themes[$folder]['Theme description']['path'] = \Idno\Core\Idno::site()->config()->path . '/hosts/' . $host . '/Themes/' . $folder . '/';
                                    $themes[$folder]['Theme description']['url']  = \Idno\Core\Idno::site()->config()->getURL() . 'hosts/' . $host . '/Themes/' . $folder . '/';
                                }
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

