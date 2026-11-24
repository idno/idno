<?php

namespace IdnoPlugins\IndiePub\Tests {

    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * The Micropub endpoint takes POSTs from clients, which have an access token and no form
     * token. So a POST must be authenticated by the access token it carries: the session
     * cookie of a logged-in browser is not enough, because a browser sends it with requests
     * that other sites make.
     *
     * These tests log in over HTTP as a browser does, so they need the site to be running
     * (see IDNO_DOMAIN / IDNO_PORT).
     */
    class MicropubEndpointAuthTest extends \Tests\IdnoTestCase
    {

        /// @var User
        private static $member;

        private static $password;

        /// An IndieAuth access token of the test user
        private static $token;

        /// File holding the cookies of the logged-in browser
        private static $cookies;

        private $toDelete = [];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            static::$password = bin2hex(random_bytes(12));
            static::$token = bin2hex(random_bytes(16));

            $user = new User();
            $user->handle = 'micropubtest' . substr(md5(rand() . microtime(true)), 0, 8);
            $user->email = $user->handle . '@example.com';
            $user->setPassword(static::$password);
            $user->setTitle('Micropub Test User');
            $user->indieauth_tokens = [
                static::$token => [
                    'me' => 'http://example.com/',
                    'redirect_uri' => 'http://client.example.com/callback',
                    'scope' => 'create delete',
                    'client_id' => 'http://client.example.com/',
                    'issued_at' => time(),
                    'nonce' => 1234567,
                ],
            ];
            $user->save(true);
            static::$member = $user;

            static::$cookies = tempnam(sys_get_temp_dir(), 'idno-micropub-test-');
        }

