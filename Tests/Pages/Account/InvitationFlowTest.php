<?php

namespace Tests\Pages\Account {

    use Idno\Core\Idno;
    use Idno\Entities\Invitation;
    use Idno\Entities\User;
    use Tests\SmtpSink;

    /**
     * Invitations from end to end, over HTTP, on a private site that is closed to
     * registration: an administrator invites people as members or as administrators, the
     * mail says who invited them and as what, the link opens a registration page that a
     * visitor can reach (and nothing else of the site), the invitation decides the email
     * address and the role of the account, and it works once, for a limited time.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT). Mail goes to a local sink (Tests\SmtpSink).
     */
    class InvitationFlowTest extends InvitationTestCase
    {

        public function testOnlyAnAdministratorCanInvite()
        {
            $address = static::address('nobody');
            $tokens = $this->formFields($this->get('/account/settings/', static::$member)['body']);
            $this->assertCount(3, $tokens);
            $response = $this->request('POST', '/admin/users', $tokens + ['action' => 'invite_users', 'invitation_emails' => $address, 'invitation_role' => 'admin', 'confirm_admin' => '1'], static::$member);
            $this->assertSame(403, $response['status']);

            // Not with an administrator's API key either, and not without a form token
            $this->request('POST', '/admin/users', ['action' => 'invite_users', 'invitation_emails' => $address], null, $this->signature(static::$admin, '/admin/users'));
            $this->request('POST', '/admin/users', ['action' => 'invite_users', 'invitation_emails' => $address], static::$admin);

            $this->assertFalse(Invitation::getByEmail($address));
            $this->assertNull(static::$sink->lastTo($address));
        }

        public function testAMemberInvitationMailSaysWhoInvitedAndHoldsOneLink()
        {
            $address = static::address('member');
            $response = $this->invite('Somebody <' . strtoupper($address) . '>');
            $this->assertSame(302, $response['status']);

            $message = static::$sink->lastTo($address);
            $this->assertNotNull($message, 'The address is stored and mailed in lower case');
            $this->assertStringContainsString('The Press Room <site@example.com>', $message['headers']['from']);
            $this->assertStringContainsString(Idno::site()->config()->title, $message['headers']['subject']);
            // Both parts, and the inviter's name as text in each
            $this->assertStringContainsString('text/plain', $message['data']);
            $this->assertStringContainsString('text/html', $message['data']);
            $this->assertStringContainsString('Ada &lt;img src=x onerror=alert(1)&gt; Admin', $message['text']);
            $this->assertStringNotContainsString('<img src=x', preg_replace('/^.*?text\/html/s', '', $message['text']), 'No markup from the name in the HTML part');
            $this->assertStringNotContainsString('administrator', $message['text']);
            $this->assertStringContainsString('works until', $message['text']);

            // One link, to the registration page of this site, in both parts of the mail
            $link = $this->linkFor($address);
            $this->assertSame(1, preg_match('#https?://[^/\s]+/account/register/\?email=\S+?&code=[0-9a-f]{64}#', $message['text'], $inText));
            $this->assertStringContainsString('href="' . htmlspecialchars($inText[0]) . '"', $message['text'], 'The button of the HTML part goes to the same address');
            $this->assertSame('/account/register/', parse_url($link, PHP_URL_PATH));

            $invitation = Invitation::getByEmail($address);
            $this->assertInstanceOf(Invitation::class, $invitation);
            $this->assertSame(Invitation::ROLE_MEMBER, $invitation->getRole());
            $this->assertSame(64, strlen($invitation->code));
            $this->assertEqualsWithDelta(time() + Invitation::LIFETIME_DAYS * 86400, $invitation->getExpiry(), 120);
        }

        public function testAnAdministratorInvitationNeedsConfirmingAndSaysSo()
        {
            $address = static::address('boss');
            $this->invite($address, ['invitation_role' => 'admin']);
            $this->assertFalse(Invitation::getByEmail($address), 'Nobody is invited as an administrator without the confirmation');
            $this->assertNull(static::$sink->lastTo($address));
            $this->assertStringContainsString('Nobody was invited', $this->get('/admin/users', static::$admin)['body']);

            $this->invite($address, ['invitation_role' => 'admin', 'confirm_admin' => '1']);
            $invitation = Invitation::getByEmail($address);
            $this->assertInstanceOf(Invitation::class, $invitation);
            $this->assertTrue($invitation->isForAdministrator());
            $message = static::$sink->lastTo($address);
            $this->assertStringContainsString('as an administrator', $message['text']);

            // The acceptance page says so too
            $page = $this->visit($this->visitor(), 'GET', $this->linkFor($address));
            $this->assertSame(200, $page['status']);
            $this->assertStringContainsString('invited as an administrator', $page['body']);
        }

