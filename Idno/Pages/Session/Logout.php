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

            $session = \Idno\Core\Idno::site()->session();
            $result  = $session->logUserOff();
            // The old session is gone (its data, its user, its form token and its id); the message waits in a new one, which
            // the page the visitor lands on shows.
            $session->startNewSession();
            $session->addMessage(\Idno\Core\Idno::site()->language()->_("You've signed out. See you soon!"));
            $this->forward(\Idno\Pages\Session\Login::sanitizeRedirectUrl($_SERVER['HTTP_REFERER'] ?? ''));

            return $result;
        }

    }

}

