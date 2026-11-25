<?php

namespace Themes\Twenty26 {

    class Controller extends \Idno\Common\Theme
    {

        function init()
        {
            // Set the modern template type for this theme
            \Idno\Core\Idno::site()->template()->setTemplateType('modern');

            // Register drafts page
            \Idno\Core\Idno::site()->routes()->addRoute('/drafts/?', 'Themes\Twenty26\Pages\Drafts');

            // Say where the drafts are listed (deleting a draft lands there)
            \Idno\Core\Idno::site()->events()->addListener('drafts/url', function (\Idno\Core\Event $event) {
                $event->setResponse(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'drafts/');
            });
        }

    }

}