        public function testTheLinkOpensARegistrationPageOnAPrivateSiteAndNothingElse()
        {
            $address = static::address('walled');
            $this->invite($address);
            $link = $this->linkFor($address);
            $jar = $this->visitor();

            $page = $this->visit($jar, 'GET', $link);
            $this->assertSame(200, $page['status']);
            $this->assertMatchesRegularExpression('/<input[^>]*name="email"[^>]*value="' . preg_quote($address, '/') . '"[^>]*readonly/s', $page['body'], 'The address is filled in and fixed');
            $this->assertMatchesRegularExpression('/<input[^>]*name="code"[^>]*value="[0-9a-f]{64}"/', $page['body']);
            $this->assertStringContainsString('Ada &lt;img src=x onerror=alert(1)&gt; Admin', $page['body']);
            $this->assertStringNotContainsString('<img src=x', $page['body']);

            // What the page needs loads for a visitor
            $this->assertSame(1, preg_match('/<link[^>]*rel="stylesheet"[^>]*href="([^"]+)"/', $page['body'], $match));
            $this->assertSame(200, $this->visit($jar, 'GET', html_entity_decode($match[1]))['status']);

            // The rest of the site does not
            foreach (['/', '/content/all/', '/profile/' . static::$admin->getHandle(), '/?_t=rss', '/admin/users', '/account/settings/'] as $path) {
                $this->assertSame(403, $this->visit($jar, 'GET', $path)['status'], $path . ' is closed to a visitor of a private site');
            }
        }

        public function testAcceptingMakesTheAccountOfTheInvitationSignsItInAndUsesTheInvitationUp()
        {
            $address = static::address('joiner');
            $this->invite($address);
            $link = $this->linkFor($address);
            $jar = $this->visitor();
            $handle = static::handle();

            // The form cannot make an administrator
            $response = $this->register($jar, $link, $handle, ['admin' => '1', 'role' => 'admin', 'invitation_role' => 'admin', 'is_admin' => '1']);
            $this->assertSame(302, $response['status']);

            $user = User::getByHandle($handle);
            $this->assertInstanceOf(User::class, $user);
            $this->assertSame($address, $user->email);
            $this->assertFalse($user->isAdmin());

            // Signed in: the private site opens
            $home = $this->visit($jar, 'GET', '/');
            $this->assertSame(200, $home['status']);
            $this->assertStringContainsString('registered', $home['body']);

            // Used up
            $this->assertFalse(Invitation::getByEmail($address));
            $again = $this->visit($this->visitor(), 'GET', $link);
            $this->assertSame(403, $again['status']);
            $this->assertStringContainsString("can&#039;t be used", str_replace("can't be used", "can&#039;t be used", $again['body']));
        }

        public function testAnAdministratorInvitationMakesAnAdministrator()
        {
            $address = static::address('newadmin');
            $this->invite($address, ['invitation_role' => 'admin', 'confirm_admin' => '1']);
            $jar = $this->visitor();
            $handle = static::handle();

            $this->assertSame(302, $this->register($jar, $this->linkFor($address), $handle)['status']);
            $user = User::getByHandle($handle);
            $this->assertInstanceOf(User::class, $user);
            $this->assertTrue($user->isAdmin());
            $this->assertSame(200, $this->visit($jar, 'GET', '/admin/users')['status']);
        }

        public function testTheAccountGetsTheAddressOfTheInvitationWhateverTheFormSends()
        {
            $address = static::address('bound');
            $this->invite($address);
            $link = $this->linkFor($address);

            // Another address with the code of this invitation is no invitation
            $jar = $this->visitor();
            $handle = static::handle();
            $response = $this->register($jar, $link, $handle, ['email' => static::address('other')]);
            $this->assertSame(403, $response['status']);
            $this->assertFalse(User::getByHandle($handle));
            $this->assertInstanceOf(Invitation::class, Invitation::getByEmail($address), 'The invitation is still there');
        }

