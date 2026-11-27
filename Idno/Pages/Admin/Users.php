<?php

    /**
     * User administration
     */

namespace Idno\Pages\Admin {

    use Idno\Entities\Invitation;
    use Idno\Entities\User;

    class Users extends \Idno\Common\Page
    {

        /**
         * The invitation with an id, and only an invitation: an id of anything else (a post) is none.
         *
         * @param  mixed $id
         * @return Invitation|null
         */
        private static function invitation($id)
        {
            $found = Invitation::unrestricted(static fn () => Invitation::getByID($id));

            return $found instanceof Invitation ? $found : null;
        }

        function getContent()
        {
            $this->adminGatekeeper(); // Admins only

            $offset = $this->getInput('offset', 0);
            $limit = $this->getInput('limit', 100);

            $users = User::getFromX(["Idno\\Entities\\User", "Idno\\Entities\\RemoteUser"], [], [], $limit, $offset);
            $count = User::countFromX(["Idno\\Entities\\User", "Idno\\Entities\\RemoteUser"]);

            $invitations = Invitation::all();

            $t        = \Idno\Core\Idno::site()->template();
            $t->body  = $t->__(array('items' => $users, 'invitations' => $invitations, 'count' => $count, 'items_per_page' => $limit))->draw('admin/users');
            $t->title = \Idno\Core\Idno::site()->language()->_('User Management');
            $t->drawPage();

        }

        function postContent()
        {

            $this->adminGatekeeper(); // Admins only

            $action = $this->getInput('action');

            switch ($action) {
                case 'add_rights':
                    $uuid = $this->getInput('user');
                    if ($user = User::getByUUID($uuid)) {
                        $user->setAdmin(true);
                        $user->save();
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("%s was given administration rights.", [$user->getTitle()]));
                    }
                    break;
                case 'remove_rights':
                    $uuid = $this->getInput('user');
                    if ($user = User::getByUUID($uuid)) {
                        $user->setAdmin(false);
                        $user->save();
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("%s was stripped of their administration rights.", [$user->getTitle()]));
                    }
                    break;
                case 'delete':
                    $uuid = $this->getInput('user');
                    if ($user = User::getByUUID($uuid)) {
                        if ($user->delete()) {
                            \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("%s was removed from your site.", [$user->getTitle()]));
                        }
                    }
                    break;
                case 'invite_users':
                    $emails = $this->getInput('invitation_emails');

                    preg_match_all('/[a-z\d._%\+\-]+@[a-z\d.-]+\.[a-z]{2,4}\b/i', $emails, $matches);

                    $invitation_count = 0;
                    $invitations_sent = 0;
                    if (!empty($matches[0])) {
                        if (is_array($matches[0])) {
                            foreach ($matches[0] as $email) {
                                if (!($user = User::getByEmail($email))) {
                                    $invitation = new Invitation();
                                    if ($invitation->sendToEmail($email, \Idno\Core\Idno::site()->session()->currentUser()->email) !== 0) {
                                        $invitations_sent++;
                                    }
                                    $invitation_count++;
                                }
                            }
                        }
                    }

