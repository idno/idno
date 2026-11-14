<?php

    /**
     * Plugin management class
     *
     * @package    idno
     * @subpackage core
     */

namespace Idno\Core {

    use Idno\Common\Plugin;

    class Plugins extends \Idno\Common\Component
    {
        public $plugins = array(); // Property containing instantiated plugin classes

        /**
         * On initialization, the plugin management class loads plugins from the system
         * configuration and saves an instantiated copy of each one in its local $plugins
         * array property.
         */
        public function init()
        {

            if (!empty(\Idno\Core\Idno::site()->config()->directloadplugins)) {
                foreach (\Idno\Core\Idno::site()->config()->directloadplugins as $plugin => $folder) {
                    @include $folder . '/Main.php';
                }
            }

            if (!empty(\Idno\Core\Idno::site()->config()->prerequisiteplugins)) {
                \Idno\Core\Idno::site()->config()->prerequisiteplugins = array_unique(\Idno\Core\Idno::site()->config()->prerequisiteplugins);
                foreach (\Idno\Core\Idno::site()->config()->prerequisiteplugins as $plugin) {
                    if (is_subclass_of("IdnoPlugins\\{$plugin}\\Main", 'Idno\\Common\\Plugin')) {
                        $class = "IdnoPlugins\\{$plugin}\\Main";
                        if (empty($this->plugins[$plugin])) {
                            $this->plugins[$plugin] = new $class();
                        }
                    }
                }
            }
            if (!empty(\Idno\Core\Idno::site()->config()->alwaysplugins)) {
                if (empty(\Idno\Core\Idno::site()->config()->plugins)) {
                    \Idno\Core\Idno::site()->config()->plugins = [];
                }
                \Idno\Core\Idno::site()->config()->plugins = array_merge(\Idno\Core\Idno::site()->config()->plugins, \Idno\Core\Idno::site()->config()->alwaysplugins);
            }
            if (!empty(\Idno\Core\Idno::site()->config()->plugins)) {
                \Idno\Core\Idno::site()->config()->plugins = array_unique(\Idno\Core\Idno::site()->config()->plugins);
                foreach (\Idno\Core\Idno::site()->config()->plugins as $plugin) {
                    if (!in_array($plugin, \Idno\Core\Idno::site()->config()->antiplugins)) {
                        if (is_subclass_of("IdnoPlugins\\{$plugin}\\Main", 'Idno\\Common\\Plugin')) {
                            $class = "IdnoPlugins\\{$plugin}\\Main";
                            if (empty($this->plugins[$plugin])) {
                                $this->plugins[$plugin] = new $class();
                            }
                        }
                    }
                }
            }

            \Idno\Core\Idno::site()->events()->triggerEvent('plugins/loaded');
        }

        /**
         * Magic method to return the instantiated plugin object when
         * $Plugins->plugin_name is accessed
         *
         * @param  $name
         * @return mixed
         */
        public function __get($name)
        {
            if (!empty($this->plugins[$name])) {
                return $this->plugins[$name];
            }

            return null;
        }

        /**
         * Magic method to check for the existence of plugin objects as properties
         * on the plugin handler object
         *
         * @param  $name
         * @return bool
         */
        public function __isset($name)
        {
            if (!empty($this->plugins[$name])) {
                return true;
            }

            return false;
        }

        /**
         * Retrieves the array of loaded plugin objects
         *
         * @return array
         */
        public function getLoaded()
        {
            return $this->plugins;
        }

        /**
         * Retrieve the Plugin object associated with a loaded plugin
         *
         * @param  string $plugin Plugin name
         * @return bool|\Idno\Common\Plugin
         */
        public function get($plugin)
        {
            if (!empty($this->plugins[$plugin])) {
                return $this->plugins[$plugin];
            }

            return false;
        }

        /**
         * Is the specified plugin allowed to be displayed?
         *
         * @param  $plugin
         * @return bool
         */
        public function isVisible($plugin)
        {
            if (empty(\Idno\Core\Idno::site()->config()->hiddenplugins)) {
                return true;
            }
            if (!in_array($plugin, \Idno\Core\Idno::site()->config()->hiddenplugins)) {
                return true;
            }

            return false;
        }

