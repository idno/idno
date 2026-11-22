<?php

namespace IdnoPlugins\IndiePub\Tests {

    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * An IndieAuth access token is given to a Micropub client. It authenticates requests to
     * the Micropub endpoint and nothing else: on any other page a request that carries one
     * is treated as if it carried none.
     *
     * These tests get a token through the IndieAuth flow and send requests over HTTP, so
     * they need the site to be running (see IDNO_DOMAIN / IDNO_PORT).
     */
    class AccessTokenReachTest extends \Tests\IdnoTestCase
    {

        /// @var User A site administrator
        private static $admin;

        private static $password;

        /// An access token of the administrator, issued through the IndieAuth flow
        private static $token;

        /// File holding the cookies of the logged-in browser
        private static $cookies;

        private static $siteTitle;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            static::$password = bin2hex(random_bytes(12));

            $user = new User();
            $user->handle = 'tokenreach' . substr(md5(rand() . microtime(true)), 0, 8);
            $user->email = $user->handle . '@example.com';
            $user->setPassword(static::$password);
            $user->setTitle('Token Reach Test Admin');
            $user->setAdmin(true);
            $user->save(true);
            static::$admin = $user;

            static::$cookies = tempnam(sys_get_temp_dir(), 'idno-token-reach-test-');
            static::$siteTitle = Idno::site()->config()->title;
            static::$token = null;
        }

        public static function tearDownAfterClass(): void
        {
            if (static::$admin) {
                static::$admin->delete();
                static::$admin = null;
            }
            if (static::$cookies && file_exists(static::$cookies)) {
                unlink(static::$cookies);
            }
            parent::tearDownAfterClass();
        }

        protected function setUp(): void
        {
            if (!Idno::site()->plugins()->get('IndiePub') || !Idno::site()->plugins()->get('Status')) {
                $this->markTestSkipped('The IndiePub and Status plugins are not both enabled.');
            }
            if (static::$token === null) {
                static::$token = $this->obtainToken();
                $this->logOut();
            }
        }

        protected function tearDown(): void
        {
            $old = $this->swapUser(static::$admin);
            foreach ($this->statuses() as $entity) {
                $entity->delete();
            }
            $this->swapUser($old);

            // Put back anything a test managed to change
            $admin = $this->freshAdmin();
            if ($admin && $admin->getTitle() != 'Token Reach Test Admin') {
                $admin->setTitle('Token Reach Test Admin');
                $admin->save(true);
            }
        }

        // -----------------------------------------------------------------
        // The token at the Micropub endpoint
        // -----------------------------------------------------------------

        public function testTokenFromTheIndieAuthFlowPublishesThroughMicropub()
        {
            $marker = $this->marker();

            $result = $this->request('POST', 'micropub/endpoint', ['h' => 'entry', 'content' => $marker], $this->bearer(), false);

            $this->assertEquals(201, $result['status'], 'If this is failing, you may need to set IDNO_DOMAIN.');
            $this->assertTrue($this->statusExists($marker));
        }

        public function testTokenReadsTheMicropubConfiguration()
        {
            $result = $this->request('GET', 'micropub/endpoint?q=config', null, $this->bearer(), false);

            $this->assertEquals(200, $result['status']);
            $this->assertArrayHasKey('media-endpoint', (array) json_decode($result['body'], true));

            $anonymous = $this->request('GET', 'micropub/endpoint?q=config', null, [], false);
            $this->assertEquals(403, $anonymous['status'], 'Without a token the endpoint answers nothing.');
        }

        public function testTokenIsVerifiedAtTheTokenEndpoint()
        {
            $result = $this->request('GET', 'indieauth/token', null, $this->bearer(), false);

            $this->assertEquals(200, $result['status']);
            parse_str($result['body'], $data);
            $this->assertEquals('create', $data['scope'] ?? null);
        }

        // -----------------------------------------------------------------
        // The token anywhere else
        // -----------------------------------------------------------------