        public static function tearDownAfterClass(): void
        {
            if (static::$member) {
                static::$member->delete();
                static::$member = null;
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
        }

        protected function tearDown(): void
        {
            $old = $this->swapUser(static::$member);
            foreach (array_merge($this->toDelete, $this->statuses()) as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
            $this->swapUser($old);
        }

        // -----------------------------------------------------------------
        // A logged-in browser, with no access token
        // -----------------------------------------------------------------

        public function testSessionCookieAloneDoesNotCreateAPost()
        {
            $this->logIn();
            $marker = $this->marker();

            $result = $this->request('POST', 'micropub/endpoint', ['h' => 'entry', 'content' => $marker]);

            $this->assertFalse($this->statusExists($marker), 'A POST with a session cookie and no access token must not publish anything.');
            $this->assertEquals(401, $result['status']);
            $this->assertEquals('unauthorized', json_decode($result['body'], true)['error'] ?? null);
            $this->assertTrue($this->isLoggedIn(), 'The request must not end the session.');
        }

        public function testSessionCookieAloneDoesNotCreateAPostFromJSON()
        {
            $this->logIn();
            $marker = $this->marker();
            $body = json_encode(['type' => ['h-entry'], 'properties' => ['content' => [$marker]]]);

            $result = $this->request('POST', 'micropub/endpoint', $body, ['Content-Type: application/json']);

            $this->assertFalse($this->statusExists($marker), 'A JSON POST with a session cookie and no access token must not publish anything.');
            $this->assertEquals(401, $result['status']);
        }

        public function testSessionCookieAloneDoesNotDeleteAPost()
        {
            $this->logIn();
            $status = $this->createStatus();

            $result = $this->request('POST', 'micropub/endpoint', ['action' => 'delete', 'url' => $status->getUUID()]);

            $this->assertEquals(401, $result['status']);
            $this->assertNotEmpty(\IdnoPlugins\Status\Status::getByID($status->getID()), 'A POST with a session cookie and no access token must not delete anything.');
        }

        public function testSessionCookieAloneDoesNotUploadAFile()
        {
            $this->logIn();
            $file = tempnam(sys_get_temp_dir(), 'idno-micropub-upload-');
            file_put_contents($file, 'not really a picture');

            try {
                $result = $this->request('POST', 'micropub/endpoint', ['file' => new \CURLFile($file, 'image/jpeg', 'picture.jpg')]);
            } finally {
                unlink($file);
            }

            $this->assertEquals(401, $result['status'], 'A file sent with a session cookie and no access token must not be stored.');
            $this->assertStringNotContainsString('/file/', $result['headers']);
        }

        public function testSessionCookieWithAnUnknownAccessTokenDoesNotCreateAPost()
        {
            $this->logIn();
            $marker = $this->marker();

            $result = $this->request('POST', 'micropub/endpoint', ['h' => 'entry', 'content' => $marker, 'access_token' => 'not-a-token']);

            $this->assertFalse($this->statusExists($marker), 'An access token that is not known must not let the session cookie publish.');
            $this->assertEquals(401, $result['status']);
        }

        public function testApprovingAnIndieAuthRequestNeedsAFormToken()
        {
            // The approval page is an ordinary form, so a POST that lacks its token must not
            // hand a client an authorization code
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

            $result = $this->request('POST', 'indieauth/approve', $fields);

            $this->assertStringNotContainsString('code=', $result['headers'], 'No authorization code may be issued.');
            $this->assertEmpty(User::getByUUID(static::$member->getUUID())->indieauth_codes, 'No authorization code may be stored.');

            // Control: with the token of the approval form, the code is issued
            $form = $this->request('GET', 'indieauth/auth?' . http_build_query($fields));
            $token = $this->formFields($form['body']);
            $this->assertCount(3, $token, 'The approval page should have a form token.');

            $result = $this->request('POST', 'indieauth/approve', $fields + $token);

            $this->assertMatchesRegularExpression('#^Location: http://client\.example\.com/callback\?code=[0-9a-f]+&state=#mi', $result['headers']);
        }

        public function testGetWithABrowserSessionAndNoTokenIs401()
        {
            $this->logIn();

            foreach (['config', 'syndicate-to', 'source'] as $query) {
                $result = $this->request('GET', 'micropub/endpoint?q=' . $query);

                $this->assertEquals(401, $result['status'], 'q=' . $query . ': a session cookie is not a credential for the endpoint.');
                $this->assertEquals('unauthorized', json_decode($result['body'], true)['error'] ?? null);
            }
            $this->assertTrue($this->isLoggedIn(), 'The request must not end the session.');
        }

        // -----------------------------------------------------------------
        // A Micropub client
        // -----------------------------------------------------------------

        public function testPostWithoutCredentialsIsRefused()
        {
            $marker = $this->marker();

            $result = $this->request('POST', 'micropub/endpoint', ['h' => 'entry', 'content' => $marker], [], false);

            $this->assertFalse($this->statusExists($marker));
            $this->assertEquals(401, $result['status']);
        }

        public function testBearerTokenCreatesAPost()
        {
            $marker = $this->marker();

            $result = $this->request('POST', 'micropub/endpoint', ['h' => 'entry', 'content' => $marker], ['Authorization: Bearer ' . static::$token], false);

            $this->assertEquals(201, $result['status'], 'A POST with an access token in the Authorization header should publish. If this is failing, you may need to set IDNO_DOMAIN.');
            $this->assertTrue($this->statusExists($marker));
        }

        public function testAccessTokenFieldCreatesAPost()
        {
            $marker = $this->marker();

            $result = $this->request('POST', 'micropub/endpoint', ['h' => 'entry', 'content' => $marker, 'access_token' => static::$token], [], false);

            $this->assertEquals(201, $result['status'], 'A POST with an access token in the access_token field should publish.');
            $this->assertTrue($this->statusExists($marker));
        }

        public function testBearerTokenCreatesAPostFromJSON()
        {
            $marker = $this->marker();
            $body = json_encode(['type' => ['h-entry'], 'properties' => ['content' => [$marker]]]);

            $result = $this->request('POST', 'micropub/endpoint', $body, ['Content-Type: application/json', 'Authorization: Bearer ' . static::$token], false);

            $this->assertEquals(201, $result['status']);
            $this->assertTrue($this->statusExists($marker));
        }

        public function testBearerTokenWorksAlongsideASessionCookie()
        {
            $this->logIn();
            $marker = $this->marker();

            $result = $this->request('POST', 'micropub/endpoint', ['h' => 'entry', 'content' => $marker], ['Authorization: Bearer ' . static::$token]);

            $this->assertEquals(201, $result['status']);
            $this->assertTrue($this->statusExists($marker));
        }

        // -----------------------------------------------------------------
        // Helpers
        // -----------------------------------------------------------------

        private function marker()
        {
            return 'micropubmarker' . md5(rand() . microtime(true));
        }

        private function statuses()
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $statuses = \IdnoPlugins\Status\Status::get(['owner' => static::$member->getUUID()], [], 100);
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

        private function createStatus()
        {
            $old = $this->swapUser(static::$member);
            $status = new \IdnoPlugins\Status\Status();
            $status->body = $this->marker();
            $status->setAccess('PUBLIC');
            $this->assertNotEmpty($status->save(), 'The status should have been saved.');
            $this->toDelete[] = $status;
            $this->swapUser($old);

            return $status;
        }

        /**
         * Make a request as a browser would, keeping cookies between requests.
         *
         * @param  string       $method
         * @param  string       $path    Path below the site's address, with any query string
         * @param  array|string $body    Form fields (sent as multipart if one is a file), or a raw body
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
                $multipart = is_array($body) && array_filter($body, 'is_object');
                curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($body) && !$multipart ? http_build_query($body) : $body);
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
         * The form token fields on a page
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

            $this->request('POST', 'session/login', $fields + ['email' => static::$member->getHandle(), 'password' => static::$password]);

            $this->assertTrue($this->isLoggedIn(), 'The test user should be able to log in.');
        }
    }

}
