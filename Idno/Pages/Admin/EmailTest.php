<?php

    /**
     * Administration page: send a test email with the stored settings
     */

namespace Idno\Pages\Admin {

    class EmailTest extends \Idno\Common\Page
    {

        function postContent()
        {
            $this->adminGatekeeper(); // Admins only

            $lang = \Idno\Core\Idno::site()->language();
            $session = \Idno\Core\Idno::site()->session();
            $email = trim((string) $this->getInput('to_email'));

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $session->addErrorMessage($lang->_('Enter the email address to send the test to.'));
            } else {
                $message = new \Idno\Core\Email();
                $sent = 0;
                try {
                    $message->addTo($email);
                    $message->setBodyFromTemplate('admin/emailtest');
                    $sent = $message->send();
                } catch (\Throwable $e) {
                    list($message->errorKind, $message->error) = \Idno\Core\Email::explain($e);
                }

                if ($sent) {
                    $session->addMessage($lang->esc_('The mail server accepted a test email for %s. Check that it arrives, and that it is not in the spam folder.', [$email]));
                } else {
                    // The reason is written by Email::explain(): no user name, no password
                    $session->addErrorMessage($lang->esc_('The test email to %s was not sent. %s', [$email, $message->error]));
                }
            }

            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/email#test');
        }

    }

}
