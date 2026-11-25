<?php

    /**
     * Administration page: PHP dependencies
     */

namespace IdnoPlugins\Styles\Pages {

    /**
     * Default class to serve the homepage
     */
    class Admin extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->adminGatekeeper(); // Admins only

            $css = '';
            if (!empty(\Idno\Core\Idno::site()->config()->styles)) {
                if (!empty(\Idno\Core\Idno::site()->config()->styles['css'])) {
                    $css = \Idno\Core\Idno::site()->config()->styles['css'];
                }
            }

            $t = \Idno\Core\Idno::site()->template();
            $t->__(array(
                        'body'  => $t->__(array('css' => $css))->draw('styles/admin'),
                        'title' => \Idno\Core\Idno::site()->language()->_('Site styles')
            ))->drawPage();
        }

        function postContent()
        {
            $this->adminGatekeeper(); // Admins only

            $css = $this->getInput('css');
            if (!empty($_FILES['import']['tmp_name'])) {
                $css = @file_get_contents($_FILES['import']['tmp_name']);
            }
            $css = trim(strip_tags($css));

            $styles                             = array('css' => $css);

            \Idno\Core\Idno::site()->config()->styles = $styles;
            \Idno\Core\Idno::site()->config()->save();
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/styles/');
        }

    }

}

