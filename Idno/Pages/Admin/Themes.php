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
            $stored   = \Idno\Core\Idno::site()->themes()->getStored();
            $t->body  = $t->__(
                array(
                'themes_stored' => $stored,
                'theme'         => \Idno\Core\Idno::site()->themes()->get(),
                'themes_with_preview' => $this->themesWithPreview($stored),
                )
            )->draw('admin/themes');
            $t->title = \Idno\Core\Idno::site()->language()->_('Themes');
            $t->drawPage();
        }

        /**
         * The themes that have a `preview.png` in their directory (the built-in look, `default`, has its own picture).
         *
         * @param  mixed $stored what Themes::getStored() gave
         * @return array<int, string>
         */
        private function themesWithPreview($stored)
        {
            $themes = [];
            if (!empty($stored) && is_array($stored)) {
                foreach ($stored as $shortname => $theme) {
                    // As the card names it: the built-in look (no folder) is `default`
                    $name = empty($shortname) ? 'default' : $shortname;
                    $path = !empty($theme['Theme description']['path']) ? $theme['Theme description']['path'] : false;
                    if ($name !== 'default' && file_exists($path . 'preview.png')) {
                        $themes[] = (string) $name;
                    }
                }
            }

            return $themes;
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