        public function testTokenDoesNotChangeAccountSettings()
        {
            $marker = $this->marker();
            $fields = ['name' => $marker, 'email' => $marker . '@example.com'];

            $result = $this->request('POST', 'account/settings', $fields, $this->bearer(), false);
            $anonymous = $this->request('POST', 'account/settings', $fields, [], false);

            $admin = $this->freshAdmin();
            $this->assertEquals('Token Reach Test Admin', $admin->getTitle(), 'An access token must not change the name of its user.');
            $this->assertEquals(static::$admin->email, $admin->email, 'An access token must not change the email address of its user.');
            $this->assertSameAnswer($anonymous, $result);
        }

        public function testTokenInTheAccessTokenFieldDoesNotChangeAccountSettings()
        {
            $marker = $this->marker();

            $result = $this->request('POST', 'account/settings', ['name' => $marker, 'access_token' => static::$token], [], false);
            $anonymous = $this->request('POST', 'account/settings', ['name' => $marker], [], false);

            $this->assertEquals('Token Reach Test Admin', $this->freshAdmin()->getTitle());
            $this->assertSameAnswer($anonymous, $result);

            $this->request('POST', 'account/settings?access_token=' . static::$token, ['name' => $marker], [], false);

            $this->assertEquals('Token Reach Test Admin', $this->freshAdmin()->getTitle(), 'Nor may a token in the query string.');
        }

        public function testTokenDoesNotPublishThroughAContentPage()
        {
            $marker = $this->marker();

            $result = $this->request('POST', 'status/edit', ['body' => $marker, 'access' => 'PUBLIC'], $this->bearer(), false);
            $anonymous = $this->request('POST', 'status/edit', ['body' => $marker, 'access' => 'PUBLIC'], [], false);

            $this->assertFalse($this->statusExists($marker), 'An access token must publish only through the Micropub endpoint.');
            $this->assertSameAnswer($anonymous, $result);
        }

        public function testTokenDoesNotChangeSiteSettings()
        {
            $marker = $this->marker();
            $fields = ['title' => $marker, 'url' => Idno::site()->config()->getURL(), 'items_per_page' => 10];

            try {
                $result = $this->request('POST', 'admin/', $fields, $this->bearer(), false);
                $anonymous = $this->request('POST', 'admin/', $fields, [], false);

                $page = $this->request('GET', 'session/login', null, [], false);
                $this->assertStringNotContainsString($marker, $page['body'], 'An access token must not change the title of the site.');
                $this->assertSameAnswer($anonymous, $result);
            } finally {
                if (strpos($this->request('GET', 'session/login', null, [], false)['body'], $marker) !== false) {
                    Idno::site()->config()->title = static::$siteTitle;
                    Idno::site()->config()->save();
                }
            }
        }

        public function testTokenDoesNotAddAUser()
        {
            $marker = $this->marker();
            $fields = ['action' => 'add_user', 'handle' => $marker, 'name' => $marker, 'email' => $marker . '@example.com', 'password1' => 'A long passphrase 1 ' . $marker, 'password2' => 'A long passphrase 1 ' . $marker];

            $result = $this->request('POST', 'admin/users', $fields, $this->bearer(), false);
            $anonymous = $this->request('POST', 'admin/users', $fields, [], false);

            $created = User::getByHandle($marker);
            if ($created) {
                $created->delete();
            }
            $this->assertEmpty($created, 'An access token must not add a user.');
            $this->assertSameAnswer($anonymous, $result);
        }

        public function testTokenDoesNotReadAnAccountPage()
        {
            // Not static::$admin: asking for the key saves the user, and that copy has no tokens
            $key = $this->freshAdmin()->getAPIkey();

            // The page gives the key to a script of its own, which sends this header
            $xhr = ['X-Requested-With: XMLHttpRequest'];
            $result = $this->request('GET', 'account/settings/tools', null, array_merge($this->bearer(), $xhr), false);
            $anonymous = $this->request('GET', 'account/settings/tools', null, $xhr, false);

            $this->assertStringNotContainsString($key, $result['body'], "An access token must not show its user's API key.");
            $this->assertSameAnswer($anonymous, $result);
        }