        public function testAnInvitationWorksOnceEvenWhenTheFormIsSentTwice()
        {
            $address = static::address('twice');
            $this->invite($address);
            $link = $this->linkFor($address);

            // Two tabs, each with the form open
            $first = $this->visitor();
            $second = $this->visitor();
            $firstForm = $this->visit($first, 'GET', $link);
            $secondForm = $this->visit($second, 'GET', $link);
            $this->assertSame(200, $secondForm['status']);
            parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
            $fields = ['email' => $query['email'], 'code' => $query['code'], 'password' => 'a-good-password-1', 'password2' => 'a-good-password-1'];

            $one = static::handle();
            $two = static::handle();
            $a = $this->visit($first, 'POST', '/account/register', $this->formFields($firstForm['body'], '/account/register') + $fields + ['handle' => $one, 'name' => 'One']);
            $b = $this->visit($second, 'POST', '/account/register', $this->formFields($secondForm['body'], '/account/register') + $fields + ['handle' => $two, 'name' => 'Two']);

            $this->assertSame(302, $a['status']);
            $this->assertSame(403, $b['status']);
            $this->assertInstanceOf(User::class, User::getByHandle($one));
            $this->assertFalse(User::getByHandle($two));
        }

        public function testAFormThatIsRefusedKeepsTheInvitationAndWhatWasTyped()
        {
            $address = static::address('typo');
            $this->invite($address);
            $link = $this->linkFor($address);
            $jar = $this->visitor();
            $handle = static::handle();

            $response = $this->register($jar, $link, $handle, ['password' => 'short', 'password2' => 'short']);
            $this->assertSame(400, $response['status']);
            $this->assertStringContainsString('at least 7 characters', $response['body']);
            $this->assertStringContainsString('value="' . $handle . '"', $response['body']);
            $this->assertStringNotContainsString('value="short"', $response['body']);
            $this->assertFalse(User::getByHandle($handle));

            $this->assertSame(302, $this->register($jar, $link, $handle)['status'], 'The invitation still works');
        }

        public function testAWrongAnExpiredAndAWithdrawnInvitationAreRefusedWithAClearPage()
        {
            $address = static::address('refused');
            $this->invite($address);
            $link = $this->linkFor($address);

            // A wrong code, no code, a code of the wrong shape
            foreach ([preg_replace('/code=[0-9a-f]{8}/', 'code=00000000', $link), preg_replace('/&code=.*/', '', $link), preg_replace('/code=.*/', 'code[]=x', $link)] as $bad) {
                $page = $this->visit($this->visitor(), 'GET', $bad);
                $this->assertSame(403, $page['status'], $bad);
                $this->assertStringNotContainsString('name="handle"', $page['body']);
                $this->assertStringContainsString('session/login', $page['body']);
            }

            // Too old
            $invitation = Invitation::getByEmail($address);
            $invitation->expires = time() - 60;
            Invitation::unrestricted(
                function () use ($invitation) {
                    $invitation->save();
                }
            );
            $page = $this->visit($this->visitor(), 'GET', $link);
            $this->assertSame(403, $page['status']);
            $this->assertStringContainsString('has expired', $page['body']);
            $jar = $this->visitor();
            $handle = static::handle();
            $tokens = $this->formFields($this->visit($jar, 'GET', '/session/login')['body']);
            parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
            $post = $this->visit($jar, 'POST', '/account/register', $tokens + ['handle' => $handle, 'email' => $query['email'], 'code' => $query['code'], 'password' => 'a-good-password-1', 'password2' => 'a-good-password-1']);
            $this->assertFalse(User::getByHandle($handle), 'No account from an expired invitation (status ' . $post['status'] . ')');

            // Sent again by the administrator: a new link works, the old code does not
            $this->submit('/admin/users', ['action' => 'resend_invitation', 'invitation_id' => (string) $invitation->getID()], static::$admin);
            $fresh = $this->linkFor($address);
            $this->assertNotSame($link, $fresh);
            $this->assertSame(200, $this->visit($this->visitor(), 'GET', $fresh)['status']);
            $this->assertSame(403, $this->visit($this->visitor(), 'GET', $link)['status']);

            // Withdrawn
            $this->submit('/admin/users', ['action' => 'remove_invitation', 'invitation_id' => (string) $invitation->getID()], static::$admin);
            $this->assertFalse(Invitation::getByEmail($address));
            $this->assertSame(403, $this->visit($this->visitor(), 'GET', $fresh)['status']);
        }

