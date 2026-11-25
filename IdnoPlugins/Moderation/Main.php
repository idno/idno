<?php

namespace IdnoPlugins\Moderation {

    use Idno\Core\Idno;

    /**
     * Reporting and moderation.
     *
     * Members report posts, comments and people from the "More" menu; administrators work
     * through the reports in a queue under /admin/moderation/, where they can dismiss a
     * report, take content down, or ban a member. See Moderation for the rules.
     */
    class Main extends \Idno\Common\Plugin
    {

        function registerPages()
        {
            $routes = Idno::site()->routes();

            // Reporting, for signed-in members
            $routes->addRoute('/moderation/report/?', '\IdnoPlugins\Moderation\Pages\Report');

            // The queue, for administrators
            $routes->addRoute('/admin/moderation/?', '\IdnoPlugins\Moderation\Pages\Admin\Queue');
            $routes->addRoute('/admin/moderation/case/([A-Za-z0-9-]+)/action/?', '\IdnoPlugins\Moderation\Pages\Admin\Action');
            $routes->addRoute('/admin/moderation/case/([A-Za-z0-9-]+)/?', '\IdnoPlugins\Moderation\Pages\Admin\Queue');
            $routes->addRoute('/admin/moderation/member/([A-Za-z0-9-]+)/?', '\IdnoPlugins\Moderation\Pages\Admin\Member');
            $routes->addRoute('/admin/moderation/settings/?', '\IdnoPlugins\Moderation\Pages\Admin\Settings');

            $template = Idno::site()->template();
            $template->extendTemplate('content/menu/items', 'moderation/menu/post');
            $template->extendTemplate('entity/annotations/menu/items', 'moderation/menu/comment');
            $template->extendTemplate('entity/User/menu/items', 'moderation/menu/profile');
            $template->extendTemplate('admin/menu/items', 'moderation/admin/menu');
            $template->extendTemplate('forms/components/usersearch/user/actions', 'moderation/admin/useraction');
        }

        function registerEventHooks()
        {
            $events = Idno::site()->events();

            // The styles and the report dialog, on every page (the menu is on most of them)
            $events->addListener(
                'page/head', function (\Idno\Core\Event $event) {
                    $page = $event->data()['page'] ?? null;
                    if ($page instanceof \Idno\Common\Page) {
                        $base = Idno::site()->config()->getStaticURL() . 'IdnoPlugins/Moderation/';
                        $version = @filemtime(__DIR__ . '/moderation.css') ?: 1;
                        $page->setAsset('moderation', $base . 'moderation.css?v=' . $version, 'css');
                        $page->setAsset('moderation', $base . 'moderation.js?v=' . (@filemtime(__DIR__ . '/moderation.js') ?: 1), 'javascript');
                    }
                }
            );

            // Mails are put together when something happens and sent from the queue
            $events->addListener(
                'moderation/mail', function (\Idno\Core\Event $event) {
                    Mailer::send((array) $event->data());
                }
            );
        }

        function getAdminIcon()
        {
            return 'shield';
        }
    }

}
