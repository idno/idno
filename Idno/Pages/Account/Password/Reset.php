<?php

    /**
     * Reset a forgotten password
     */

namespace Idno\Pages\Account\Password {

    /**
     * Default class to serve the password recovery page
     */
    class Reset extends \Idno\Common\Page
    {

        function getContent()
        {

            $this->reverseGatekeeper();
            $code  = $this->getInput('code');
            $email = $this->getInput('email');

            if (!empty($code) && !empty($email)) {
                if ($user = \Idno\Entities\User::getByEmail($email)) {
                    $storedCode = $user->getPasswordRecoveryCode();
                    if ($storedCode && hash_equals($storedCode, $code)) {

                        $t        = \Idno\Core\Idno::site()->template();
                        $t->body  = $t->__(array('email' => $email, 'code' => $code))->draw('account/password/reset');
                        $t->title = \Idno\Core\Idno::site()->language()->_('Reset password');

                        // The plain shell, with its messages: drawPage() would pick the account settings
                        // shell for an address under /account/, which is for people who are signed in
                        $t->messages = \Idno\Core\Idno::site()->session()->getAndFlushMessages();
                        echo $t->draw('shell');
                        exit;

                    }
                }
            }

            \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("The password reset code wasn't valid. They expire after three hours, so you might need to try again."));
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'account/password');

        }

        function postContent()
        {

            $this->reverseGatekeeper();
            $code      = $this->getInput('code');
            $email     = $this->getInput('email');
            $password  = trim($this->getInput('password'));
            $password2 = trim($this->getInput('password2'));

            if (\Idno\Entities\User::checkNewPasswordStrength($password) && $password == $password2) {
                if (!empty($code) && !empty($email)) {
                    if ($user = \Idno\Entities\User::getByEmail($email)) {
                        $storedCode = $user->getPasswordRecoveryCode();
                        if ($storedCode && hash_equals($storedCode, $code)) {

                            /* @var \Idno\Entities\User $user */
                            $user->setPassword($password);
                            $user->clearPasswordRecoveryCode();
                            $user->save(true);
                            // On to the sign-in page: the home page of a private site would refuse them
                            \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Your password was changed. Sign in with your new password."));
                            $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'session/login');
                            return;

                        }
                    }
                }
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("The password reset code wasn't valid. They expire after three hours, so you might need to try again."));
                $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'account/password');
            } else {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('Sorry, your passwords either don\'t match, or are too weak'));
                // Back to the form: the link's own address, never one taken from the request's headers
                $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/password/reset/?email=' . urlencode((string) $email) . '&code=' . urlencode((string) $code));
            }

        }

    }

}