        public function testSendingAgainKeepsTheRoleAndTheLink()
        {
            $address = static::address('again');
            $this->invite($address, ['invitation_role' => 'admin', 'confirm_admin' => '1']);
            $link = $this->linkFor($address);
            $invitation = Invitation::getByEmail($address);
            static::$sink->reset();

            $this->submit('/admin/users', ['action' => 'resend_invitation', 'invitation_id' => (string) $invitation->getID()], static::$admin);
            $this->assertSame($link, $this->linkFor($address), 'An invitation that has not expired keeps its link');
            $this->assertTrue(Invitation::getByEmail($address)->isForAdministrator());
            $this->assertCount(1, Invitation::allForEmail($address));

            // Inviting the same address again changes the one invitation
            $this->invite($address);
            $this->assertCount(1, Invitation::allForEmail($address));
            $this->assertFalse(Invitation::getByEmail($address)->isForAdministrator());
        }

        public function testMembersBlockedAddressesAndNonsenseAreNotInvited()
        {
            $blocked = static::address('blocked');
            $config = Idno::site()->config();
            $config->addBlockedEmail($blocked);
            $config->save();
            try {
                $fine = static::address('fine');
                $this->invite(static::$member->email . ', ' . $blocked . "\n" . $fine . '; not-an-address@');
                $page = $this->get('/admin/users', static::$admin)['body'];

                $this->assertFalse(Invitation::getByEmail(static::$member->email));
                $this->assertFalse(Invitation::getByEmail($blocked));
                $this->assertInstanceOf(Invitation::class, Invitation::getByEmail($fine));
                $this->assertNull(static::$sink->lastTo($blocked));
                $this->assertStringContainsString('Already members', $page);
                $this->assertStringContainsString('blocked from this site', $page);
                $this->assertStringContainsString('not-an-address@', $page);
            } finally {
                $config->removeBlockedEmail($blocked);
                $config->save();
            }
        }

        public function testTooManyAddressesAtOnceInviteNobody()
        {
            $addresses = [];
            for ($i = 0; $i <= Invitation::MAX_PER_BATCH; $i++) {
                $addresses[] = 'bulk' . $i . 'x' . static::address('');
            }
            $this->invite(implode(', ', $addresses));
            $this->assertStringContainsString('at most ' . Invitation::MAX_PER_BATCH, $this->get('/admin/users', static::$admin)['body']);
            $this->assertFalse(Invitation::getByEmail($addresses[0]));
            $this->assertCount(0, static::$sink->messages());
        }

        public function testWhenTheMailCannotBeSentTheInvitationIsMadeAndItsLinkCanBeCopied()
        {
            // A mail server that is not there
            $dead = SmtpSink::start();
            $port = $dead->port();
            $dead->stop();
            static::$sink->configure(['smtp_port' => $port, 'from_email' => 'site@example.com', 'smtp_password' => 'SG.a-secret', 'smtp_username' => 'apikey']);

            $address = static::address('unsent');
            $this->invite($address);
            $page = $this->get('/admin/users', static::$admin)['body'];
            $this->assertStringContainsString('the email was not sent', $page);
            $this->assertStringContainsString('refused the connection', $page);
            $this->assertStringNotContainsString('SG.a-secret', $page);

            $invitation = Invitation::getByEmail($address);
            $this->assertInstanceOf(Invitation::class, $invitation);
            $this->assertMatchesRegularExpression('#value="https?://[^/"]+' . preg_quote(htmlspecialchars('/account/register/?email=' . urlencode($address) . '&code=' . $invitation->code), '#') . '"#', $page, 'The link is on the page to copy');
            $this->assertStringContainsString('email not sent', $page);

            // And the link works
            $this->assertSame(200, $this->visit($this->visitor(), 'GET', $invitation->getLink())['status']);
        }

        public function testTheInviteFormWarnsWhenNoMailServiceIsSetUp()
        {
            static::$sink->configure(['smtp_host' => '']);
            $this->assertStringContainsString('No mail service is set up', $this->get('/admin/users', static::$admin)['body']);
            $this->assertStringContainsString("isn't set up to send email", str_replace('&#039;', "'", $this->visit($this->visitor(), 'GET', '/account/password')['body']));

            static::$sink->configure();
            $this->assertStringNotContainsString('No mail service is set up', $this->get('/admin/users', static::$admin)['body']);
        }

        public function testThePendingListShowsAddressRoleInviterAndExpiryEscaped()
        {
            $address = static::address('listed');
            $this->invite($address, ['invitation_role' => 'admin', 'confirm_admin' => '1']);
            foreach (['', '?_t=default'] as $type) {
                $page = $this->get('/admin/users' . $type, static::$admin)['body'];
                $this->assertStringContainsString($address, $page);
                $this->assertStringContainsString('Administrator', $page);
                $this->assertStringContainsString('Invited by Ada &lt;img src=x onerror=alert(1)&gt; Admin', $page);
                $this->assertStringNotContainsString('<img src=x', $page);
                $this->assertStringContainsString('expires', $page);
            }
        }

