<?php

namespace IdnoPlugins\Video {

    class Main extends \Idno\Common\Plugin
    {
        function registerPages()
        {
            \Idno\Core\Idno::site()->routes()->addRoute('/video/edit/?', '\IdnoPlugins\Video\Pages\Edit');
            \Idno\Core\Idno::site()->routes()->addRoute('/video/edit/:id/?', '\IdnoPlugins\Video\Pages\Edit');
            \Idno\Core\Idno::site()->routes()->addRoute('/video/delete/:id/?', '\IdnoPlugins\Video\Pages\Delete');

            // Administration: videos from PeerTube and Loops sites
            \Idno\Core\Idno::site()->routes()->addRoute('admin/video/?', '\IdnoPlugins\Video\Pages\Admin');
            \Idno\Core\Idno::site()->template()->extendTemplate('admin/menu/items', 'video/admin/menu');
        }

        function registerTranslations()
        {

            \Idno\Core\Idno::site()->language()->register(
                new \Idno\Core\GetTextTranslation(
                    'video', dirname(__FILE__) . '/languages/'
                )
            );
        }

        function getAdminIcon()
        {
            return 'video';
        }
    }

}
