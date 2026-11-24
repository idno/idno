<?php

    /**
     * Change user settings
     */

namespace Idno\Pages\Account {

    /**
     * Default class to serve the homepage
     */
    class Settings extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->createGatekeeper(); // Logged-in only please
            $t        = \Idno\Core\Idno::site()->template();
            $t->body  = $t->draw('account/settings');
            $t->title = \Idno\Core\Idno::site()->language()->_('Account settings');
            $t->drawPage();
        }

        function postContent()
        {
            $this->createGatekeeper(); // Logged-in only please
            $user     = \Idno\Core\Idno::site()->session()->currentUser();
            $name     = $this->getInput('name');
            $email    = $this->getInput('email');
            $password = trim($this->getInput('password'));
            $username = trim($this->getInput('handle'));
            $timezone = trim($this->getInput('timezone'));

            /*if (!\Idno\Common\Page::isSSL() && !\Idno\Core\Idno::site()->config()->disable_cleartext_warning) {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Warning: Access credentials were sent over a non-secured connection! To disable this warning set disable_cleartext_warning in your config.ini"));
            }*/

            // Changing the email address or the password takes the member's CURRENT password: a stolen session alone cannot take
            // the account over. It is counted as a login for the account is (a wrong one is a failed attempt), and when it is not
            // right nothing is changed.
            $language = \Idno\Core\Idno::site()->language();
            if ((!empty($email) && $email != $user->email) || !empty($password)) {
                $key = \Idno\Pages\Session\Login::throttleKeyFor($user);
                if (!\Idno\Pages\Session\Login::throttleAttempt($key)) {
                    \Idno\Core\Idno::site()->session()->addErrorMessage($language->_("Too many failed sign-in attempts for that username or email address. Please wait a few minutes and try again."));
                    $this->forward($_SERVER['HTTP_REFERER'] ?? '');

                    return;
                }
                if (!$user->checkPassword(trim((string)$this->getInput('current_password')))) {
                    \Idno\Core\Idno::site()->session()->addErrorMessage($language->_("Your current password isn't right, so nothing was changed. Please enter it to change your email address or password."));
                    $this->forward($_SERVER['HTTP_REFERER'] ?? '');

                    return;
                }
                \Idno\Pages\Session\Login::throttleReset($key);
            }

            if (!empty($name)) {
                $user->setTitle($name);
            }

            if (!empty($username) && $username != $user->getHandle()) {
                $user->setHandle($username);
            }

            if (!empty($email) && $email != $user->email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if (!\Idno\Entities\User::getByEmail($email)) {
                    $user->email = $email;
                } else {
                    \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->esc_('Someone is already using %s as their email address.', [$email]));
                }
            }

            if (!empty($password)) {
                if (\Idno\Entities\User::checkNewPasswordStrength($password)) {
                    \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Your password has been updated."));
                    $user->setPassword($password);
                } else {
                    \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('Sorry, your password is too weak'));
                }
            }

            $user->timezone = $timezone;

            if ($user->save()) {
                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Your details were saved."));
            }
            $this->forward($_SERVER['HTTP_REFERER']);
        }

    }

}

