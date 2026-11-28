<?php

namespace Tests\Pages\Account {

    use Idno\Common\Entity;
    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * An account that is being deleted is closed: nobody can use it, by any way of signing in,
     * and nobody but an administrator is shown its profile or its posts, in a list, in a count
     * or at their own address, while the posts are still being removed.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class ClosedAccountTest extends \Tests\BrowserTestCase
    {

        private function signedIn(array $answer)
        {
            // The settings page is drawn for a member and sends anybody else to sign in
            return $answer['status'] === 200;
        }

        /**
         * While an account was being deleted its owner could go on using it, and sign in
         * again. Every way in is closed in the same step.
         */
        public function testAClosedAccountCannotBeUsedByAnyWayOfSigningIn()
        {
            $member = static::member('closedin');
            $jar = $this->jar($member);
            $token = '';
            if (Idno::site()->plugins()->get('IndiePub')) {
                $token = \IdnoPlugins\IndiePub\Pages\IndieAuth\Token::issueToken(static::fresh($member), ['me' => $member->getURL(), 'scope' => 'create', 'client_id' => 'https://client.example/']);
            }
            $signed = $this->signature($member, '/status/edit/?_t=json');
            $bearer = ['Authorization: Bearer ' . $token];

            $this->assertTrue($this->signedIn($this->requestWithJar($jar, 'GET', '/account/settings/')), 'Control: the browser is signed in.');
            $this->assertSame(200, $this->requestWithJar(null, 'GET', '/status/edit/?_t=json', null, $signed)['status'], 'Control: the signed request is the member\'s.');
            if ($token) {
                $this->assertSame(200, $this->requestWithJar(null, 'GET', '/micropub/endpoint?q=config', null, $bearer)['status'], 'Control: the token is taken.');
            }
            $control = Idno::site()->session()->logUserOn(static::fresh($member));
            $this->assertInstanceOf(User::class, $control, 'Control: the account can be logged on.');
            Idno::site()->session()->logUserOff();

            $this->assertTrue(static::fresh($member)->close());

            $closed = static::fresh($member);
            $this->assertFalse($this->signedIn($this->requestWithJar($jar, 'GET', '/account/settings/')), 'A browser session of a closed account ends.');
            $this->logIn($member);
            $this->assertFalse($this->signedIn($this->requestWithJar($jar, 'GET', '/account/settings/')), 'The password of a closed account no longer signs in.');
            $this->assertEmpty(Idno::site()->session()->logUserOn($closed), 'A closed account is not logged on, whoever asks.');
            $this->assertSame('', (string) $closed->getAPIkey(), 'A closed account has no API key, and is given none.');
            // Signed with the key it had, and with the empty key it has now
            foreach ([$signed, ['X-IDNO-USERNAME: ' . $member->getHandle(), 'X-IDNO-SIGNATURE: ' . base64_encode(hash_hmac('sha256', '/status/edit/?_t=json', '', true))]] as $headers) {
                $answer = $this->requestWithJar(null, 'GET', '/status/edit/?_t=json', null, $headers);
                $this->assertNotSame(200, $answer['status'], 'A signed request of a closed account is nobody\'s.');
            }
            if ($token) {
                $this->assertNotSame(200, $this->requestWithJar(null, 'GET', '/micropub/endpoint?q=config', null, $bearer)['status'], 'An access token of a closed account is refused.');
            }

            $visitor = $this->visitorJar();
            $this->requestWithJar($visitor, 'POST', '/account/password', $this->tokenFields($visitor, '/account/password') + ['email' => $member->email]);
            $this->assertEmpty(static::fresh($member)->getPasswordRecoveryCode(), 'A closed account is sent no code to set a new password with.');
        }

        /**
         * While a deletion ran, or after it stopped half-way, the posts that were left stayed
         * on the home page, in feeds and at their addresses.
         */
        public function testTheProfileAndPostsOfAClosedAccountAreShownToAdministratorsOnly()
        {
            $admin = static::member('closedadmin', true);
            $member = static::member('closedposts');
            $other = static::member('closedother');
            $text = static::marker();
            $post = $this->post($member, $text);
            $kept = $this->post($other, static::marker());
            $types = \Idno\Common\ContentType::getRegisteredClasses();
            $this->swapUser(null);

            $this->assertSame(200, $this->get($post->getURL())['status'], 'Control: the post is public.');
            $this->assertStringContainsString($text, $this->get('/')['body'], 'Control: the post is on the home page.');
            $before = Entity::countFromX($types, []);

            $this->assertTrue(static::fresh($member)->close());

            $this->assertSame($before - 1, Entity::countFromX($types, []), 'A count for a visitor leaves the posts of a closed account out.');
            $this->assertSame(0, Entity::countFromX($types, ['owner' => $member->getUUID()]), 'Asking for the closed account\'s posts by their owner finds none.');
            $this->assertEmpty(Entity::getFromX($types, ['owner' => $member->getUUID()]), 'A listing for a visitor leaves them out.');
            $this->assertSame(1, Entity::countFromX($types, ['owner' => $other->getUUID()]), 'Other members\' posts are listed as before.');
            $this->assertStringNotContainsString($text, $this->get('/')['body'], 'The home page no longer shows the post.');
            $this->assertStringNotContainsString($text, $this->get('/?_t=rss')['body'], 'Nor does the feed.');
            $this->assertNotSame(200, $this->get($post->getURL())['status'], 'The post is not shown at its address.');
            $this->assertSame(404, $this->get('/profile/' . $member->getHandle())['status'], 'The profile is missing.');
            $this->assertNotSame(200, $this->get($post->getURL(), $other)['status'], 'Another member is not shown the post either.');

            $this->assertSame(200, $this->get($post->getURL(), $admin)['status'], 'An administrator still sees the post.');
            $this->assertSame(200, $this->get('/profile/' . $member->getHandle(), $admin)['status'], 'An administrator still sees the profile.');
            $this->swapUser($admin);
            $this->assertSame(1, Entity::countFromX($types, ['owner' => $member->getUUID()]), 'An administrator\'s count has the post.');
            $this->swapUser(null);
            $this->assertSame(200, $this->get($kept->getURL())['status']);
        }

        /**
         * The Users page says that an account is being deleted, and lets an administrator
         * carry on with a deletion that stopped.
         */
        public function testTheUsersPageShowsAnAccountBeingDeletedAndCarriesOnWithIt()
        {
            $admin = static::member('closedcontinue', true);
            $member = static::member('closedstuck');
            $this->post($member);
            $this->swapUser(null);
            $this->assertTrue(static::fresh($member)->close());

            // (as the page's script asks: the controls' forms then come with the rows)
            $rows = json_decode($this->get('/search/users/?query=' . urlencode($member->email), $admin, ['X-Requested-With: XMLHttpRequest'])['body'], true);
            $rendered = html_entity_decode((string) ($rows['rendered'] ?? ''), ENT_QUOTES);
            $this->assertStringContainsString('"action":"delete"', $rendered, 'The row of a closed account offers to carry on deleting it.');
            $this->assertStringNotContainsString('"action":"add_rights"', $rendered, 'An account that is being deleted is not offered rights.');

            $jar = $this->jar($admin);
            $this->requestWithJar($jar, 'POST', '/admin/users', $this->tokenFields($jar, '/admin/users') + ['action' => 'delete', 'user' => $member->getUUID()]);

            $this->assertEmpty(static::fresh($member), 'Deleting a closed account again finishes the job.');
        }
    }

}
