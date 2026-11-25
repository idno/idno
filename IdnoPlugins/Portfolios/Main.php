<?php

namespace IdnoPlugins\Portfolios {

    use Idno\Core\Idno;

    class Main extends \Idno\Common\Plugin
    {

        function registerPages()
        {
            $routes = Idno::site()->routes();

            // Ahead of the core route that reads the rest of a profile address as kinds of post
            $routes->hijackRoute('/profile/([^\/]+)/portfolios/?', '\IdnoPlugins\Portfolios\Pages\Index');
            $routes->hijackRoute('/profile/([^\/]+)/portfolio/([^\/.]+)/?', '\IdnoPlugins\Portfolios\Pages\View');

            $routes->addRoute('/portfolio/edit/?', '\IdnoPlugins\Portfolios\Pages\Edit');
            $routes->addRoute('/portfolio/edit/:id/?', '\IdnoPlugins\Portfolios\Pages\Edit');
            $routes->addRoute('/portfolio/delete/:id/?', '\IdnoPlugins\Portfolios\Pages\Delete');
            $routes->addRoute('/portfolio/items/:id/?', '\IdnoPlugins\Portfolios\Pages\Items');
            $routes->addRoute('/portfolio/pick/?', '\IdnoPlugins\Portfolios\Pages\Pick');

            $template = Idno::site()->template();
            // "Add to portfolio" in the "More" menu of a member's own posts
            $template->extendTemplate('content/menu/items', 'portfolios/menuitem');
            // "Portfolios" in the Twenty26 side navigation and in the default look's member menu
            $template->extendTemplate('shell/nav/items', 'portfolios/nav');
            $template->extendTemplate('shell/toolbar/personal/items', 'portfolios/toolbar');
            // The "Portfolios" button of a profile
            $template->extendTemplate('entity/User/profile/actions', 'portfolios/profilebutton');
            // The plugin's own stylesheet and script
            $template->extendTemplate('shell/head', 'portfolios/assets');
        }

        function registerEventHooks()
        {
            // A post that is deleted leaves every portfolio it was in
            Idno::site()->events()->addListener('deleted', function (\Idno\Core\Event $event) {
                $data = $event->data();
                $object = $data['object'] ?? null;
                if ($object instanceof \Idno\Common\Entity && !($object instanceof Portfolio) && !($object instanceof \Idno\Entities\User)) {
                    Portfolio::removeEverywhere((string) $object->getUUID());
                }
            });
        }

        function registerTranslations()
        {
            Idno::site()->language()->register(
                new \Idno\Core\GetTextTranslation(
                    'portfolios', dirname(__FILE__) . '/languages/'
                )
            );
        }

    }

}
