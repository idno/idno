<?php

    /**
     * Administration page: email settings
     */

namespace Idno\Pages\Admin {

    class EmailTest extends \Idno\Common\Page
    {

        function postContent()
        {
            $this->adminGatekeeper(); // Admins only

            $email = $this->getInput('to_email');

            try {
                $message = new \Idno\Core\Email();
                $message->addTo($email);
                $message->setSubject("Test email from " . \Idno\Core\Idno::site()->config()->title . '!');
                $message->setHTMLBodyFromTemplate('admin/emailtest');
                $message->setTextBodyFromTemplate('admin/emailtest');

                if ($message->send()) {
                    \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("Test email sent to %s", [$email]));
                } else {
                    \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->esc_("There was a problem sending a test message to %s.", [$email]));
                    // A mail setting that cannot be used is named (never its value), so the admin knows what to fix.
                    if (str_starts_with((string)$message->lastFailure(), 'settings: ')) {
                        \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->esc_("The mail setting %s cannot be used. Check it on this page.", [substr($message->lastFailure(), 10)]));
                    }
                }
            } catch (\Exception $e) {
                \Idno\Core\Idno::site()->session()->addErrorMessage($e->getMessage());
            }

            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/email');
        }

    }

}