                    if ($invitation_count > 1) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("%d invitations were sent.", [$invitation_count]));
                    } else if ($invitation_count == 1) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Your invitation was sent."));
                    } else if ($invitations_sent == 0 && $invitation_count > 0) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Something went wrong and we couldn't send emails to your recipients."));
                    } else {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("No email addresses were found or all the people you invited are already members of this site."));
                    }
                    break;
                case 'remove_invitation':
                    $invitation_id = $this->getInput('invitation_id');

                    if ($invitation = self::invitation($invitation_id)) {
                        if ($invitation->delete()) {
                            \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("The invitation was removed."));
                        }
                    }

                    break;
                case 'resend_invitation':

                    $invitation_id = $this->getInput('invitation_id');

                    if ($invitation = self::invitation($invitation_id)) {
                        $email = $invitation->email;
                        if ($invitation->delete()) {
                            $new_invitation = new Invitation();
                            if ($new_invitation->sendToEmail($email)) {
                                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("The invitation was resent."));
                            }
                        }
                    }

                    break;
                case 'add_user':

                    if (!\Idno\Core\Idno::site()->config()->canAddUsers()) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("You can't add any more users to your site."));
                        break;
                    }

                    $name      = $this->getInput('name');
                    $handle    = trim($this->getInput('handle'));
                    $email     = trim($this->getInput('email'));
                    $password  = trim($this->getInput('password1'));
                    $password2 = trim($this->getInput('password2'));

                    $user = new \Idno\Entities\User();

                    if (empty($password) || $password != $password2) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Please make sure your passwords match and aren't empty."));
                    } else if (empty($handle) && empty($email)) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Please enter a username and email address."));
                    } else if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        if (!($emailuser = \Idno\Entities\User::getByEmail($email))
                        && !($handleuser = \Idno\Entities\User::getByHandle($handle))
                        && !empty($handle) && strlen($handle) <= 32
                        && !substr_count($handle, '/')
                        ) {
                            $user         = new \Idno\Entities\User();
                            $user->email  = $email;
                            $user->handle = strtolower(trim($handle)); // Trim the handle and set it to lowercase
                            $user->setPassword($password);
                            if (empty($name)) {
                                $name = $user->handle;
                            }
                            $user->setTitle($name);
                            $user->save();
                        } else {
                            if (empty($handle)) {
                                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Please create a username."));
                            }
                            if (strlen($handle) > 32) {
                                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Your username is too long."));
                            }
                            if (substr_count($handle, '/')) {
                                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Usernames can't contain a slash %s character.", ["('/')"]));
                            }
                            if (!empty($handleuser)) {
                                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Unfortunately, someone is already using that username. Please choose another."));
                            }
                            if (!empty($emailuser)) {
                                \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Hey, it looks like there's already an account with that email address. Did you forget your login?"));
                            }
                        }
                    } else {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("That doesn't seem like it's a valid email address."));
                    }

                    if (!empty($user->_id)) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("User %s was created. You may wish to email them to let them know.", [$user->getHandle()]));
                    } else {
                        \Idno\Core\Idno::site()->session()->addMessageAtStart("We couldn't register that user.");
                    }
                    break;
                case 'block_emails':
                    $emails = $this->getInput('blocked_emails');
                    preg_match_all('/[a-z\d._%+-]+@[a-z\d.-]+\.[a-z]{2,4}\b/i', $emails, $matches);

                    $block_count = 0;

                    if (!empty($matches[0])) {
                        if (is_array($matches[0])) {
                            foreach ($matches[0] as $email) {
                                if (\Idno\Core\Idno::site()->config()->addBlockedEmail($email)) {
                                    $block_count++;
                                }
                            }
                            \Idno\Core\Idno::site()->config()->save();
                        }
                    }

                    if ($block_count > 1) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("%d emails were blocked.", [$block_count]));
                    } else if ($block_count == 1) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("The email address was blocked."));
                    } else {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("No email addresses were found."));
                    }
                    break;
                case 'unblock_emails':
                    $emails = $this->getInput('blocked_emails');
                    preg_match_all('/[a-z\d._%+-]+@[a-z\d.-]+\.[a-z]{2,4}\b/i', $emails, $matches);

                    $block_count = 0;

                    if (!empty($matches[0])) {
                        if (is_array($matches[0])) {
                            foreach ($matches[0] as $email) {
                                if (\Idno\Core\Idno::site()->config()->removeBlockedEmail($email)) {
                                    $block_count++;
                                }
                            }
                            \Idno\Core\Idno::site()->config()->save();
                        }
                    }

                    if ($block_count > 1) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("%d emails were unblocked.", [$block_count]));
                    } else if ($block_count == 1) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("The email address was unblocked."));
                    } else {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("No email addresses were found."));
                    }
                    break;
            }

            $this->forward(\Idno\Core\Idno::site()->config()->getURL() . 'admin/users');

        }

    }
}
