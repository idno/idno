<?php

namespace Tests\Pages\Account {

    use Idno\Core\Idno;
    use Idno\Entities\Invitation;
    use Idno\Entities\User;

    /**
     * What keeps an invitation from giving more than its administrator meant: a link that was
     * mailed for one role never becomes a link for another, an administrator's invitation
     * lasts only while whoever made it is an administrator, an invitation makes one account
     * however many requests use it at once, and the page that takes the link gives the code
     * to nobody else.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class InvitationSafeguardsTest extends InvitationTestCase
    {

        /**
         * Post the registration form for a link without opening the link first (which a
         * refused link gives no form for)
         */
        private function registerBlind($link, $handle)
        {
            $jar = $this->visitor();
            $tokens = $this->formFields($this->visit($jar, 'GET', '/session/login')['body']);
            parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

            return $this->visit($jar, 'POST', '/account/register', $tokens + ['name' => 'New ' . $handle, 'handle' => $handle, 'email' => $query['email'], 'code' => $query['code'], 'password' => 'a-good-password-1', 'password2' => 'a-good-password-1']);
        }

        /**
         * A member's invitation link that was mailed (and may sit in a mailbox, a log or a
         * browser's history) must not become an administrator's link when the address is
         * invited again as an administrator.
         */
        public function testInvitingAnAddressAgainInAnotherRoleEndsTheLinkItHad()
        {
            $address = static::address('rolechange');
            $this->invite($address);
            $memberLink = $this->linkFor($address);

            $this->invite($address, ['invitation_role' => 'admin', 'confirm_admin' => '1']);
            $adminLink = $this->linkFor($address);
            $this->assertNotSame($memberLink, $adminLink, 'The administrator invitation has a link of its own');
            $this->assertSame(403, $this->visit($this->visitor(), 'GET', $memberLink)['status'], 'The link mailed for a member no longer works');
            $handle = static::handle();
            $this->registerBlind($memberLink, $handle);
            $this->assertFalse(User::getByHandle($handle), 'Nobody registers with the link mailed for a member');

            // The other way too: the address invited again as a member
            $this->invite($address);
            $this->assertNotSame($adminLink, $this->linkFor($address));
            $this->assertSame(403, $this->visit($this->visitor(), 'GET', $adminLink)['status']);
            $this->assertSame(200, $this->visit($this->visitor(), 'GET', $this->linkFor($address))['status']);
        }

        /**
         * An administrator who has lost the right (or the account) must not go on making
         * administrators through invitations sent while they had it.
         */
        public function testAnAdministratorInvitationEndsWhenWhoeverMadeItIsNoLongerAnAdministrator()
        {
            $inviter = static::member('exadmin', true);
            $address = static::address('fromexadmin');
            $this->submit('/admin/users', ['action' => 'invite_users', 'invitation_emails' => $address, 'invitation_role' => 'admin', 'confirm_admin' => '1'], $inviter);
            $link = $this->linkFor($address);
            $this->assertSame(200, $this->visit($this->visitor(), 'GET', $link)['status']);

            $inviter = static::fresh($inviter);
            $inviter->setAdmin(false);
            $inviter->save(true);

            $page = $this->visit($this->visitor(), 'GET', $link);
            $this->assertSame(403, $page['status'], 'The link is refused, as a withdrawn one is');
            $this->assertStringNotContainsString('name="handle"', $page['body']);
            $handle = static::handle();
            $this->registerBlind($link, $handle);
            $this->assertFalse(User::getByHandle($handle), 'No account, and so no administrator, comes of it');

            // A member's invitation from the same person is not about rights, and stays
            $plain = static::address('plainfromexadmin');
            $invitation = new Invitation();
            $invitation->associateWithEmail($plain);
            $invitation->invited_by = $inviter->getUUID();
            $invitation->save();
            $this->assertInstanceOf(Invitation::class, Invitation::check($plain, $invitation->code)['invitation']);
        }

