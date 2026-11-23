<?php

    /**
     * Create a user
     */

namespace Idno\Pages\Account {

    use Idno\Entities\Invitation;

    /**
     * Default class to serve the registration page
     */
    class Register extends \Idno\Common\Page
    {

        function getContent()
        {
            $this->reverseGatekeeper();
            $code       = $this->getInput('code');
            $email      = $this->getInput('email');
            $onboarding = $this->getInput('onboarding');
            $set_name   = $this->getInput('set_name');

            if (empty(\Idno\Core\Idno::site()->config()->open_registration)) {
                if (!\Idno\Core\Idno::site()->config()->canAddUsers()) {
                    \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("This site is closed to new users."));
                    $this->forward(\Idno\Core\Idno::site()->config()->getURL());
                }
                if (!\Idno\Entities\Invitation::validate($email, $code)) {
                    \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Your invitation doesn't seem to be valid, or has expired."));
                    $this->forward(\Idno\Core\Idno::site()->config()->getURL());
                }
            }

            $t = \Idno\Core\Idno::site()->template();
            if (empty($onboarding)) {
                $t->body  = $t->__(array('email' => $email, 'code' => $code))->draw('account/register');
                $t->title = \Idno\Core\Idno::site()->language()->_('Create a new account');
                $t->sendShellHeaders();
                echo $t->draw('shell');
            } else {
                $t->body  = $t->__(
                    array(
                    'email'    => $email,
                    'code'     => $code,
                    'set_name' => $set_name,
                    'messages' => \Idno\Core\Idno::site()->session()->getAndFlushMessages())
                )->draw('onboarding/register');
                $t->title = \Idno\Core\Idno::site()->language()->_('Create a new account');
                $t->sendSimpleShellHeaders();
                echo $t->draw('shell/simple');
            }
        }

        function postContent()
        {
            $name       = $this->getInput('name');
            $handle     = trim($this->getInput('handle'));
            $password   = trim($this->getInput('password'));
            $password2  = trim($this->getInput('password2'));
            $email      = trim($this->getInput('email'));
            $code       = $this->getInput('code');
            $onboarding = $this->getInput('onboarding');
            $set_name   = $this->getInput('set_name');

            /*if (!\Idno\Common\Page::isSSL() && !\Idno\Core\Idno::site()->config()->disable_cleartext_warning) {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Warning: Access credentials were sent over a non-secured connection! To disable this warning set disable_cleartext_warning in your config.ini"));
            }*/

            if (empty(\Idno\Core\Idno::site()->config()->open_registration)) {
                if (!($invitation = \Idno\Entities\Invitation::validate($email, $code))) {
                    \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Your invitation doesn't seem to be valid, or has expired."));
                    $this->forward(\Idno\Core\Idno::site()->config()->getURL());
                } else {
                    // Removing this from here - invitation will be deleted once user is created
                    //$invitation->delete(); // Remove the invitation; it's no longer needed
                }
            }

            $user = new \Idno\Entities\User();

            if (empty($handle) && empty($email)) {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Please enter a username and email address."));
            } else if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if (!(\Idno\Core\Idno::site()->config()->emailIsBlocked($email))
                    && !($emailuser = \Idno\Entities\User::getByEmail($email))
                    && !($handleuser = \Idno\Entities\User::getByHandle($handle))
                    && !empty($handle) && strlen($handle) <= 32
                    && preg_match('/^[a-zA-Z0-9_]{1,}$/', $handle)
                    && !substr_count($handle, '/')
                    && $password == $password2
                    && \Idno\Entities\User::checkNewPasswordStrength($password)
                    && \Idno\Core\Idno::site()->events()->triggerEvent(
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
                    if (!\Idno\Entities\User::get()) {
                        $user->setAdmin(true);
                        if (\Idno\Core\Idno::site()->config()->title == 'New Idno site') {
                            if (!empty($set_name)) {
                                \Idno\Core\Idno::site()->config()->title = $set_name;
                            } else {
                                \Idno\Core\Idno::site()->config()->title = $user->getTitle() . '\'s Idno';
                            }
                            \Idno\Core\Idno::site()->config()->open_registration = false;
                            \Idno\Core\Idno::site()->config()->from_email        = $user->email;
                            \Idno\Core\Idno::site()->config()->save();
                        }
                        \Idno\Core\Idno::site()->events()->triggerEvent('site/firstadmin', array('user' => $user)); // Event hook for first admin
                    } else {
                        \Idno\Core\Idno::site()->events()->triggerEvent('site/newuser', array('user' => $user)); // Event hook for new user
                    }
                    $user->save(true);
                    // Now we can remove the invitation
                    if (!empty($invitation)) {
                        if ($invitation instanceof Invitation) {
                            $invitation->delete(); // Remove the invitation; it's no longer needed
                        }
                    }
                } else {
                    if (empty($handle)) {
                        \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Please create a username."));
                    }
                    if (strlen($handle) > 32) {
                        \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Your username is too long."));
                    }
                    if (!preg_match('/^[a-zA-Z0-9_]{1,}$/', $handle)) {
                        \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Usernames can only have letters, numbers and underscores."));
                    }
                    if (substr_count($handle, '/')) {
                        \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Usernames can't contain a slash %s character.", ["('/')"]));
                    }
                    if (!empty($handleuser)) {
                        \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Unfortunately, someone is already using that username. Please choose another."));
                    }
                    if (!empty($emailuser)) {
                        \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Hey, it looks like there's already an account with that email address. Did you forget your login?"));
                    }
                    if (!\Idno\Entities\User::checkNewPasswordStrength($password) || $password != $password2) {
                        \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("Please check that your passwords match and that your password is at least 7 characters long."));
                    }
                }
            } else {
                \Idno\Core\Idno::site()->session()->addErrorMessage(\Idno\Core\Idno::site()->language()->_("That doesn't seem like it's a valid email address."));
            }

            if (!empty($user->_id)) {
                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("You've registered! You're ready to get started. Why not add a post to say hello?"));
                \Idno\Core\Idno::site()->session()->logUserOn($user);
                $this->forward();
            } else {
                \Idno\Core\Idno::site()->session()->addMessageAtStart(\Idno\Core\Idno::site()->language()->_("We couldn't register you."));
                $this->forward($_SERVER['HTTP_REFERER']);
            }

        }

    }

}