        public function testTokenDoesNotIdentifyTheUserOnAPublicPage()
        {
            $result = $this->request('GET', 'currentUser?_t=json', null, $this->bearer(), false);

            $this->assertStringNotContainsString(static::$admin->getHandle(), $result['body']);
        }

        public function testAdministratorsApiKeyAsATokenDoesNotChangeAccountSettings()
        {
            // The plugin also takes the API key of the site's first administrator as a token
            $first = User::getOne(['admin' => true]);
            $title = $first->getTitle();
            $marker = $this->marker();

            try {
                $result = $this->request('POST', 'account/settings', ['name' => $marker], ['Authorization: Bearer ' . $first->getAPIkey()], false);
                $anonymous = $this->request('POST', 'account/settings', ['name' => $marker], [], false);

                $this->assertEquals($title, User::getByUUID($first->getUUID(), false)->getTitle());
                $this->assertSameAnswer($anonymous, $result);
            } finally {
                $first = User::getByUUID($first->getUUID(), false);
                if ($first->getTitle() != $title) {
                    $first->setTitle($title);
                    $first->save(true);
                }
            }
        }

        // -----------------------------------------------------------------
        // A logged-in browser
        // -----------------------------------------------------------------

        public function testBrowserSessionStillNeedsAFormTokenWhenAnAccessTokenIsSent()
        {
            $this->logIn();
            $marker = $this->marker();

            $this->request('POST', 'account/settings', ['name' => $marker], $this->bearer());

            $this->assertEquals('Token Reach Test Admin', $this->freshAdmin()->getTitle(), 'An access token must not stand in for the form token.');
            $this->assertTrue($this->isLoggedIn(), 'An access token sent to an ordinary page must not end the session.');
        }

        public function testBrowserSessionWithAFormTokenChangesAccountSettings()
        {
            $this->logIn();
            $marker = $this->marker();

            $form = $this->request('GET', 'account/settings');
            $this->assertMatchesRegularExpression('#<form[^>]+account/settings[^>]*>(.*?)</form>#s', $form['body']);
            preg_match('#<form[^>]+account/settings[^>]*>(.*?)</form>#s', $form['body'], $match);
            $fields = $this->formFields($match[1]);
            $this->assertCount(3, $fields, 'The settings page should have a form token.');

            $this->request('POST', 'account/settings', $fields + ['name' => $marker, 'email' => static::$admin->email]);

            $this->assertEquals($marker, $this->freshAdmin()->getTitle(), 'The settings form of a logged-in browser must keep working.');
            $this->assertTrue($this->isLoggedIn());
        }

        public function testBrowserSessionWithoutAFormTokenChangesNothing()
        {
            $this->logIn();
            $marker = $this->marker();

            $this->request('POST', 'account/settings', ['name' => $marker]);

            $this->assertEquals('Token Reach Test Admin', $this->freshAdmin()->getTitle());
        }

        // -----------------------------------------------------------------
        // Helpers
        // -----------------------------------------------------------------

        private function marker()
        {
            return 'tokenreachmarker' . md5(rand() . microtime(true));
        }

        private function bearer()
        {
            return ['Authorization: Bearer ' . static::$token];
        }

        private function freshAdmin()
        {
            return User::getByUUID(static::$admin->getUUID(), false);
        }

        /**
         * The token must have changed nothing about the answer: the request is answered as
         * the same request without a token is.
         */
        private function assertSameAnswer(array $anonymous, array $result)
        {
            $this->assertEquals($anonymous['status'], $result['status'], 'A request with an access token should get the status an anonymous request gets.');
            $this->assertEquals($this->location($anonymous), $this->location($result), 'A request with an access token should be sent where an anonymous request is sent.');
        }

