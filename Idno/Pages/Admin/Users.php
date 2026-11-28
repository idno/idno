<?php

    /**
     * User administration
     */

namespace Idno\Pages\Admin {

    use Idno\Entities\Invitation;
    use Idno\Entities\User;

    class Users extends \Idno\Common\Page
    {

        /// How many reserved usernames the page lists at a time
        const RESERVED_PER_PAGE = 25;

        /**
         * The invitation the form names (`invitation_id`), and only an invitation: an id of anything else (a post) is none.
         *
         * @return Invitation|null
         */
        protected function invitationFromInput()
        {
            $id = $this->getTextInput('invitation_id');
            if (!preg_match('/^[A-Za-z0-9-]+$/', $id)) {
                return null;
            }
            $found = Invitation::unrestricted(static fn () => Invitation::getByID($id));

            return $found instanceof Invitation ? $found : null;
        }

        function getContent()
        {
            $this->adminGatekeeper(); // Admins only

            $offset = max(0, (int) $this->getTextInput('offset', 0));
            $limit  = (int) $this->getTextInput('limit', 100);
            $limit  = $limit > 0 ? $limit : 100;

            $users = User::getFromX(["Idno\\Entities\\User", "Idno\\Entities\\RemoteUser"], [], [], $limit, $offset);
            $count = User::countFromX(["Idno\\Entities\\User", "Idno\\Entities\\RemoteUser"]);

            $invitations = Invitation::all();
            $reservedOffset = max(0, (int) $this->getTextInput('reserved_offset', 0));
            $reserved       = array_map(static fn ($r) => ['handle' => (string) $r->display, 'at' => (int) $r->created], \Idno\Entities\ReservedHandle::page(self::RESERVED_PER_PAGE, $reservedOffset));

            $t        = \Idno\Core\Idno::site()->template();
            $t->body  = $t->__(array('items' => $users, 'invitations' => $invitations, 'mail_configured' => \Idno\Core\Email::isConfigured(), 'invitation_days' => Invitation::lifetimeDays(), 'invitation_batch' => Invitation::MAX_PER_BATCH, 'count' => $count, 'items_per_page' => $limit, 'reserved_handles' => $reserved, 'reserved_total' => \Idno\Entities\ReservedHandle::total(), 'reserved_offset' => $reservedOffset, 'reserved_per_page' => self::RESERVED_PER_PAGE))->draw('admin/users');
            $t->title = \Idno\Core\Idno::site()->language()->_('User Management');
            $t->drawPage();

        }

