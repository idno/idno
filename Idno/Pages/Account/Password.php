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

            // The body is drawn on a copy of the page's template: what it is given is no variable of the page, which a JSON request prints
            $body = $t->withVars(array_merge($t->vars, ['mail_configured' => Email::isConfigured()]));
            if ($sent = $this->getInput('sent')) {
                $t->body  = $body->draw('account/password/sent');
                $t->title = \Idno\Core\Idno::site()->language()->_('Password recovery email sent');
            } else {
                $t->body  = $body->draw('account/password');
                $t->title = \Idno\Core\Idno::site()->language()->_('Recover password');
            }

            static::drawInTheSitesShell();

        }

        /**
         * Draw the page the template holds in the site's own shell with its headers and the session's messages, and end the request.
         *
         * drawPage() would pick the account settings shell for an address under /account/, which is for people who are signed in; the exact shell
         * does that for the web page type only, so another type is chosen as for any page and a JSONP request is still answered as the HTML settings
         * page, never as a script. The request ends here: a legacy page that returns is forwarded home by Page::post().
         *
         * @param int $status The status the answer has
         */
        public static function drawInTheSitesShell($status = 0)
        {
            $status = $status ?: (http_response_code() ?: 200);
            $t      = \Idno\Core\Idno::site()->template();
            $page   = $t->renderPage(
                $status, 'shell', static function (array $headers) {
                    foreach ($headers as [$name, $value]) {
                        header($name . ': ' . $value, \Idno\Templating\ShellHeaders::replaces($name));
                    }
                }, true
            );
            echo $page['body'];
            exit;
        }

        function postContent()
        {

            $this->reverseGatekeeper();
            $email_address = $this->getTextInput('email'); // A text: a field sent as an array is an empty one

            // An account that is being deleted, and an address the site has blocked, are sent no code: the answer is the same
            if (($user = User::getByEmail($email_address)) && !$user->isClosed() && !\Idno\Core\Idno::site()->config()->emailIsBlocked($user->email)) {

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

