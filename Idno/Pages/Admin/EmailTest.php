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

            $lang    = \Idno\Core\Idno::site()->language();
            $session = \Idno\Core\Idno::site()->session();
            $email   = $this->getInput('to_email');
            $email   = is_string($email) ? trim($email) : '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $session->addErrorMessage($lang->_('Enter the email address to send the test to.'));
            } else {
                try {
                    $message = new \Idno\Core\Email();
                    $message->addTo($email);
                    $message->setBodyFromTemplate('admin/emailtest');

                    if ($message->send()) {
                        $session->addMessage($lang->esc_('The mail server accepted a test email for %s. Check that it arrives, and that it is not in the spam folder.', [$email]));
                    } else {
                        // The reason is Email::explanation(): one fixed sentence for the kind of failure and what the mail server
                        // answered, with no user name and no password (and never an exception's text)
                        $session->addErrorMessage($lang->esc_('The test email to %s was not sent. %s', [$email, $message->explanation()]));
                    }
                } catch (\Exception $e) {
                    $session->addErrorMessage(htmlspecialchars(\Idno\Core\ExceptionMessage::forVisitor($e), ENT_QUOTES));
                }
            }

            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/email#test');
        }

    }

}
