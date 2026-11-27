<?php

    /**
     * Reset a forgotten password
     */

namespace Idno\Pages\Account\Password {

    /**
     * Default class to serve the password reset page
     *
     * The link in the reset mail carries the member's address and the code in its query, so the page sends `Referrer-Policy: no-referrer`, and the
     * code is never a variable of the page (a JSON request prints them): the form is drawn on a copy of the page's template. A reset the page
     * refuses because of the passwords is the form again, in the answer to the POST, carrying the address and the code in hidden fields and never the
     * passwords; a reset it refuses because of the code is one answer for a wrong, a used and an expired code and for an address that is no
     * member's or may not reset, and it sends the visitor to the page that asks for a new link.
     */
    class Reset extends \Idno\Common\Page
    {

        function getContent()
        {

            $this->reverseGatekeeper();
            // Texts: a field sent as an array is an empty one
            $code  = $this->getTextInput('code');
            $email = $this->getTextInput('email');

            if ($this->memberFor($email, $code)) {
                $this->drawForm($email, $code);
                return;
            }

            $this->refuseTheCode();

        }

        function postContent()
        {

            $this->reverseGatekeeper();
            // Texts: a field sent as an array is an empty one
            $code      = $this->getTextInput('code');
            $email     = $this->getTextInput('email');
            $password  = trim($this->getTextInput('password'));
            $password2 = trim($this->getTextInput('password2'));

            // The code is judged first: whoever has not a right one is told only that, whatever they typed as passwords
            if (!($user = $this->memberFor($email, $code))) {
                $this->refuseTheCode();
                return;
            }

            if (!\Idno\Entities\User::checkNewPasswordStrength($password) || $password !== $password2) {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('Sorry, your passwords either don\'t match, or are too weak'));
                // The form again, here: a redirect would put the code in an address
                $this->setResponse(400);
                $this->drawForm($email, $code);
                return;
            }

            /* @var \Idno\Entities\User $user */
            $user->setPassword($password);
            $user->clearPasswordRecoveryCode();
            $user->save(true);
            // A member who was locked out of signing in can sign in at once.
            \Idno\Pages\Session\Login::throttleReset(\Idno\Pages\Session\Login::throttleKeyFor($user));
            // On to the sign-in page: the home page of a private site would refuse them, and nobody is signed in by a reset
            \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_('Your password was changed. Sign in with your new password.'));
            $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'session/login');

        }

        /**
         * The member whose address this is, when the code is their current one and they may reset: an account that is being deleted, and an address
         * the site has blocked, cannot (they could not sign in with the new password either).
         *
         * @param  string $email
         * @param  string $code
         * @return \Idno\Entities\User|false
         */
        private function memberFor($email, $code)
        {
            if ($email === '' || $code === '') {
                return false;
            }
            $user = \Idno\Entities\User::getByEmail($email);
            if (!$user || $user->isClosed() || \Idno\Core\Idno::site()->config()->emailIsBlocked($user->email)) {
                return false;
            }

            return $user->passwordRecoveryCodeMatches($code) ? $user : false;
        }

        /**
         * The one answer to a code that is not right: it does not say whether it was wrong, used or too old, and the page it goes to asks for a new link.
         */
        private function refuseTheCode()
        {
            \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("The password reset code wasn't valid. They expire after three hours, so you might need to try again."));
            $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/password');
        }

        /**
         * Draw the form for this address and code in the site's own shell, and end the request (see Password::drawInTheSitesShell()).
         */
        private function drawForm($email, $code)
        {
            header('Referrer-Policy: no-referrer');
            $t        = \Idno\Core\Idno::site()->template();
            $t->body  = $t->withVars(array_merge($t->vars, ['email' => $email, 'code' => $code]))->draw('account/password/reset');
            $t->title = \Idno\Core\Idno::site()->language()->_('Reset password');
            \Idno\Pages\Account\Password::drawInTheSitesShell();
        }

    }

}
