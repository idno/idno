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
            $t->body  = $t->__(
                array(
                'plugins_stored' => \Idno\Core\Idno::site()->plugins()->getStored(),
                'plugins_loaded' => \Idno\Core\Idno::site()->plugins()->getLoaded(),
                )
            )->draw('admin/plugins');
            $t->title = \Idno\Core\Idno::site()->language()->_('Plugins');
            $t->drawPage();
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

    }

}