        /**
         * One invitation must make one account, also when its form is sent several times in
         * the same moment (a double click, or somebody trying).
         */
        public function testALinkUsedBySeveralRequestsAtOnceMakesOneAccount()
        {
            $address = static::address('atonce');
            $this->invite($address);
            $link = $this->linkFor($address);
            parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
            $base = rtrim(Idno::site()->config()->getDisplayURL(), '/');

            $multi = curl_multi_init();
            $handles = [];
            for ($i = 0; $i < 8; $i++) {
                $jar = $this->visitor();
                $form = $this->visit($jar, 'GET', $link);
                $handle = static::handle();
                $curl = curl_init($base . '/account/register');
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_TIMEOUT, 60);
                curl_setopt($curl, CURLOPT_COOKIEFILE, $jar);
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($this->formFields($form['body'], '/account/register') + ['name' => 'At once', 'handle' => $handle, 'email' => $query['email'], 'code' => $query['code'], 'password' => 'a-good-password-1', 'password2' => 'a-good-password-1']));
                curl_multi_add_handle($multi, $curl);
                $handles[$handle] = $curl;
            }
            do {
                curl_multi_exec($multi, $running);
                curl_multi_select($multi, 1);
            } while ($running);

            $made = [];
            foreach ($handles as $handle => $curl) {
                curl_multi_remove_handle($multi, $curl);
                if (User::getByHandle($handle)) {
                    $made[] = $handle;
                }
            }
            curl_multi_close($multi);

            $this->assertCount(1, $made, 'Eight requests with one invitation make one account');
            $this->assertFalse(Invitation::getByEmail($address), 'The invitation is used up');
        }

        /**
         * Of two uses of an invitation only one is told yes, also when the other's delete
         * comes after this one's lookup (the moment two requests at once meet in).
         */
        public function testAnInvitationSomebodyElseJustUsedIsNotUsedAgain()
        {
            $invitation = new Invitation();
            $invitation->associateWithEmail(static::address('claimed'));
            $invitation->save();
            $other = Invitation::unrestricted(
                function () use ($invitation) {
                    return Invitation::getByID($invitation->getID());
                }
            );

            $inner = 'not asked';
            $outer = $invitation->redeem(
                function () use ($other, &$inner) {
                    // The other request arrives while this one is making its account
                    $inner = $other->redeem(
                        function () {
                            return 'a second account';
                        }
                    );

                    return 'the account';
                }
            );

            $this->assertSame('the account', $outer);
            $this->assertNull($inner, 'The second use is told the invitation is gone');
        }

        /**
         * An invited person whose account could not be saved (the database refused it) must
         * be able to try the same link again.
         */
        public function testAnInvitationWhoseAccountWasNotMadeStillWorks()
        {
            $address = static::address('unsaved');
            $invitation = new Invitation();
            $invitation->associateWithEmail($address);
            $invitation->setRole(Invitation::ROLE_ADMIN);
            $invitation->invited_by = static::$admin->getUUID();
            $invitation->save();
            $code = $invitation->code;

            $this->assertFalse($invitation->redeem(
                function () {
                    return false; // The save failed
                }
            ));
            try {
                $invitation->redeem(
                    function () {
                        throw new \RuntimeException('The save blew up');
                    }
                );
                $this->fail('What went wrong is not swallowed');
            } catch (\RuntimeException $e) {
                $this->assertSame('The save blew up', $e->getMessage());
            }

            $again = Invitation::check($address, $code);
            $this->assertInstanceOf(Invitation::class, $again['invitation'], 'The same link still works');
            $this->assertTrue($again['invitation']->isForAdministrator(), 'With the role it had');
            $this->assertCount(1, Invitation::allForEmail($address));
            $this->assertSame('made', $again['invitation']->redeem(
                function () {
                    return 'made';
                }
            ));
            $this->assertFalse(Invitation::getByEmail($address));
        }

        /**
         * The code of an invitation link must not be readable in the page's JSON form, least
         * of all in its JSONP form, which is a script any other site can load.
         */
        public function testTheRegistrationPageAsJsonHoldsNeitherTheCodeNorTheAddress()
        {
            $address = static::address('jsonform');
            $this->invite($address);
            $link = $this->linkFor($address);
            $code = Invitation::getByEmail($address)->code;

            foreach (['json', 'jsonp'] as $type) {
                $page = $this->visit($this->visitor(), 'GET', $link . '&_t=' . $type . '&callback=cb');
                $this->assertSame(200, $page['status'], $type);
                $this->assertStringNotContainsString($code, $page['body'], 'No code in the ' . $type . ' form');
                $this->assertStringNotContainsString($address, $page['body'], 'No address in the ' . $type . ' form');
            }

            // Nor in the answer to a form that was refused
            $jar = $this->visitor();
            $tokens = $this->formFields($this->visit($jar, 'GET', $link)['body'], '/account/register');
            $page = $this->visit($jar, 'POST', '/account/register?_t=json', $tokens + ['handle' => static::handle(), 'email' => $address, 'code' => $code, 'password' => 'short', 'password2' => 'short']);
            $this->assertStringNotContainsString($code, $page['body']);
            $this->assertStringNotContainsString($address, $page['body']);
        }

        /**
         * An address the administrators have blocked since it was invited must not be mailed
         * a fresh invitation by "Send again".
         */
        public function testAnInvitationIsNotSentAgainToAnAddressBlockedSince()
        {
            $address = static::address('blockedlater');
            $this->invite($address);
            $invitation = Invitation::getByEmail($address);
            $expiry = $invitation->getExpiry();
            $invitation->expires = $expiry - 86400;
            $invitation->save();
            static::$sink->reset();

            $config = Idno::site()->config();
            $config->addBlockedEmail($address);
            $config->save();
            try {
                $this->submit('/admin/users', ['action' => 'resend_invitation', 'invitation_id' => (string) $invitation->getID()], static::$admin);
                $this->assertNull(static::$sink->lastTo($address), 'Nothing is mailed to a blocked address');
                $this->assertSame($expiry - 86400, Invitation::getByEmail($address)->getExpiry(), 'And the invitation gets no more time');
                $this->assertStringContainsString('blocked', $this->get('/admin/users', static::$admin)['body'], 'The administrator is told why');
            } finally {
                $config->removeBlockedEmail($address);
                $config->save();
            }
        }

        /**
         * The link holds the code, so the page it opens must not hand its address to other
         * sites in a Referer (an embedded picture, a font, a link somebody follows).
         */
        public function testTheRegistrationPageKeepsItsAddressFromOtherSites()
        {
            $address = static::address('referrer');
            $this->invite($address);
            $link = $this->linkFor($address);

            $page = $this->visit($this->visitor(), 'GET', $link);
            $this->assertSame(200, $page['status']);
            $this->assertMatchesRegularExpression('/^Referrer-Policy:\s*same-origin\s*$/mi', $page['headers']);
            // The page that refuses a link has the same address
            $refused = $this->visit($this->visitor(), 'GET', $link . '0');
            $this->assertSame(403, $refused['status']);
            $this->assertMatchesRegularExpression('/^Referrer-Policy:\s*same-origin\s*$/mi', $refused['headers']);
        }

        /**
         * A site that has all the members it may have (the users/add/check hook says so)
         * must not take more through open registration.
         */
        public function testOpenRegistrationIsClosedWhenTheSiteCanTakeNoMoreMembers()
        {
            $config = Idno::site()->config();
            $open = $config->open_registration;
            $config->open_registration = true;
            $full = true;
            Idno::site()->events()->addListener(
                'users/add/check', function (\Idno\Core\Event $event) use (&$full) {
                    if ($full) {
                        $event->setResponse(false);
                    }
                }
            );
            $page = new class extends \Idno\Pages\Account\Register {
                function admit($email, $code)
                {
                    return $this->admission($email, $code);
                }
            };
            try {
                $this->assertSame('closed', $page->admit('someone@invite-test.example', '')['problem']);
                $full = false;
                $this->assertSame('', $page->admit('someone@invite-test.example', '')['problem'], 'A site with room still takes people');
            } finally {
                $full = false;
                $config->open_registration = $open;
            }
        }
    }

}