        function postContent()
        {

            $this->adminGatekeeper(); // Admins only

            $action = $this->getTextInput('action');

            switch ($action) {
                case 'add_rights':
                    $uuid = $this->getTextInput('user');
                    if (($user = User::getByUUID($uuid)) instanceof User) {
                        $user->setAdmin(true);
                        $user->save();
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("%s was given administration rights.", [$user->getTitle()]));
                    }
                    break;
                case 'remove_rights':
                    $uuid = $this->getTextInput('user');
                    if (($user = User::getByUUID($uuid)) instanceof User && $this->mayDemoteOrDelete($user)) {
                        $user->setAdmin(false);
                        $user->save();
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("%s was stripped of their administration rights.", [$user->getTitle()]));
                    }
                    break;
                case 'delete':
                    $uuid = $this->getTextInput('user');
                    if (($user = User::getByUUID($uuid)) instanceof User && $this->mayDemoteOrDelete($user)) {
                        if ($user->delete()) {
                            \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("%s was removed from your site.", [$user->getTitle()]));
                        }
                    }
                    break;
                case 'release_handle':
                    $handle = trim($this->getTextInput('handle'));
                    if ($handle !== '' && \Idno\Core\AccountRegistry::releaseHandle($handle)) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("The username %s can be registered again.", [$handle]));
                    }
                    break;
                case 'invite_users':
                    $this->inviteUsers();
                    break;
                case 'remove_invitation':
                    $invitation = $this->invitationFromInput();
                    if ($invitation && $invitation->delete()) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->esc_("The invitation for %s was withdrawn. Its link no longer works.", [$invitation->email]));
                    }

                    break;
                case 'resend_invitation':
                    if ($invitation = $this->invitationFromInput()) {
                        $this->resendInvitation($invitation);
                    }

                    break;
                case 'add_user':

                    if (!\Idno\Core\Idno::site()->config()->canAddUsers()) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("You can't add any more users to your site."));
                        break;
                    }

                    $name      = $this->getTextInput('name');
                    $handle    = trim($this->getTextInput('handle'));
                    $email     = trim($this->getTextInput('email'));
                    $password  = trim($this->getTextInput('password1'));
                    $password2 = trim($this->getTextInput('password2'));

                    $user = new \Idno\Entities\User();

                    if (empty($password) || $password != $password2 || strlen($password) > \Idno\Security\Passwords::MAX_BYTES) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Please make sure your passwords match and aren't empty."));
                    } else if (empty($handle) && empty($email)) {
                        \Idno\Core\Idno::site()->session()->addMessage(\Idno\Core\Idno::site()->language()->_("Please enter a username and email address."));
                    } else if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        if (!($emailuser = \Idno\Entities\User::getByEmail($email))
                        && !($handleuser = \Idno\Entities\User::getByHandle($handle) ?: \Idno\Core\AccountRegistry::handleIsReserved($handle))
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
                    $emails = $this->getTextInput('blocked_emails');
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
                    $emails = $this->getTextInput('blocked_emails');
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

        /**
         * Whether the administrator who asks may take this member's rights away or delete the account: not their own, and
         * not those of the last administrator. The page does not offer either for oneself, but a request can still ask.
         * Says why when the answer is no.
         *
         * @param  User $user
         * @return bool
         */
        protected function mayDemoteOrDelete(User $user)
        {
            $why = $user->whyNotRemovableBy(\Idno\Core\Idno::site()->session()->currentUserUUID());
            if ($why === '') {
                return true;
            }
            $user->explainWhyNotRemovable($why);

            return false;
        }

        /**
         * Send an invitation again: the same invitation, with its role, its time starting again (and a new code if the
         * old one had expired). The address is checked as the invite form checks it: an address that has been blocked
         * since, or that has an account now, gets nothing.
         *
         * @param Invitation $invitation
         */
        protected function resendInvitation(Invitation $invitation)
        {
            $site  = \Idno\Core\Idno::site();
            $lang  = $site->language();
            $email = (string) $invitation->email;
            if ($site->config()->emailIsBlocked($email)) {
                $site->session()->addErrorMessage($lang->esc_("%s is blocked from this site, so the invitation was not sent.", [$email]));

                return;
            }
            if (User::getByEmail($email)) {
                $site->session()->addErrorMessage($lang->esc_("%s is already a member, so the invitation was not sent.", [$email]));

                return;
            }
            // As the invite form: a link to a site that cannot take another member would only be refused
            if (!$site->config()->canAddUsers()) {
                $site->session()->addErrorMessage($lang->_("You can't add any more users to your site."));

                return;
            }
            $invitation->renew();
            $invitation->save();
            if ($invitation->send()) {
                $site->session()->addMessage($lang->esc_("The invitation was sent to %s again.", [$email]));
            } else {
                $site->session()->addErrorMessage($lang->esc_("The invitation for %s is still good, but the email was not sent. %s You can copy its link from the list below and send it yourself.", [$email, $invitation->sendError]));
            }
        }

        /**
         * Invite the addresses typed into the invite form, as members or as administrators. An invitation is made and
         * kept even when its email cannot be sent: the administrator is told why, and can copy the link from the list.
         * Whether it makes an administrator is the stored role, which only this form sets, and asking for it needs the
         * box that says what administrators can do ticked.
         */
        protected function inviteUsers()
        {
            $site    = \Idno\Core\Idno::site();
            $lang    = $site->language();
            $session = $site->session();
            $inviter = $session->currentUser();

            $role = $this->getTextInput('invitation_role') === Invitation::ROLE_ADMIN ? Invitation::ROLE_ADMIN : Invitation::ROLE_MEMBER;
            if ($role === Invitation::ROLE_ADMIN && !$this->getTextInput('confirm_admin')) {
                $session->addErrorMessage($lang->_("Nobody was invited. To invite someone as an administrator, tick the box that says you understand what administrators can do."));

                return;
            }

            list($addresses, $invalid) = Invitation::parseAddresses($this->getTextInput('invitation_emails'));
            if ($invalid) {
                $session->addErrorMessage($lang->esc_("These don't look like email addresses and were skipped: %s", [implode(', ', $invalid)]));
            }
            if (!$addresses) {
                $session->addErrorMessage($lang->_("No email addresses were found. Enter one or more addresses, separated by commas or new lines."));

                return;
            }
            if (count($addresses) > Invitation::MAX_PER_BATCH) {
                $session->addErrorMessage($lang->esc_("Nobody was invited: that is %d addresses, and at most %d can be invited at once.", [count($addresses), Invitation::MAX_PER_BATCH]));

                return;
            }
            if (!$site->config()->canAddUsers()) {
                $session->addErrorMessage($lang->_("You can't add any more users to your site."));

                return;
            }

            $sent    = [];
            $unsent  = [];
            $members = [];
            $blocked = [];
            $reason  = '';
            foreach ($addresses as $email) {
                if ($site->config()->emailIsBlocked($email)) {
                    $blocked[] = $email;
                    continue;
                }
                if (User::getByEmail($email)) {
                    $members[] = $email;
                    continue;
                }
                // One invitation per address: inviting again renews the one there is, with the role asked for now
                $invitation = Invitation::getByEmail($email);
                if (!$invitation) {
                    $invitation = new Invitation();
                    if (!$invitation->associateWithEmail($email)) {
                        continue;
                    }
                }
                $invitation->renew();
                $invitation->setRole($role);
                $invitation->inviter_name  = $inviter->getTitle();
                $invitation->invited_by    = $inviter->getUUID();
                $invitation->inviter_email = (string) $inviter->email;
                $invitation->save();
                if ($invitation->send()) {
                    $sent[] = $email;
                } else {
                    $unsent[] = $email;
                    $reason   = $invitation->sendError;
                }
            }

            if ($sent) {
                if ($role === Invitation::ROLE_ADMIN) {
                    $session->addMessage($lang->esc_("Invited as administrators: %s", [implode(', ', $sent)]));
                } else {
                    $session->addMessage($lang->esc_("Invited: %s", [implode(', ', $sent)]));
                }
            }
            if ($unsent) {
                $session->addErrorMessage($lang->esc_("Invitations were made for %s, but the email was not sent. %s You can copy each invitation's link from the list below and send it yourself.", [implode(', ', $unsent), $reason]));
            }
            if ($members) {
                $session->addMessage($lang->esc_("Already members, so not invited: %s", [implode(', ', $members)]));
            }
            if ($blocked) {
                $session->addErrorMessage($lang->esc_("These addresses are blocked from this site, so they were not invited: %s", [implode(', ', $blocked)]));
            }
        }

    }
}
