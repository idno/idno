<?php

    /**
     * Create a user
     */

namespace Idno\Pages\Account {

    use Idno\Entities\Invitation;

    /**
     * Default class to serve the registration page.
     *
     * On a site that is closed to registration the page works only with an invitation: the address and the code of the
     * link an administrator sent. The page is public, so that an invited person reaches it on a private site too; it
     * shows nothing of the site.
     *
     * The invitation decides the email address of the account and whether it is an administrator's. Neither is read
     * from the form. Nobody becomes an administrator by registering unless the stored invitation, which only an
     * administrator can make, says so; a site with no account at all registers nobody (its first administrator is made
     * by the installation).
     */
    class Register extends \Idno\Common\Page
    {

        /**
         * Somebody who is signed in has no use for this page.
         *
         * @return bool True when the visitor was sent away: the caller returns
         */
        protected function signedOutOnly(): bool
        {
            $session = \Idno\Core\Idno::site()->session();
            if ($session->isLoggedIn()) {
                $session->addMessage(\Idno\Core\Idno::site()->language()->esc_("You're already signed in as %s. To create another account, sign out first.", [$session->currentUser()->getTitle()]));
                $this->forward(\Idno\Core\Idno::site()->config()->getDisplayURL());

                return true;
            }

            return false;
        }

        /**
         * Work out whether this visitor may register, and with which invitation.
         *
         * @param  string $email
         * @param  string $code
         * @return array 'invitation' (Invitation|null) and 'problem': '' when they may register, 'closed', 'invalid' or
         *               'expired' when they may not
         */
        protected function admission($email, $code)
        {
            $config = \Idno\Core\Idno::site()->config();
            $open   = !empty($config->open_registration);

            // A code is always checked, also on a site anyone can join: it may make an administrator
            if ($code !== '' || !$open) {
                if (!$config->canAddUsers()) {
                    return ['invitation' => null, 'problem' => 'closed'];
                }
                if ($code === '' && !$open) {
                    return ['invitation' => null, 'problem' => 'closed'];
                }

                return Invitation::check($email, $code);
            }

            return ['invitation' => null, 'problem' => ''];
        }

        /**
         * Tell a visitor who may not register why, on a page of its own (the home page of a private site would only
         * tell them to sign in).
         *
         * @param string $problem 'closed', 'invalid' or 'expired'
         */
        protected function refuse($problem)
        {
            $this->setResponse(403);
            http_response_code(403);
            $this->drawGate(fn ($t) => $this->onACopy($t, ['problem' => $problem])->draw('account/register/refused'));
        }

        /**
         * The page's template with these values for the body to draw. They go to the body's own draw, on a copy: the
         * invitation's code is a credential, and the JSON and JSONP output of a page prints every variable of its
         * template.
         *
         * @param  \Idno\Core\DefaultTemplate $t
         * @param  array                       $vars
         * @return \Idno\Core\DefaultTemplate
         */
        private function onACopy($t, array $vars)
        {
            return $t->withVars(array_merge($t->vars, $vars));
        }

        /**
         * Draw a page of this address in the plain shell, with the session's messages: drawPage() would pick the
         * account settings shell for an address under /account/, which is for people who are signed in.
         *
         * @param callable $body Given the page's template, draws the body (`$this->onACopy($t, [...])->draw('...')`)
         */
        private function drawGate(callable $body)
        {
            $t = \Idno\Core\Idno::site()->template();
            // The link of an invitation carries its code in the query: no page this one links to is told where it came from
            header('Referrer-Policy: no-referrer');
            $t->body     = $body($t);
            $t->title    = \Idno\Core\Idno::site()->language()->_('Create a new account');
            $t->messages = \Idno\Core\Idno::site()->session()->getAndFlushMessages();
            $t->sendShellHeaders();
            echo $t->draw('shell');
            // The answer is made: the router's own forward after a POST (`Page::post()`) would add a redirect to what was drawn
            exit;
        }

        function getContent()
        {
            if ($this->signedOutOnly() || $this->refuseWithoutAnAdministrator()) {
                return;
            }
            // Texts: a field sent as an array is an empty one
            $code  = $this->getTextInput('code');
            $email = $this->getTextInput('email');

            $admission = $this->admission($email, $code);
            if ($admission['problem']) {
                $this->refuse($admission['problem']);

                return;
            }

            $this->drawForm($admission['invitation'], ['email' => $email, 'code' => $code]);
        }

