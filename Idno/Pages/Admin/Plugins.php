<?php

    /**
     * Plugin administration
     */

namespace Idno\Pages\Admin {

    /**
     * Default class to serve the homepage
     */
    class Plugins extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->adminGatekeeper(); // Admins only
            $t        = \Idno\Core\Idno::site()->template();
            $stored   = \Idno\Core\Idno::site()->plugins()->getStored();
            $t->body  = $t->__(
                array(
                'plugins_stored' => $stored,
                'plugins_loaded' => \Idno\Core\Idno::site()->plugins()->getLoaded(),
                'plugins_visible' => $this->visiblePlugins($stored),
                )
            )->draw('admin/plugins');
            $t->title = \Idno\Core\Idno::site()->language()->_('Plugins');
            $t->drawPage();
        }

        /**
         * The plugins the page lists, in the order it lists them: the ones the site does not hide, each with its `shortname`, by name
         * (two plugins of one name are listed once: the later one).
         *
         * @param  mixed $stored what Plugins::getStored() gave
         * @return array<int, array>
         */
        private function visiblePlugins($stored)
        {
            $listed = [];
            if (!empty($stored) && is_array($stored)) {
                foreach ($stored as $shortname => $plugin) {
                    if (\Idno\Core\Idno::site()->plugins()->isVisible($shortname)) {
                        $plugin['shortname'] = $shortname;
                        $listed[$plugin['Plugin description']['name']] = $plugin;
                    }
                }
            }
            ksort($listed);

            return array_values($listed);
        }

        function postContent()
        {
            $this->adminGatekeeper(); // Admins only
            $plugin = $this->getInput('plugin');
            $action = $this->getInput('plugin_action');
            if (preg_match('/^[a-zA-Z0-9]+$/', $plugin)
                && (\Idno\Core\Idno::site()->plugins()->exists($plugin))
            ) {
                switch ($action) {
                    case 'install':
                        if (\Idno\Core\Idno::site()->plugins()->enable($plugin)) {
                            $this->reconcileIndexedFields($plugin);
                            \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_('The plugin was enabled.'));

                            echo json_encode(
                                [
                                'action' => $action,
                                'status' => true,
                                'message' => \Idno\Core\Idno::site()->language()->_('The plugin was enabled.')
                                ]
                            );
                            exit;
                        }

                        break;
                    case 'uninstall':

                        if (\Idno\Core\Idno::site()->plugins()->disable($plugin)) {
                            \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_('The plugin was disabled.'));

                            echo json_encode(
                                [
                                'action' => $action,
                                'status' => true,
                                'message' => \Idno\Core\Idno::site()->language()->_('The plugin was disabled.')
                                ]
                            );
                            exit;
                        }

                        break;
                }

            }

            echo json_encode(
                [
                'action' => $action,
                'status' => false,
                ]
            );
            //$this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/plugins/');
        }

        /**
         * Creates the indexes that the plugin's entity classes declare (#[IndexedField]). A site
         * whose database is not SQL (a backend class a plugin supplies) has none and is skipped. Any failure (a missing or
         * broken service included) is logged and shown, and never stops the plugin from being enabled.
         *
         * @param string      $plugin
         * @param string|null $directory the plugin's directory (default: found as Plugins finds it)
         */
        private function reconcileIndexedFields($plugin, $directory = null)
        {
            if ($directory === null) {
                $directory = \Idno\Core\Idno::site()->config()->path . '/IdnoPlugins/' . $plugin;
                if (!is_dir($directory) && !empty(\Idno\Core\Idno::site()->config()->external_plugin_path)) {
                    $directory = \Idno\Core\Idno::site()->config()->external_plugin_path . '/IdnoPlugins/' . $plugin;
                }
            }
            try {
                $database = \Idno\Storage\ConnectionParameters::driverFamily(\Idno\Core\Idno::container()->get(\Idno\Config\BootConfig::class)->all());
                if ($database !== 'mysql' && $database !== 'sqlite') {
                    return; // Not an SQL site: it has no indexed fields.
                }
                $indexes = \Idno\Core\Idno::container()->get(\Idno\Storage\IndexedFields::class);
                $indexes->reconcileDirectory($directory);
                foreach ($indexes->conflicts() as $conflict) {
                    \Idno\Core\Idno::site()->logging()->warning('Indexed field of plugin ' . $plugin . ': ' . $conflict);
                }
            } catch (\Throwable $e) {
                \Idno\Core\Idno::site()->logging()->error('Could not create the indexes of plugin ' . $plugin . ': ' . get_class($e) . ': ' . $e->getMessage());
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('The plugin was enabled, but the indexes it declares could not be created. The site works without them; the error was logged.'));
            }
        }

    }

}