        /**
         * Retrieves a list of stored plugins (but not necessarily loaded ones)
         *
         * @return array
         */
        public function getStored()
        {
            $plugins = array();
            if ($folders = scandir(\Idno\Core\Idno::site()->config()->path . '/IdnoPlugins')) {
                foreach ($folders as $folder) {
                    if ($folder != '.' && $folder != '..') {
                        if (is_dir(\Idno\Core\Idno::site()->config()->path . '/IdnoPlugins/' . $folder)) {
                            if ($this->isAllowed($folder)) {

                                // See if we can load some values from a package.json
                                if (file_exists(\Idno\Core\Idno::site()->config()->path . '/IdnoPlugins/' . $folder . '/package.json')) {
                                    $plugins[$folder]= [];
                                    $plugins[$folder]['Plugin description'] = array_replace_recursive($plugins[$folder], json_decode(file_get_contents(\Idno\Core\Idno::site()->config()->path . '/IdnoPlugins/' . $folder . '/package.json'), true));
                                }

                                // Get stuff from plugin.ini
                                if (file_exists(\Idno\Core\Idno::site()->config()->path . '/IdnoPlugins/' . $folder . '/plugin.ini')) {
                                    $plugins[$folder]= [];
                                    $plugins[$folder] = array_replace_recursive($plugins[$folder], parse_ini_file(\Idno\Core\Idno::site()->config()->path . '/IdnoPlugins/' . $folder . '/plugin.ini', true));
                                }
                            }
                        }
                    }
                }
            }
            ksort($plugins);

            return $plugins;
        }

        /**
         * Is the specified plugin allowed to be loaded?
         *
         * @param  $plugin
         * @return bool
         */
        public function isAllowed($plugin)
        {
            if (empty(\Idno\Core\Idno::site()->config()->antiplugins)) {
                return true;
            }
            if (!in_array($plugin, \Idno\Core\Idno::site()->config()->antiplugins)) {
                return true;
            }
            if (!empty(\Idno\Core\Idno::site()->config()->prerequisiteplugins)) {
                if (in_array($plugin, \Idno\Core\Idno::site()->config()->prerequisiteplugins)) {
                    return true;
                }
            }
            if (!empty(\Idno\Core\Idno::site()->config()->alwaysplugins)) {
                if (in_array($plugin, \Idno\Core\Idno::site()->config()->alwaysplugins)) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Retrieves the number of bytes stored by all plugins in the system.
         *
         * @return int
         */
        public function getTotalFileUsage()
        {
            if ($usage = $this->getFileUsageByPlugin()) {
                return (int)array_sum($usage);
            }

            return 0;
        }

        /**
         * Retrieves the file bytes stored by each plugin
         *
         * @return array
         */
        public function getFileUsageByPlugin()
        {
            $usage = [];
            if (!empty($this->plugins)) {
                foreach ($this->plugins as $plugin) {
                    if ($plugin instanceof Plugin) {
                        $usage[$plugin->getClass()] = (int)$plugin->getFileUsage();
                    }
                }
            }

            return $usage;
        }

        /**
         * Enable a specific plugin
         *
         * @param  string $plugin
         * @return boolean
         */
        public function enable($plugin)
        {

            if (!$this->exists($plugin)) {
                return false;
            }

            \Idno\Core\Idno::site()->events()->triggerEvent('plugin/load/' . $plugin);

            \Idno\Core\Idno::site()->config()->config['plugins'][] = $plugin;
            if (!empty(\Idno\Core\Idno::site()->config()->external_plugin_path) && file_exists(\Idno\Core\Idno::site()->config()->external_plugin_path . '/IdnoPlugins/' . $plugin)) {
                \Idno\Core\Idno::site()->config()->config['directloadplugins'][$plugin] = \Idno\Core\Idno::site()->config()->external_plugin_path . '/IdnoPlugins/' . $plugin;
            }

            \Idno\Core\Idno::site()->config()->config['plugins'] = array_unique(\Idno\Core\Idno::site()->config()->config['plugins']);
            \Idno\Core\Idno::site()->config()->save();

            return true;
        }

        /**
         * Disable a plugin
         *
         * @param  string $plugin The plugin
         * @return boolean
         */
        public function disable($plugin)
        {
            if (!$this->exists($plugin)) {
                return false;
            }
            if (($key = array_search($plugin, \Idno\Core\Idno::site()->config()->config['plugins'])) !== false) {
                \Idno\Core\Idno::site()->events()->triggerEvent('plugin/unload/' . $plugin);
                unset(\Idno\Core\Idno::site()->config()->config['plugins'][$key]);
                unset(\Idno\Core\Idno::site()->config()->config['directloadplugins'][$key]);

                \Idno\Core\Idno::site()->config()->config['plugins'] = array_unique(\Idno\Core\Idno::site()->config()->config['plugins']);
                \Idno\Core\Idno::site()->config()->save();

                return true;
            }
        }

        /**
         * Returns whether the selected plugin exists.
         *
         * @param  string $plugin
         * @return boolean
         */
        public function exists($plugin)
        {

            if (!preg_match('/^[a-zA-Z0-9]+$/', $plugin)) {
                return false;
            }
            if ((file_exists(\Idno\Core\Idno::site()->config()->path . '/IdnoPlugins/' . $plugin))
                || (!empty(\Idno\Core\Idno::site()->config()->external_plugin_path) && file_exists(\Idno\Core\Idno::site()->config()->external_plugin_path . '/IdnoPlugins/' . $plugin))
            ) {
                 return true;
            }

            return false;
        }
    }
}
