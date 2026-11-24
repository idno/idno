<?php

namespace Tests\API {

    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * An API request (one signed with the user's API key, or one that carries an access
     * token) acts as the user on the site's pages, but not on the account pages: the pages
     * under /account/, where the password, the email address, the API key and the access
     * tokens are. A key that has leaked must not be enough to take the account over.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class AccountPagesTest extends \Tests\IdnoTestCase
    {

        /// @var User
        private static $member;

        private static $password;

        /// File holding the cookies of the logged-in browser
        private static $cookies;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            static::$password = bin2hex(random_bytes(12));

            $user = new User();
            $user->handle = 'apiaccount' . substr(md5(rand() . microtime(true)), 0, 8);
            $user->email = $user->handle . '@example.com';
            $user->setPassword(static::$password);
            $user->setTitle('API Account Test User');
            $user->save(true);
            $user->getAPIkey();
            static::$member = $user;

            static::$cookies = tempnam(sys_get_temp_dir(), 'idno-api-account-test-');
        }

        public static function tearDownAfterClass(): void
        {
            if (static::$member) {
                $old = Idno::site()->session()->currentUser();
                Idno::site()->session()->logUserOn(static::$member);
                Idno::site()->db()->setIgnoreAccess(true);
                foreach (\Idno\Common\Entity::getFromAll(['owner' => static::$member->getUUID()], [], 100) ?: [] as $entity) {
                    $entity->delete();
                }
                Idno::site()->db()->setIgnoreAccess(false);
                Idno::site()->session()->logUserOff();
                if ($old) {
                    Idno::site()->session()->logUserOn($old);
                }
                static::$member->delete();
                static::$member = null;
            }
            if (static::$cookies && file_exists(static::$cookies)) {
                unlink(static::$cookies);
            }
            parent::tearDownAfterClass();
        }

        // -----------------------------------------------------------------
        // Signed requests to account pages
        // -----------------------------------------------------------------

        public function testSignedRequestDoesNotChangeAccountSettings()
        {
            $marker = $this->marker();
            $fields = ['name' => $marker, 'email' => $marker . '@example.com', 'password' => 'A new passphrase 9 ' . $marker];

            $result = $this->request('POST', '/account/settings', $fields, $this->signature('/account/settings'));

            $member = $this->freshMember();
            $this->assertEquals(403, $result['status'], 'A signed request to an account page should be refused.');
            $this->assertEquals('API Account Test User', $member->getTitle(), "A signed request must not change the user's name.");
            $this->assertEquals(static::$member->email, $member->email, "A signed request must not change the user's email address.");
            $this->assertTrue($member->checkPassword(static::$password), "A signed request must not change the user's password.");
        }

        public function testSignedRequestWithTheOlderHeadersDoesNotChangeAccountSettings()
        {
            $marker = $this->marker();
            $headers = [
                'X-KNOWN-USERNAME: ' . static::$member->getHandle(),
                'X-KNOWN-SIGNATURE: ' . base64_encode(hash_hmac('sha256', '/account/settings/', $this->freshMember()->getAPIkey(), true)),
            ];

            $result = $this->request('POST', '/account/settings/', ['name' => $marker], $headers);

            $this->assertEquals(403, $result['status']);
            $this->assertEquals('API Account Test User', $this->freshMember()->getTitle());
        }

        public function testSignedRequestDoesNotReadAnAccountPage()
        {
            $key = $this->freshMember()->getAPIkey();

            $result = $this->request('GET', '/account/settings/tools/', null, array_merge($this->signature('/account/settings/tools/'), ['X-Requested-With: XMLHttpRequest']));

            $this->assertEquals(403, $result['status']);
            $this->assertStringNotContainsString($key, $result['body'], 'A signed request must not be given the API key.');
        }

        public function testSignedRequestDoesNotRevokeTheApiKey()
        {
            $key = $this->freshMember()->getAPIkey();

            $result = $this->request('POST', '/account/settings/tools/', ['_method' => 'revoke'], $this->signature('/account/settings/tools/'));

            $this->assertEquals(403, $result['status']);
            $this->assertEquals($key, $this->freshMember()->apikey, 'A signed request must not replace the API key.');
        }