        public function testAnInvitationIsReadableByAdministratorsOnly()
        {
            $address = static::address('hidden');
            $this->invite($address);
            $invitation = Invitation::getByEmail($address);
            $id = (string) $invitation->getID();

            foreach ([null, static::$member] as $viewer) {
                foreach (['/view/' . $id, '/view/' . $id . '?_t=json', '/s/' . $id] as $path) {
                    $page = $this->get($path, $viewer);
                    $this->assertNotSame(200, $page['status'], $path);
                    $this->assertStringNotContainsString($invitation->code, $page['body']);
                    $this->assertStringNotContainsString($address, $page['body']);
                }
            }
            $this->assertSame([], $invitation->jsonSerialize());
            // The administrators' page in the forms other sites can load holds no code
            foreach (['?_t=jsonp', '?_t=rss'] as $type) {
                $this->assertStringNotContainsString($invitation->code, $this->get('/admin/users' . $type, static::$member)['body']);
            }
        }

        public function testSomeoneSignedInIsSentHomeFromAnInvitationLink()
        {
            $address = static::address('signedin');
            $this->invite($address);
            $response = $this->get($this->linkFor($address), static::$member);
            $this->assertSame(302, $response['status']);
            $this->assertInstanceOf(Invitation::class, Invitation::getByEmail($address));
        }

        public function testAVisitorToThePrivateSiteIsToldItIsPrivateAndHowToGetIn()
        {
            $page = $this->visit($this->visitor(), 'GET', '/');
            $this->assertSame(403, $page['status']);
            $this->assertStringContainsString('session/login', $page['body']);
            if (Idno::site()->template()->getTemplateType() === 'modern' || strpos($page['body'], 'is private') !== false) {
                $this->assertStringContainsString('is private', $page['body']);
                $this->assertStringContainsString('invitation', $page['body']);
            }
            $this->assertSame(200, $this->visit($this->visitor(), 'GET', '/session/login')['status']);

            // Registration without an invitation is refused with a page, not a redirect to a closed door
            $refused = $this->visit($this->visitor(), 'GET', '/account/register');
            $this->assertSame(403, $refused['status']);
            $this->assertStringContainsString('need an invitation', $refused['body']);
        }

        public function testAPasswordCanBeResetOnThePrivateSiteWithoutSigningIn()
        {
            $member = static::member('resetme');
            $jar = $this->visitor();

            $form = $this->visit($jar, 'GET', '/account/password');
            $this->assertSame(200, $form['status']);
            $response = $this->visit($jar, 'POST', '/account/password', $this->formFields($form['body'], '/account/password') + ['email' => $member->email]);
            $this->assertSame(302, $response['status']);
            $this->assertSame(200, $this->visit($jar, 'GET', static::onThisSite($response['location']))['status']);

            $message = static::$sink->lastTo($member->email);
            $this->assertNotNull($message);
            $this->assertStringContainsString(Idno::site()->config()->title, $message['headers']['subject']);
            $this->assertSame(1, preg_match('#https?://\S+/account/password/reset/\?email=\S+?&code=[0-9a-f]+#', $message['text'], $match));
            $this->assertStringContainsString('text/plain', $message['data']);
            $this->assertStringContainsString('text/html', $message['data']);
            $this->assertStringContainsString('within three hours', $message['text']);
            $this->assertStringContainsString('you can ignore this email', $message['text']);

            $reset = static::onThisSite($match[0]);
            $page = $this->visit($jar, 'GET', $reset);
            $this->assertSame(200, $page['status']);
            parse_str((string) parse_url($reset, PHP_URL_QUERY), $query);
            $done = $this->visit($jar, 'POST', '/account/password/reset', $this->formFields($page['body'], '/account/password/reset') + ['email' => $query['email'], 'code' => $query['code'], 'password' => 'a-new-password-2', 'password2' => 'a-new-password-2']);
            $this->assertSame(302, $done['status']);
            $this->assertStringContainsString('/session/login', $done['location'], 'On to the sign-in page, not to the closed front door');
            $this->assertSame(200, $this->visit($jar, 'GET', static::onThisSite($done['location']))['status']);

            // The new password signs in
            static::$passwords[$member->getHandle()] = 'a-new-password-2';
            $this->assertSame(200, $this->get('/', $member)['status']);
        }
    }

}
