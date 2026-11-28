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
         * The address of an invitation link holds its code, and the form's page has that
         * address: the browser must not pass it to another site in a Referer (a picture or
         * a font the page loads from elsewhere, a link somebody follows). "same-origin"
         * rather than "no-referrer", because the first-run form comes back to the page it
         * was sent from, which it knows by the Referer of its own POST.
         */
        protected function keepAddressPrivate()
        {
            if (!headers_sent()) {
                header('Referrer-Policy: same-origin');
            }
        }

        /**
         * The page has drawn its own answer: the request ends here. Page::post() would
         * otherwise go on to redirect a POST that has not been redirected, which turns the
         * answer into a 302 wherever the output has not left the server yet.
         */
        protected function finish()
        {
            exit;
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

            // A site that can take no more members takes none, invited or not
            if (!$config->canAddUsers()) {
                return ['invitation' => null, 'problem' => 'closed'];
            }

            // A code is always checked, also on a site anyone can join: it may make an administrator
            if (!empty($code) || !$open) {
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
            $this->finish();
        }

        function getContent()
        {
            $this->signedOutOnly();
            $this->keepAddressPrivate();
            // Texts: a field sent as an array is an empty one
            $code       = $this->getTextInput('code');
            $email      = $this->getTextInput('email');
            $onboarding = !empty($this->getInput('onboarding')); // A flag, which the first-run pages set themselves
            $set_name   = $this->getTextInput('set_name');

            $admission = $this->admission($email, $code);
            if ($admission['problem']) {
                $this->refuse($admission['problem']);

                return;
            }

            $t = \Idno\Core\Idno::site()->template();
            if (empty($onboarding)) {
                $this->drawForm($admission['invitation'], ['email' => $email, 'code' => $code]);
            } else {
                // Drawn with a copy of the template (see drawForm())
                $form = clone $t;
                $t->body  = $form->__(
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
         * @param bool            $end        Whether the request ends with the form (the
         *                                    answer to a POST)
         */
        protected function drawForm($invitation, array $values, $end = false)
        {
            $t = \Idno\Core\Idno::site()->template();
            // The form is drawn with a copy of the template: what goes into its fields (the
            // invitation's code and address) must not become variables of the page, which
            // its JSON and JSONP forms print, and JSONP is a script any site can load
            $form = clone $t;
            $t->body  = $form->__(
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
            if ($end) {
                $this->finish();
            }
        }

        function postContent()
        {
            $this->signedOutOnly();
            $this->keepAddressPrivate();
            // Texts: a field sent as an array is an empty one
            $name       = $this->getTextInput('name');
            $handle     = trim($this->getTextInput('handle'));
            $password   = trim($this->getTextInput('password'));
            $password2  = trim($this->getTextInput('password2'));
            $email      = trim($this->getTextInput('email'));
            $code       = $this->getTextInput('code');
            $onboarding = !empty($this->getInput('onboarding')); // A flag, which the first-run pages set themselves
            $set_name   = $this->getTextInput('set_name');

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
                    && !($handleuser = (\Idno\Entities\User::getByHandle($handle) ?: \Idno\Core\AccountRegistry::handleIsReserved($handle)))
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
                    $make = function () use ($site, $invitation, $email, $handle, $password, $name, $set_name) {
                        $made         = new \Idno\Entities\User();
                        $made->email  = $email;
                        $made->handle = strtolower(trim($handle)); // Trim the handle and set it to lowercase
                        $made->setPassword($password);
                        if (empty($name)) {
                            $name = $made->handle;
                        }
                        $made->setTitle($name);
                        if (!\Idno\Entities\User::get()) {
                            $made->setAdmin(true);
                            $made->robot_state = '1';
                            if ($site->config()->title == 'New Idno site') {
                                if (!empty($set_name)) {
                                    $site->config()->title = $set_name;
                                } else {
                                    $site->config()->title = $made->getTitle() . '\'s Idno';
                                }
                                $site->config()->open_registration = false;
                                $site->config()->from_email        = $made->email;
                                $site->config()->save();
                            }
                            $site->events()->triggerEvent('site/firstadmin', array('user' => $made)); // Event hook for first admin
                        } else {
                            // The role comes from the invitation as it is stored, never from the request
                            if ($invitation && $invitation->isForAdministrator()) {
                                $made->setAdmin(true);
                            }
                            $site->events()->triggerEvent('site/newuser', array('user' => $made)); // Event hook for new user
                        }

                        return $made->save(true) ? $made : false;
                    };
                    if ($invitation) {
                        // An invitation is used once: of several requests with it, the one
                        // that claims it makes the account, and an account that could not
                        // be saved leaves the invitation as it was (Invitation::redeem())
                        $made = $invitation->redeem($make);
                        if ($made === null) {
                            $this->refuse('invalid');

                            return;
                        }
                    } else {
                        $made = $make();
                    }
                    if ($made) {
                        $user = $made;
                    }
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
                $this->drawForm($invitation, ['email' => $email, 'code' => $code, 'name' => $name, 'handle' => $handle], true);
            }

        }

    }

}