        public function testSignedRequestDoesNotReachAnAccountPageOfAPlugin()
        {
            if (!Idno::site()->plugins()->get('IndiePub')) {
                $this->markTestSkipped('The IndiePub plugin is not enabled.');
            }
            $fields = ['client_id' => 'http://client.example.com/', 'redirect_uri' => 'http://client.example.com/callback'];

            $result = $this->request('POST', '/account/indiepub/add', $fields, $this->signature('/account/indiepub/add'));

            $this->assertEquals(403, $result['status'], 'A page that a plugin registers under /account/ is an account page too.');
            $this->assertEmpty($this->freshMember()->indieauth_tokens, 'A signed request must not issue an access token.');
        }

        public function testSignedRequestIsToldWhy()
        {
            $result = $this->request('GET', '/account/settings/', null, array_merge($this->signature('/account/settings/'), ['Accept: application/json']));

            $this->assertEquals(403, $result['status']);
            $this->assertStringContainsString('API', $result['body']);
        }

        public function testQueryStringDoesNotOpenAnAccountPage()
        {
            $marker = $this->marker();
            $uri = '/account/settings/?x=/status/edit';

            $result = $this->request('POST', $uri, ['name' => $marker], $this->signature($uri));

            $this->assertEquals(403, $result['status']);
            $this->assertEquals('API Account Test User', $this->freshMember()->getTitle());
        }

        // -----------------------------------------------------------------
        // What still works
        // -----------------------------------------------------------------

        public function testSignedRequestStillPublishes()
        {
            if (!Idno::site()->plugins()->get('Status')) {
                $this->markTestSkipped('The Status plugin is not enabled.');
            }
            $marker = $this->marker();

            $result = $this->request('POST', '/status/edit', ['body' => $marker], array_merge($this->signature('/status/edit'), ['Accept: application/json']));

            $this->assertEquals(200, $result['status'], 'The API should still publish. If this is failing, you may need to set IDNO_DOMAIN.');
            $this->assertNotEmpty(json_decode($result['body'])->location ?? null);
        }

        public function testSignedRequestStillIdentifiesItsUser()
        {
            $result = $this->request('GET', '/currentUser', null, $this->signature('/currentUser'));

            $this->assertEquals(200, $result['status']);
            $this->assertStringContainsString(static::$member->getHandle(), $result['body']);
        }

        public function testBrowserSessionStillUsesAccountPages()
        {
            $this->logIn();
            $marker = $this->marker();

            $form = $this->request('GET', '/account/settings', null, [], true);
            $this->assertEquals(200, $form['status'], 'A logged-in browser should see its account settings.');
            $this->assertMatchesRegularExpression('#<form[^>]+account/settings[^>]*>(.*?)</form>#s', $form['body']);
            preg_match('#<form[^>]+account/settings[^>]*>(.*?)</form>#s', $form['body'], $match);

            $this->request('POST', '/account/settings', $this->formFields($match[1]) + ['name' => $marker, 'email' => static::$member->email], [], true);

            try {
                $this->assertEquals($marker, $this->freshMember()->getTitle(), 'The settings form of a logged-in browser must keep working.');
            } finally {
                $member = $this->freshMember();
                $member->setTitle('API Account Test User');
                $member->save(true);
            }
        }

        // -----------------------------------------------------------------
        // A session alone does not change the password or the email address
        // -----------------------------------------------------------------

        public function testNewPasswordWithTheCurrentPasswordIsChanged()
        {
            $new = 'A new passphrase 7 ' . $this->marker();

            $this->submitSettings(['name' => 'API Account Test User', 'password' => $new, 'current_password' => static::$password]);

            try {
                $this->assertTrue($this->freshMember()->checkPassword($new), 'The right current password should allow the change.');
            } finally {
                $this->restoreMember();
            }
        }

        public function testNewPasswordWithAWrongCurrentPasswordChangesNothing()
        {
            $marker = $this->marker();

            $this->submitSettings(['name' => $marker, 'email' => $marker . '@example.com', 'password' => 'A new passphrase 8 ' . $marker, 'current_password' => 'not the password']);

            $this->assertNothingChanged();
        }

        public function testNewPasswordWithoutTheCurrentPasswordChangesNothing()
        {
            $marker = $this->marker();

            $this->submitSettings(['name' => $marker, 'password' => 'A new passphrase 9 ' . $marker]);

            $this->assertNothingChanged();
        }

