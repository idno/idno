<?php

namespace Tests\Pages\Session {

    /**
     * The login page gives a signed-in API client its API key (#2240). The key must only
     * be in the JSON response, which another site cannot read: never in JSONP, which any
     * site can load as a script.
     */
    class LoginApiKeyTest extends \Tests\IdnoTestCase
    {

        private static $loginUser = false;

        private static $password = '';

        /**
         * A curl handle with its own cookie store, signed in as the test user.
         */
        private static $browser = null;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            self::$password = bin2hex(random_bytes(16));

            $user         = new \Idno\Entities\User();
            $user->handle = 'loginapikey' . bin2hex(random_bytes(4));
            $user->email  = $user->handle . '@example.test';
            $user->setPassword(self::$password);
            $user->setTitle('Login API key test user');
            $user->save(true);

            // Give the user a key now: the site would make one for it on its first request,
            // and this copy of the user would not know it
            $user->getAPIkey();
            $user->save(true);

            self::$loginUser = $user;
        }

        public static function tearDownAfterClass(): void
        {
            self::$browser = null;
            if (self::$loginUser) {
                self::$loginUser->delete();
                self::$loginUser = false;
            }
            parent::tearDownAfterClass();
        }

        private static function loginUrl(string $query = ''): string
        {
            return \Idno\Core\Idno::site()->config()->getDisplayURL() . 'session/login' . ($query === '' ? '' : '?' . $query);
        }

        /**
         * Make a request with the signed-in browser and return the response body.
         */
        private static function request(string $url, ?array $post = null, array $headers = []): string
        {
            curl_setopt_array(
                self::$browser, [
                    CURLOPT_URL            => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_HTTPGET        => true,
                ]
            );
            if ($post !== null) {
                curl_setopt(self::$browser, CURLOPT_POST, true);
                curl_setopt(self::$browser, CURLOPT_POSTFIELDS, http_build_query($post));
            }

            return (string) curl_exec(self::$browser);
        }

        /**
         * Sign in through the login form, as a browser does, and keep the session cookie.
         */
        private function signIn(): void
        {
            if (self::$browser) {
                return;
            }

            self::$browser = curl_init();
            curl_setopt(self::$browser, CURLOPT_COOKIEFILE, ''); // Keep cookies in memory

            // The token is in the HTML form of the session (the JSON of the page never carries it).
            $html = self::request(self::loginUrl());
            $this->assertSame(1, preg_match('/name="__bTs" value="([^"]*)"/', $html, $time), 'The login form should come with a time.');
            $this->assertSame(1, preg_match('/name="__bTk" value="([0-9a-f]{64})"/', $html, $token), 'The login form should come with a token.');
            $this->assertSame(1, preg_match('/name="__bTa" value="([^"]*)"/', $html, $action), 'The login form should come with an action.');

            self::request(
                self::loginUrl(), [
                    'email'    => self::$loginUser->handle,
                    'password' => self::$password,
                    '__bTs'    => $time[1],
                    '__bTk'    => $token[1],
                    '__bTa'    => html_entity_decode($action[1]),
                ]
            );
        }

        /**
         * Fetch the login page as the signed-in user, over what the site sees as HTTPS
         * (the key is only ever given out over HTTPS).
         */
        private function signedInLoginPage(string $query): string
        {
            $this->signIn();

            return self::request(self::loginUrl($query), null, ['X-Forwarded-Proto: https']);
        }

        public function testJsonResponseStillHasTheApiKey()
        {
            $response = json_decode($this->signedInLoginPage('_t=json'), true);

            $this->assertIsArray($response);
            $this->assertSame(
                self::$loginUser->getAPIkey(),
                $response['api-token'] ?? null,
                'A signed-in API client should get its API key from the JSON login page.'
            );
        }

        public static function scriptResponseProvider(): array
        {
            return [
                'jsonp with a callback'         => ['_t=jsonp&callback=x'],
                'jsonp with the jsonp argument' => ['_t=jsonp&jsonp=x'],
                'jsonp with no callback'        => ['_t=jsonp'],
            ];
        }

        /**
         * @dataProvider scriptResponseProvider
         */
        public function testJsonpResponseHasNoApiKey($query)
        {
            $response = $this->signedInLoginPage($query);

            $this->assertStringContainsString('"title"', $response, 'The login page should have answered in JSONP.');
            $this->assertStringNotContainsString('api-token', $response);
            $this->assertStringNotContainsString(self::$loginUser->getAPIkey(), $response, 'The API key must not be in a response another site can load as a script.');
        }

        public function testSignedOutResponseHasNoApiKey()
        {
            $anonymous = curl_init(self::loginUrl('_t=json'));
            curl_setopt($anonymous, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($anonymous, CURLOPT_HTTPHEADER, ['X-Forwarded-Proto: https']);
            $response = json_decode((string) curl_exec($anonymous), true);

            $this->assertIsArray($response);
            $this->assertArrayNotHasKey('api-token', $response);
        }
    }

}