        private function location(array $result)
        {
            return preg_match('#^Location: (.*)$#mi', $result['headers'], $match) ? trim($match[1]) : null;
        }

        private function statuses()
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $statuses = \IdnoPlugins\Status\Status::get(['owner' => static::$admin->getUUID()], [], 100);
            Idno::site()->db()->setIgnoreAccess(false);

            return $statuses ?: [];
        }

        private function statusExists($marker)
        {
            foreach ($this->statuses() as $status) {
                if (strpos((string) $status->body, $marker) !== false) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Get an access token as a Micropub client does: the user approves the client in
         * their browser, and the client exchanges the code it is given for a token.
         */
        private function obtainToken()
        {
            $this->logIn();

            // "me" must be on the site's host as the web server knows it, which is not always
            // the name the tests reach it by. Find it as a client does, from the Link header.
            $page = $this->request('GET', 'session/login');
            $this->assertMatchesRegularExpression('#^Link: <([^>]+)indieauth/auth>; rel="authorization_endpoint"#mi', $page['headers'], 'The site should advertise its authorization endpoint.');
            preg_match('#^Link: <([^>]+)indieauth/auth>; rel="authorization_endpoint"#mi', $page['headers'], $match);

            $fields = [
                'me' => $match[1],
                'client_id' => 'http://client.example.com/',
                'redirect_uri' => 'http://client.example.com/callback',
                'state' => $this->marker(),
                'scope' => 'create',
            ];

            $form = $this->request('GET', 'indieauth/auth?' . http_build_query($fields));
            $result = $this->request('POST', 'indieauth/approve', $fields + $this->formFields($form['body']));
            $this->assertMatchesRegularExpression('#^Location: http://client\.example\.com/callback\?code=([0-9a-f]+)&state=#mi', $result['headers'], 'The approval should hand the client a code.');
            preg_match('#^Location: http://client\.example\.com/callback\?code=([0-9a-f]+)&state=#mi', $result['headers'], $match);

            $result = $this->request('POST', 'indieauth/token', [
                'code' => $match[1],
                'me' => $fields['me'],
                'client_id' => $fields['client_id'],
                'redirect_uri' => $fields['redirect_uri'],
                'state' => $fields['state'],
            ], [], false);
            $answer = json_decode($result['body'], true);
            $this->assertNotEmpty($answer['access_token'] ?? null, 'The token endpoint should issue a token: ' . $result['body']);

            return $answer['access_token'];
        }

        /**
         * Make a request as a browser would, keeping cookies between requests.
         *
         * @param  string       $method
         * @param  string       $path    Path below the site's address, with any query string
         * @param  array|string $body    Form fields, or a raw body
         * @param  string[]     $headers
         * @param  bool         $cookies Whether to send (and keep) the browser's cookies
         * @return array 'status', 'headers' and 'body'
         */
        private function request($method, $path, $body = null, array $headers = [], $cookies = true)
        {
            $curl = curl_init(Idno::site()->config()->getURL() . $path);
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
                curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
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

        /**
         * The form token fields in a page, or in part of one
         */
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

        private function isLoggedIn()
        {
            $result = $this->request('GET', 'account/settings/');

            return $result['status'] == 200;
        }

        /**
         * Log the test's browser in through the login form, unless it already is
         */
        private function logIn()
        {
            if ($this->isLoggedIn()) {
                return;
            }

            $form = $this->request('GET', 'session/login');
            $fields = $this->formFields($form['body']);
            $this->assertCount(3, $fields, 'The login page should have a form token. If this is failing, you may need to set IDNO_DOMAIN.');

            $this->request('POST', 'session/login', $fields + ['email' => static::$admin->getHandle(), 'password' => static::$password]);

            $this->assertTrue($this->isLoggedIn(), 'The test user should be able to log in.');
        }

        /**
         * Forget the browser's cookies
         */
        private function logOut()
        {
            file_put_contents(static::$cookies, '');
        }
    }

}