        /**
         * Draw the registration form.
         *
         * @param Invitation|null $invitation The invitation being accepted, if any
         * @param array           $values     What goes into the fields: email, code, name, handle (never a password)
         */
        protected function drawForm($invitation, array $values)
        {
            $this->drawGate(
                fn ($t) => $this->onACopy(
                    $t, [
                        'email'    => $invitation ? (string) $invitation->email : (string) ($values['email'] ?? ''),
                        'code'     => (string) ($values['code'] ?? ''),
                        'name'     => (string) ($values['name'] ?? ''),
                        'handle'   => (string) ($values['handle'] ?? ''),
                        'invited'  => (bool) $invitation,
                        'is_admin' => $invitation ? $invitation->makesAnAdministrator() : false,
                        'inviter'  => $invitation ? $invitation->getInviterName() : '',
                    ]
                )->draw('account/register')
            );
        }

        /**
         * Registration never makes a site's first administrator: that is the last step of the
         * installation (Idno\Install\SetupGateListener, or `php bin/console idno:admin:create`),
         * which only the person who installed the site can reach. On a site with no account at all
         * nobody can register: the visitor is sent to the home page, which says the site is being set up.
         */
        private function refuseWithoutAnAdministrator(): bool
        {
            if (!\Idno\Entities\User::get()) {
                $this->forward(\Idno\Core\Idno::site()->config()->getURL());

                return true; // the caller returns: a request that cannot be redirected must not register
            }

            return false;
        }

        /**
         * Use up the invitation (if there is one) and save the account, in one step of the database: the invitation is
         * claimed first, so of two registrations with one link only one gets that far, and if the account cannot be
         * saved the claim is undone with it. An invitation is never spent without an account being made, and an
         * account is never made on an invitation another registration has used.
         *
         * @param  \Idno\Entities\User $user       The account to make, not yet saved
         * @param  Invitation|null     $invitation What it is made on
         * @return string 'made', 'used' (somebody else used the invitation first, or it was withdrawn) or 'failed' (the
         *                account could not be saved)
         */
        private function makeAccount(\Idno\Entities\User $user, $invitation): string
        {
            $site    = \Idno\Core\Idno::site();
            $outcome = 'failed';
            $site->db()->atomically(
                function () use ($site, $user, $invitation, &$outcome) {
                    if ($invitation && !$invitation->consume()) {
                        $outcome = 'used';

                        return false;
                    }
                    // The role comes from the invitation as it is stored (consume() reads it again), never from the request
                    if ($invitation && $invitation->makesAnAdministrator()) {
                        $user->setAdmin(true);
                    }
                    $site->events()->triggerEvent('site/newuser', array('user' => $user)); // Event hook for new user
                    if (!$user->save(true)) {
                        return false;
                    }
                    $outcome = 'made';

                    return true;
                }
            );

            return $outcome;
        }

        function postContent()
        {
            if ($this->signedOutOnly() || $this->refuseWithoutAnAdministrator()) {
                return;
            }
            // Texts: a field sent as an array is an empty one
            $name      = $this->getTextInput('name');
            $handle    = trim($this->getTextInput('handle'));
            $password  = trim($this->getTextInput('password'));
            $password2 = trim($this->getTextInput('password2'));
            $email     = trim($this->getTextInput('email'));
            $code      = $this->getTextInput('code');

            $site    = \Idno\Core\Idno::site();
            $lang    = $site->language();
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
            $made = false;

            if (empty($handle) && empty($email)) {
                $session->addErrorMessage($lang->_("Please enter a username and email address."));
            } else if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if (!($blocked = $site->config()->emailIsBlocked($email))
                    && !($emailuser = \Idno\Entities\User::getByEmail($email))
                    && !($handleuser = \Idno\Entities\User::getByHandle($handle))
                    && !empty($handle) && strlen($handle) <= 32
                    && preg_match('/^[a-zA-Z0-9_]{1,}$/', $handle)
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
                    $user         = new \Idno\Entities\User();
                    $user->email  = $email;
                    $user->handle = strtolower(trim($handle)); // Trim the handle and set it to lowercase
                    $user->setPassword($password);
                    if (empty($name)) {
                        $name = $user->handle;
                    }
                    $user->setTitle($name);
                    $outcome = $this->makeAccount($user, $invitation);
                    if ($outcome === 'used') {
                        $this->refuse('invalid');

                        return;
                    }
                    $made = $outcome === 'made';
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

            if ($made) {
                if ($user->isAdmin() && $invitation) {
                    $session->addMessage($lang->_("Welcome! Your account is ready, and you are an administrator of this site."));
                } else {
                    $session->addMessage($lang->_("You've registered! You're ready to get started. Why not add a post to say hello?"));
                }
                $session->logUserOn($user);
                $this->forward();
            } else {
                // Back to the form, with what was typed (never the passwords). The invitation has not been used.
                $session->addMessageAtStart($lang->_("We couldn't register you."), 'alert-danger');
                $this->setResponse(400);
                http_response_code(400);
                $this->drawForm($invitation, ['email' => $email, 'code' => $code, 'name' => $name, 'handle' => $handle]);
            }

        }

    }

}
