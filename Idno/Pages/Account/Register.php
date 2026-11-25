<?php

    /**
     * Create a user
     */

namespace Idno\Pages\Account {

    use Idno\Entities\Invitation;

    /**
     * Default class to serve the registration page.
     *
     * On a site that is closed to registration the page works only with an invitation: the
     * address and the code of the link an administrator sent. The page is public, so that an
     * invited person reaches it on a private site too; it shows nothing of the site.
     *
     * The invitation decides the email address of the account and whether it is an
     * administrator's. Neither is read from the form.
     */
    class Register extends \Idno\Common\Page
    {

        /**
         * Somebody who is signed in has no use for this page
         */
        protected function signedOutOnly()
        {
            $session = \Idno\Core\Idno::site()->session();
            if ($session->isLoggedIn()) {
                $session->addMessage(\Idno\Core\Idno::site()->language()->esc_("You're already signed in as %s. To create another account, sign out first.", [$session->currentUser()->getTitle()]));
                $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL());
            }
        }

        /**
         * Work out whether this visitor may register, and with which invitation.
         *
         * @param  string $email
         * @param  string $code
         * @return array 'invitation' (Invitation|null) and 'problem': '' when they may register,
         *               'closed', 'invalid' or 'expired' when they may not
         */
        protected function admission($email, $code)
        {
            $config = \Idno\Core\Idno::site()->config();
            $open = !empty($config->open_registration);

            // A code is always checked, also on a site anyone can join: it may make an administrator
            if (!empty($code) || !$open) {
                if (!$config->canAddUsers()) {
                    return ['invitation' => null, 'problem' => 'closed'];
                }
                if (empty($code) && !$open) {
                    return ['invitation' => null, 'problem' => 'closed'];
                }

                return Invitation::check($email, $code);
            }

            return ['invitation' => null, 'problem' => ''];
        }

        /**
         * Tell a visitor who may not register why, on a page of its own (the home page of a
         * private site would only tell them to sign in)
         */
        protected function refuse($problem)
        {
            $t = \Idno\Core\Idno::site()->template();
            $this->setResponse(403);
            http_response_code(403);
            $t->body  = $t->__(['problem' => $problem])->draw('account/register/refused');
            $t->title = \Idno\Core\Idno::site()->language()->_('Create a new account');
            // The plain shell, with its messages: drawPage() would pick the account settings
            // shell for an address under /account/, which is for people who are signed in
            $t->messages = \Idno\Core\Idno::site()->session()->getAndFlushMessages();
            echo $t->draw('shell');
        }

        function getContent()
        {
            $this->signedOutOnly();
            $code       = $this->getInput('code');
            $email      = $this->getInput('email');
            $onboarding = $this->getInput('onboarding');
            $set_name   = $this->getInput('set_name');

            $admission = $this->admission($email, $code);
            if ($admission['problem']) {
                $this->refuse($admission['problem']);

                return;
            }

            $t = \Idno\Core\Idno::site()->template();
            if (empty($onboarding)) {
                $this->drawForm($admission['invitation'], ['email' => $email, 'code' => $code]);
            } else {
                $t->body  = $t->__(
                    array(
                    'email'    => $email,
                    'code'     => $code,
                    'set_name' => $set_name,
                    'messages' => \Idno\Core\Idno::site()->session()->getAndFlushMessages())
                )->draw('onboarding/register');
                $t->title = \Idno\Core\Idno::site()->language()->_('Create a new account');
                echo $t->draw('shell/simple');
            }
        }

        /**
         * Draw the registration form.
         *
         * @param Invitation|null $invitation The invitation being accepted, if any
         * @param array           $values     What goes into the fields: email, code, name, handle
         *                                    (never a password)
         */
        protected function drawForm($invitation, array $values)
        {
            $t = \Idno\Core\Idno::site()->template();
            $t->body  = $t->__(
                [
                    'email'    => $invitation ? (string) $invitation->email : (string) ($values['email'] ?? ''),
                    'code'     => (string) ($values['code'] ?? ''),
                    'name'     => (string) ($values['name'] ?? ''),
                    'handle'   => (string) ($values['handle'] ?? ''),
                    'invited'  => (bool) $invitation,
                    'is_admin' => $invitation ? $invitation->isForAdministrator() : false,
                    'inviter'  => $invitation ? $invitation->getInviterName() : '',
                ]
            )->draw('account/register');
            $t->title = \Idno\Core\Idno::site()->language()->_('Create a new account');
            // The plain shell, with its messages: drawPage() would pick the account settings
            // shell for an address under /account/, which is for people who are signed in
            $t->messages = \Idno\Core\Idno::site()->session()->getAndFlushMessages();
            echo $t->draw('shell');
        }

