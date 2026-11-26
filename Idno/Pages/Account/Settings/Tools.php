<?php

    /**
     * Allow the user to change their notification settings
     */

namespace Idno\Pages\Account\Settings {

    /**
     * Serve the user notifications settings page
     */
    class Tools extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->createGatekeeper(); // Logged-in only please

            if ($this->xhr) {
                \Idno\Core\Actions::validateToken('/account/settings/tools/');
                $user = \Idno\Core\Idno::site()->session()->currentUser();
                echo json_encode($user->getAPIkey());
            } else {
                $t        = \Idno\Core\Idno::site()->template();
                $t->body  = $t->draw('account/settings/tools');
                $t->title = \Idno\Core\Idno::site()->language()->_('Tools and Apps');
                $t->drawPage();
            }
        }

        function postContent()
        {
            $this->createGatekeeper();

            \Idno\Core\Actions::validateToken(\Idno\Core\Idno::site()->currentPage()->currentUrl());

            $user = \Idno\Core\Idno::site()->session()->currentUser();
            if (!empty($user)) {

                switch ($this->getInput('_method')) {
                    case 'revoke':
                        $user->apikey = null;
                        $user->getAPIkey();
                }
            }

            $this->forwardBack();
        }

    }

}

