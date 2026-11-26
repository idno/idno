<?php

    /**
     * Defines built-in log out functionality
     */

namespace Idno\Pages\Session {

    /**
     * Default class to serve the homepage
     */
    class Logout extends \Idno\Common\Page
    {

        function getContent()
        {
        }

        function postContent()
        {
            \Idno\Core\Idno::site()->events()->triggerEvent('logout/success', array('user' => \Idno\Core\Idno::site()->session()->currentUser())); // Trigger an event for auditing

            $result = \Idno\Core\Idno::site()->session()->logUserOff();
            \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("You've signed out. See you soon!"));
            $this->forwardBack();

            return $result;
        }

    }

}

