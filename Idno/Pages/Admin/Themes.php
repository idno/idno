<?php

    /**
     * Theme administration
     */

namespace Idno\Pages\Admin {

    use Idno\Core\Idno;

    class Themes extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->adminGatekeeper(); // Admins only
            $t        = \Idno\Core\Idno::site()->template();
            $t->body  = $t->__(
                array(
                'themes_stored' => \Idno\Core\Idno::site()->themes()->getStored(),
                'theme'         => \Idno\Core\Idno::site()->themes()->get(),
                )
            )->draw('admin/themes');
            $t->title = \Idno\Core\Idno::site()->language()->_('Themes');
            $t->drawPage();
        }

        function postContent()
        {
            $this->adminGatekeeper(); // Admins only
            $theme  = $this->getInput('theme');
            $action = $this->getInput('action');
            if ((                preg_match('/^[a-zA-Z0-9]+$/', $theme)
                && (                file_exists(\Idno\Core\Idno::site()->config()->path . '/Themes/' . $theme)                ))
                || $theme == 'default' || $theme == ''
            ) {
                switch ($action) {
                    case 'install':
                        \Idno\Core\Idno::site()->config()->config['theme'] = $theme;
                        Idno::site()->config()->theme                    = $theme;
                        //\Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_('The theme was enabled.'));
                        break;
                    case 'uninstall':
                        \Idno\Core\Idno::site()->config()->config['theme'] = '';
                        break;
                }
                \Idno\Core\Idno::site()->config()->save();
            }
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/themes/');
        }

    }

}

