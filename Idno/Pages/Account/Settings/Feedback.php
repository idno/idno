<?php

    /**
     * Change user settings
     */

namespace Idno\Pages\Account\Settings {

    use Idno\Core\Webservice;

    class Feedback extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->createGatekeeper(); // Logged-in only please
            $t        = \Idno\Core\Idno::site()->template();
            $t->body  = $t->draw('account/settings/feedback');
            $t->title = \Idno\Core\Idno::site()->language()->_('Send feedback');
            $t->drawPage();
        }

        function postContent()
        {
            $this->createGatekeeper(); // Logged-in only please

            $email   = $this->getInput('email');
            $message = $this->getInput('message');

            if (!empty($email) && !empty($message)) {

                $results    = Webservice::post(
                    'https://idno.co/vendor-services/feedback/', array(
                    'url'     => \Idno\Core\Idno::site()->config()->getURL(),
                    'title'   => \Idno\Core\Idno::site()->config()->getTitle(),
                    'version' => \Idno\Core\Version::version(),
                    'public'  => \Idno\Core\Idno::site()->config()->isPublicSite(),
                    'email'   => $email,
                    'message' => $message
                    )
                );

                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Thanks! We received your feedback."));

            }

            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'account/settings/feedback/confirm/');

        }

    }

}

