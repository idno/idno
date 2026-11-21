<?php

namespace IdnoPlugins\Webhooks {

    use Idno\Common\Entity;
    use Idno\Common\Plugin;
    use Idno\Core\Webservice;

    class Main extends Plugin
    {

        function registerTranslations()
        {

            \Idno\Core\Idno::site()->language()->register(
                new \Idno\Core\GetTextTranslation(
                    'webhooks', dirname(__FILE__) . '/languages/'
                )
            );
        }

        function registerPages()
        {

            \Idno\Core\Idno::site()->routes()->addRoute('admin/webhooks/?', 'IdnoPlugins\Webhooks\Pages\Admin');

            \Idno\Core\Idno::site()->template()->extendTemplate('admin/menu/items', 'webhooks/admin/menu');

        }

        function registerEventHooks()
        {
            \Idno\Core\Idno::site()->syndication()->registerService('webhooks', function() {
                return $this->hasWebhooks();
            }, array('note', 'bookmark', 'event', 'article'));

            if ($this->hasWebhooks()) {
                if (!empty(\Idno\Core\Idno::site()->config()->webhook_syndication)) {
                    foreach (\Idno\Core\Idno::site()->config()->webhook_syndication as $hook) {
                        if (!empty($hook['url']))
                            \Idno\Core\Idno::site()->syndication()->registerServiceAccount('webhooks', $hook['url'], $hook['title']);
                    }
                }
                if (\Idno\Core\Idno::site()->session()->isLoggedIn()) {
                    if (!empty(\Idno\Core\Idno::site()->session()->currentUser()->webhook_syndication)) {
                        foreach (\Idno\Core\Idno::site()->session()->currentUser()->webhook_syndication as $hook) {
                            if (!empty($hook['url']))
                                \Idno\Core\Idno::site()->syndication()->registerServiceAccount('webhooks', $hook['url'], $hook['title']);
                        }
                    }
                }
            }

            $hook_function = function(\Idno\Core\Event $event) {

                $eventdata = $event->data();
                if ($this->hasWebhooks()) {
                    $object = $eventdata['object'];
                    if (!empty($object) && $object instanceof Entity && !empty($eventdata['syndication_account'])) {

                        $payload = array();
                        $hook_url = $eventdata['syndication_account'];

                        if ($owner = $object->getOwner()) {
                            $payload['icon_url'] = $owner->getIcon();
                            $payload['username'] = $owner->getHandle();
                        }
                        $payload['content_type'] = $object->getActivityStreamsObjectType();
                        $payload['text'] = $object->getTitle() . ' <' . $object->getURL() . '>';
                        $payload['title'] = $object->getTitle();

                        // A hook the admin configured is the site's to call; any other URL is one a member
                        // typed, and goes through the client that refuses private networks.
                        $site_hooks = array_column((array) \Idno\Core\Idno::site()->config()->webhook_syndication, 'url');
                        if (in_array($hook_url, $site_hooks, true)) {
                            Webservice::post($hook_url, json_encode($payload), ['Content-Type: application/json']);
                        } else {
                            Webservice::safeSend('post', $hook_url, json_encode($payload), ['Content-Type: application/json']);
                        }

                    }
                }

            };

                \Idno\Core\Idno::site()->events()->addListener('post/note/webhooks', $hook_function);
                \Idno\Core\Idno::site()->events()->addListener('post/article/webhooks', $hook_function);
                \Idno\Core\Idno::site()->events()->addListener('post/bookmark/webhooks', $hook_function);
                \Idno\Core\Idno::site()->events()->addListener('post/event/webhooks', $hook_function);

        }

        /**
         * Have webhooks been registered in the system?
         * @return bool
         */
        function hasWebhooks()
        {
            if (!empty(\Idno\Core\Idno::site()->config()->webhook_syndication) ||
                (\Idno\Core\Idno::site()->session()->isLoggedIn() && !empty(\Idno\Core\Idno::site()->session()->currentUser()->webhook_syndication))) {
                return true;
            }
            return false;
        }

        function getAdminIcon()
        {
            return 'webhook';
        }

    }

}

