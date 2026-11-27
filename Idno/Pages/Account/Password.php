<?php

    /**
     * Recover a forgotten password
     */

namespace Idno\Pages\Account {

    use Idno\Core\Email;
    use Idno\Entities\User;

    /**
     * Default class to serve the password recovery page
     */
    class Password extends \Idno\Common\Page
    {

        function getContent()
        {

            $this->reverseGatekeeper();
            $t = \Idno\Core\Idno::site()->template();

            if ($sent = $this->getInput('sent')) {
                $t->body  = $t->draw('account/password/sent');
                $t->title = \Idno\Core\Idno::site()->language()->_('Password recovery email sent');
            } else {
                $t->body  = $t->draw('account/password');
                $t->title = \Idno\Core\Idno::site()->language()->_('Recover password');
            }

            $t->sendShellHeaders();
            echo $t->draw('shell');

        }

        function postContent()
        {

            $this->reverseGatekeeper();
            $email_address = $this->getInput('email');

            if ($user = User::getByEmail($email_address)) {

                if ($auth_code = $user->addPasswordRecoveryCode()) {

                    $user->save(); // Save the recovery code to the user

                    $email = new Email();
                    $email->addTo($user->email);
                    $email->setBodyFromTemplate('account/password', array('email' => $email_address, 'code' => $auth_code));
                    $email->send();

                }

            }

            // Always show the same response to prevent user enumeration
            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'account/password/?sent=true');

        }

    }

}

