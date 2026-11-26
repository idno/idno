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

                        $t->drawPage();
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
                            // A member who was locked out of signing in can sign in at once.
                            \Idno\Pages\Session\Login::throttleReset(\Idno\Pages\Session\Login::throttleKeyFor($user));
                            \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Your password was reset!"));
                            $this->forward(\Idno\Core\Idno::site()->config()->getURL());
                            return;

                        }
                    }
                }
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("The password reset code wasn't valid. They expire after three hours, so you might need to try again."));
                $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'account/password');
            } else {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_('Sorry, your passwords either don\'t match, or are too weak'));
                $this->forward(\Idno\Pages\Session\Login::sanitizeRedirectUrl($_SERVER['HTTP_REFERER'] ?? ''));
            }

        }

    }

}

