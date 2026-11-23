<?php

namespace Tests\Pages\Search {

    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * The user search (/search/users/) behind the administrator's Users page draws, for each
     * match, the member's email address and the controls that make an administrator, block
     * or delete them. Only an administrator may use it: a member must not be able to list
     * the other members' email addresses.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class UserSearchAccessTest extends \Tests\IdnoTestCase
    {

        /// @var User A site administrator
        private static $admin;

        /// @var User An ordinary member, who makes the requests
        private static $member;

        /// @var User Another member, whose details are searched for
        private static $other;

        private static $password;

        /// Files holding the cookies of each user's browser, by handle
        private static $cookies = [];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            static::$password = bin2hex(random_bytes(12));
            static::$admin = static::createUser('searchadmin', true);
            static::$member = static::createUser('searchmember', false);
            static::$other = static::createUser('searchother', false);
        }

        public static function tearDownAfterClass(): void
        {
            foreach ([static::$admin, static::$member, static::$other] as $user) {
                if ($user) {
                    $user->delete();
                }
            }
            static::$admin = static::$member = static::$other = null;
            foreach (static::$cookies as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            static::$cookies = [];

            parent::tearDownAfterClass();
        }

        public function testVisitorIsRefused()
        {
            $result = $this->search(null, static::$other->getHandle());

            $this->assertNotEquals(200, $result['status'], 'A visitor who is not logged in must not be able to search the users.');
            $this->assertStringNotContainsString(static::$other->email, $result['body']);
        }

        public function testMemberCannotReadOtherMembersEmailAddresses()
        {
            $result = $this->search(static::$member, static::$other->getHandle());

            $this->assertStringNotContainsString(static::$other->email, $result['body'], 'A member must not be shown another member\'s email address.');
            $this->assertStringNotContainsString('admin\/users', $result['body'], 'A member must not be shown the administrator\'s controls.');
            $this->assertStringNotContainsString('Make admin', $result['body']);
            $this->assertNotEquals(200, $result['status'], 'The user search is for administrators only.');
        }

        public function testMemberCannotListEveryMember()
        {
            $result = $this->search(static::$member, '');

            $this->assertStringNotContainsString(static::$other->email, $result['body']);
            $this->assertStringNotContainsString(static::$admin->email, $result['body']);
            $this->assertNotEquals(200, $result['status']);
        }

        public function testMemberCannotLookUpAnEmailAddress()
        {
            // A search for an exact email address is answered with that member's row
            $result = $this->search(static::$member, static::$other->email);

            $this->assertStringNotContainsString(static::$other->getHandle(), $result['body'], 'A member must not be able to find who an email address belongs to.');
            $this->assertNotEquals(200, $result['status']);
        }

        public function testMemberCannotChooseAnotherTemplate()
        {
            $result = $this->search(static::$member, static::$other->getHandle(), 'template=' . rawurlencode('forms/components/usersearch/user'));

            $this->assertStringNotContainsString(static::$other->email, $result['body']);
            $this->assertNotEquals(200, $result['status']);
        }

        public function testMemberIsRefusedInEveryTemplateType()
        {
            foreach (['json', 'jsonp', 'rss', 'default'] as $type) {
                $result = $this->search(static::$member, static::$other->getHandle(), '_t=' . $type . '&callback=cb');

                $this->assertStringNotContainsString(static::$other->email, $result['body'], "Template type $type must not show the email address.");
                $this->assertNotEquals(200, $result['status'], "Template type $type must refuse a member.");
            }
        }

        public function testAdministratorStillGetsTheFullRows()
        {
            $result = $this->search(static::$admin, "");

            $this->assertEquals(200, $result['status']);
            $json = json_decode($result['body'], true);
            $this->assertIsArray($json, 'The search should answer with JSON.');
            $this->assertGreaterThanOrEqual(1, $json['count']);
            $this->assertStringContainsString(static::$other->email, $json['rendered'], 'An administrator should see the email address.');
            $this->assertStringContainsString('Make admin', $json['rendered'], 'An administrator should see the controls.');
        }

        public function testAdministratorCanSearchByEmailAddress()
        {
            $result = $this->search(static::$admin, static::$other->email);

            $this->assertEquals(200, $result['status']);
            $json = json_decode($result['body'], true);
            $this->assertStringContainsString(static::$other->getHandle(), $json['rendered']);
        }

        // -----------------------------------------------------------------

        private static function createUser($prefix, $admin)
        {
            $user = new User();
            $user->handle = $prefix . substr(md5(rand() . microtime(true)), 0, 8);
            $user->email = $user->handle . '@example.com';
            $user->setPassword(static::$password);
            $user->setTitle('User Search Test User');
            if ($admin) {
                $user->setAdmin(true);
            }
            $user->save(true);

            return $user;
        }

        /**
         * Search the users as a visitor or as the browser of a logged-in user
         *
         * @param  User|null $user  The user whose browser makes the request, if any
         * @param  string    $query The search text
         * @param  string    $extra More query string
         * @return array 'status', 'headers' and 'body'
         */
        private function search($user, $query, $extra = '')
        {
            $cookies = $user ? $this->logIn($user) : null;
            $path = 'search/users/?query=' . rawurlencode($query) . ($extra ? '&' . $extra : '');

            return $this->send('GET', $path, null, [], $cookies);
        }

        private function send($method, $path, $body, array $headers, $cookies)
        {
            $curl = curl_init(Idno::site()->config()->getDisplayURL() . $path);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            if ($cookies) {
                curl_setopt($curl, CURLOPT_COOKIEFILE, $cookies);
                curl_setopt($curl, CURLOPT_COOKIEJAR, $cookies);
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

        /**
         * Log a user's browser in through the login form, unless it already is.
         *
         * @return string The file that holds the browser's cookies
         */
        private function logIn(User $user)
        {
            $handle = $user->getHandle();
            if (empty(static::$cookies[$handle])) {
                static::$cookies[$handle] = tempnam(sys_get_temp_dir(), 'idno-usersearch-test-cookies-');
            }
            $cookies = static::$cookies[$handle];

            if ($this->send('GET', 'account/settings/', null, [], $cookies)['status'] == 200) {
                return $cookies;
            }

            $form = $this->send('GET', 'session/login', null, [], $cookies);
            $fields = [];
            foreach (['__bTs', '__bTk', '__bTa'] as $name) {
                if (preg_match('/name="' . $name . '" value="([^"]*)"/', $form['body'], $match)) {
                    $fields[$name] = html_entity_decode($match[1]);
                }
            }
            $this->assertCount(3, $fields, 'The login page should have a form token. If this is failing, you may need to set IDNO_DOMAIN.');

            $this->send('POST', 'session/login', $fields + ['email' => $handle, 'password' => static::$password], [], $cookies);
            $this->assertEquals(200, $this->send('GET', 'account/settings/', null, [], $cookies)['status'], 'The test user should be able to log in.');

            return $cookies;
        }
    }

}