        public function testEmailChangeNeedsTheCurrentPassword()
        {
            $marker = $this->marker();

            $this->submitSettings(['name' => $marker, 'email' => $marker . '@example.com']);
            $this->assertNothingChanged();

            $this->submitSettings(['name' => 'API Account Test User', 'email' => $marker . '@example.com', 'current_password' => static::$password]);
            try {
                $this->assertEquals($marker . '@example.com', $this->freshMember()->email, 'The right current password should allow the change.');
            } finally {
                $this->restoreMember();
            }
        }

        public function testNameOnlyChangeNeedsNoPassword()
        {
            $marker = $this->marker();

            $this->submitSettings(['name' => $marker, 'email' => static::$member->email]);

            try {
                $this->assertEquals($marker, $this->freshMember()->getTitle());
            } finally {
                $this->restoreMember();
            }
        }

        public function testAnonymousVisitorStillSeesPublicAccountPages()
        {
            $result = $this->request('GET', '/account/password', null, []);

            $this->assertEquals(200, $result['status'], 'The password recovery page is for visitors who are not logged in.');
        }

        // -----------------------------------------------------------------
        // Helpers
        // -----------------------------------------------------------------

        /**
         * Post the settings form as the logged-in browser, with a valid form token
         */
        private function submitSettings(array $fields)
        {
            $this->logIn();
            $form = $this->request('GET', '/account/settings', null, [], true);
            $this->assertEquals(200, $form['status']);
            preg_match('#<form[^>]+account/settings[^>]*>(.*?)</form>#s', $form['body'], $match);
            $this->assertNotEmpty($match, 'The settings form should be on the page.');

            return $this->request('POST', '/account/settings', $this->formFields($match[1]) + $fields, [], true);
        }

        private function assertNothingChanged()
        {
            $member = $this->freshMember();
            $this->assertEquals('API Account Test User', $member->getTitle(), "The user's name must not change.");
            $this->assertEquals(static::$member->email, $member->email, "The user's email address must not change.");
            $this->assertTrue($member->checkPassword(static::$password), "The user's password must not change.");
        }

        private function restoreMember()
        {
            $member = $this->freshMember();
            $member->setTitle('API Account Test User');
            $member->email = static::$member->email;
            $member->setPassword(static::$password);
            $member->save(true);
        }

        private function marker()
        {
            return 'apiaccountmarker' . md5(rand() . microtime(true));
        }

        private function freshMember()
        {
            return User::getByUUID(static::$member->getUUID(), false);
        }

        /**
         * The headers that sign a request for a path (with any query string) as the test user
         */
        private function signature($uri)
        {
            return [
                'X-IDNO-USERNAME: ' . static::$member->getHandle(),
                'X-IDNO-SIGNATURE: ' . base64_encode(hash_hmac('sha256', $uri, $this->freshMember()->getAPIkey(), true)),
            ];
        }

        /**
         * @param  string     $method
         * @param  string     $uri     Path from the root of the site, with any query string
         * @param  array|null $body    Form fields
         * @param  string[]   $headers
         * @param  bool       $cookies Whether to send (and keep) the browser's cookies
         * @return array 'status', 'headers' and 'body'
         */
        private function request($method, $uri, $body = null, array $headers = [], $cookies = false)
        {
            $curl = curl_init(rtrim(Idno::site()->config()->getDisplayURL(), '/') . $uri);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            if ($cookies) {
                curl_setopt($curl, CURLOPT_COOKIEFILE, static::$cookies);
                curl_setopt($curl, CURLOPT_COOKIEJAR, static::$cookies);
            }
            if ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body));
            }
            if ($headers) {
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            }
            $response = (string) curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            unset($curl); // Writes the cookie jar

            return ['status' => $status, 'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize)];
        }

        private function formFields($page)
        {
            $fields = [];
            foreach (['__bTs', '__bTk', '__bTa'] as $name) {
                if (preg_match('/name="' . $name . '" value="([^"]*)"/', $page, $match)) {
                    $fields[$name] = html_entity_decode($match[1]);
                }
            }

            return $fields;
        }

        private function logIn()
        {
            $form = $this->request('GET', '/session/login', null, [], true);
            $fields = $this->formFields($form['body']);
            $this->assertCount(3, $fields, 'The login page should have a form token. If this is failing, you may need to set IDNO_DOMAIN.');

            $this->request('POST', '/session/login', $fields + ['email' => static::$member->getHandle(), 'password' => static::$password], [], true);
        }
    }

}