        function postContent()
        {
            $this->signedOutOnly();
            $name       = $this->getInput('name');
            $handle     = trim($this->getInput('handle'));
            $password   = trim($this->getInput('password'));
            $password2  = trim($this->getInput('password2'));
            $email      = trim($this->getInput('email'));
            $code       = $this->getInput('code');
            $onboarding = $this->getInput('onboarding');
            $set_name   = $this->getInput('set_name');

            $site = \Idno\Core\Idno::site();
            $lang = $site->language();
            $session = $site->session();

            $admission = $this->admission($email, $code);
            if ($admission['problem']) {
                $this->refuse($admission['problem']);

                return;
            }
            $invitation = $admission['invitation'];
            if ($invitation) {
                // The invitation is for one address, and that is the address of the account
                $email = (string) $invitation->email;
            }

            $user = new \Idno\Entities\User();

            if (empty($handle) && empty($email)) {
                $session->addErrorMessage($lang->_("Please enter a username and email address."));
            } else if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if (!($blocked = $site->config()->emailIsBlocked($email))
                    && !($emailuser = \Idno\Entities\User::getByEmail($email))
                    && !($handleuser = \Idno\Entities\User::getByHandle($handle))
                    && !empty($handle) && strlen($handle) <= 32
                    && preg_match('/^[a-zA-Z0-9_]{1,}$/', $handle)
                    && !substr_count($handle, '/')
                    && $password == $password2
                    && \Idno\Entities\User::checkNewPasswordStrength($password)
                    && $site->events()->triggerEvent(
                        'user/register/validate', [
                        'name' => $name,
                        'handle' => $handle,
                        'email' => $email,
                        'password' => $password,
                        'password2' => $password2
                        ], true
                    )
                ) {
                    // An invitation is used once: whoever uses it up first gets the account
                    if ($invitation && !$invitation->consume()) {
                        $this->refuse('invalid');

                        return;
                    }

                    $user         = new \Idno\Entities\User();
                    $user->email  = $email;
                    $user->handle = strtolower(trim($handle)); // Trim the handle and set it to lowercase
                    $user->setPassword($password);
                    if (empty($name)) {
                        $name = $user->handle;
                    }
                    $user->setTitle($name);
                    if (!\Idno\Entities\User::get()) {
                        $user->setAdmin(true);
                        $user->robot_state = '1';
                        if ($site->config()->title == 'New Idno site') {
                            if (!empty($set_name)) {
                                $site->config()->title = $set_name;
                            } else {
                                $site->config()->title = $user->getTitle() . '\'s Idno';
                            }
                            $site->config()->open_registration = false;
                            $site->config()->from_email        = $user->email;
                            $site->config()->save();
                        }
                        $site->events()->triggerEvent('site/firstadmin', array('user' => $user)); // Event hook for first admin
                    } else {
                        // The role comes from the invitation as it is stored, never from the request
                        if ($invitation && $invitation->isForAdministrator()) {
                            $user->setAdmin(true);
                        }
                        $site->events()->triggerEvent('site/newuser', array('user' => $user)); // Event hook for new user
                    }
                    $user->save(true);
                } else {
                    if (!empty($blocked)) {
                        $session->addErrorMessage($lang->_("An account can't be created with that email address."));
                    }
                    if (empty($handle)) {
                        $session->addErrorMessage($lang->_("Please create a username."));
                    }
                    if (strlen($handle) > 32) {
                        $session->addErrorMessage($lang->_("Your username is too long."));
                    }
                    if (!empty($handle) && !preg_match('/^[a-zA-Z0-9_]{1,}$/', $handle)) {
                        $session->addErrorMessage($lang->_("Usernames can only have letters, numbers and underscores."));
                    }
                    if (!empty($handleuser)) {
                        $session->addErrorMessage($lang->_("Unfortunately, someone is already using that username. Please choose another."));
                    }
                    if (!empty($emailuser)) {
                        $session->addErrorMessage($lang->_("Hey, it looks like there's already an account with that email address. Did you forget your login?"));
                    }
                    if (!\Idno\Entities\User::checkNewPasswordStrength($password) || $password != $password2) {
                        $session->addErrorMessage($lang->_("Please check that your passwords match and that your password is at least 7 characters long."));
                    }
                }
            } else {
                $session->addErrorMessage($lang->_("That doesn't seem like it's a valid email address."));
            }

            if (!empty($user->_id)) {
                if ($user->isAdmin() && $invitation) {
                    $session->addMessage($lang->_("Welcome! Your account is ready, and you are an administrator of this site."));
                } else {
                    $session->addMessage($lang->_("You've registered! You're ready to get started. Why not add a post to say hello?"));
                }
                $session->logUserOn($user);
                $this->forward();
            } else if (!empty($onboarding)) {
                $session->addMessageAtStart($lang->_("We couldn't register you."));
                $this->forward(\Idno\Pages\Session\Login::sanitizeRedirectUrl($_SERVER['HTTP_REFERER'] ?? ''));
            } else {
                // Back to the form, with what was typed (never the passwords). The invitation
                // has not been used.
                $session->addMessageAtStart($lang->_("We couldn't register you."), 'alert-danger');
                $this->setResponse(400);
                http_response_code(400);
                $this->drawForm($invitation, ['email' => $email, 'code' => $code, 'name' => $name, 'handle' => $handle]);
            }

        }

    }

}
