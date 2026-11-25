<?php

namespace IdnoPlugins\Status\Tests {

    use Idno\Core\Idno;
    use Idno\Entities\User;
    use IdnoPlugins\Status\Reply;
    use IdnoPlugins\Status\Status;

    /**
     * The form for a new status update takes its text from the "body" parameter (the
     * bookmarklet and share links fill it in). With an empty body, or none, it is simply
     * an empty form.
     */
    class EditPageTest extends \Tests\IdnoTestCase
    {

        /// A member with a known password, and that password
        private static $member;
        private static $password;

        /// File holding the cookies of the logged-in browser
        private static $cookies;

        private $previousPage;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            static::$password = bin2hex(random_bytes(12));

            $user = new User();
            $user->handle = 'statusedittest' . substr(md5(rand() . microtime(true)), 0, 8);
            $user->email = $user->handle . '@example.com';
            $user->setPassword(static::$password);
            $user->setTitle('Status Edit Test User');
            $user->save(true);
            static::$member = $user;

            static::$cookies = tempnam(sys_get_temp_dir(), 'idno-status-test-');
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
            $this->previousPage = Idno::site()->currentPage();
        }

        protected function tearDown(): void
        {
            Idno::site()->setCurrentPage($this->previousPage ?: new \Idno\Legacy\BarePage());
        }

        /**
         * Query strings for the form, and the text the form should hold.
         */
        public static function queries(): array
        {
            return [
                'no body'            => ['', ''],
                'empty body'         => ['?body=', ''],
                'empty body and url' => ['?body=&url=', ''],
                'body'               => ['?body=Hello+there', 'Hello there'],
                'mention'            => ['?body=%40someone+hello', '@someone hello'],
            ];
        }

        /**
         * @dataProvider queries
         */
        public function testFormLoads($query, $text)
        {
            $this->logIn();

            $result = $this->request('GET', 'status/edit' . $query);

            $this->assertEquals(200, $result['status'], "The form at /status/edit$query should load.");
            $this->assertSame(1, preg_match('~<textarea[^>]*name="body"[^>]*>(.*?)</textarea>~s', $result['body'], $match), 'The form should have a field for the text.');
            $this->assertSame($text, html_entity_decode(trim($match[1])), 'The field should hold the text that was given.');
        }

        /**
         * Inputs, and the class of the new post they should give.
         */
        public static function inputs(): array
        {
            return [
                'nothing'         => [[], Status::class],
                'empty body'      => [['body' => ''], Status::class],
                'body'            => [['body' => 'Hello there'], Status::class],
                'mention'         => [['body' => '@someone hello'], Reply::class],
                'in reply to'     => [['body' => '', 'inreplyto' => 'https://example.com/post'], Reply::class],
            ];
        }

        /**
         * @dataProvider inputs
         */
        public function testFactory($input, $class)
        {
            $page = new \Idno\Legacy\BarePage();
            foreach ($input as $name => $value) {
                $page->setInput($name, $value);
            }
            Idno::site()->setCurrentPage($page);

            $this->assertSame($class, get_class(Status::factory()));
        }

        /**
         * Make a request as a browser would, keeping cookies between requests.
         *
         * @param  string $method
         * @param  string $path   Path below the site's address, with any query string
         * @param  array  $body   Form fields
         * @return array 'status' and 'body'
         */
        private function request($method, $path, ?array $body = null)
        {
            $curl = curl_init(Idno::site()->config()->getURL() . $path);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            curl_setopt($curl, CURLOPT_COOKIEFILE, static::$cookies);
            curl_setopt($curl, CURLOPT_COOKIEJAR, static::$cookies);
            if ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body));
            }
            $response = (string) curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            unset($curl); // Writes the cookie jar

            return ['status' => $status, 'body' => $response];
        }

        /**
         * Log the test's browser in through the login form, unless it already is
         */
        private function logIn()
        {
            if ($this->request('GET', 'account/settings/')['status'] == 200) {
                return;
            }

            $form = $this->request('GET', 'session/login');
            $fields = [];
            foreach (['__bTs', '__bTk', '__bTa'] as $name) {
                if (preg_match('/name="' . $name . '" value="([^"]*)"/', $form['body'], $match)) {
                    $fields[$name] = html_entity_decode($match[1]);
                }
            }
            $this->assertCount(3, $fields, 'The login page should have a form token. If this is failing, you may need to set IDNO_DOMAIN.');

            $this->request('POST', 'session/login', $fields + ['email' => static::$member->getHandle(), 'password' => static::$password]);

            $this->assertEquals(200, $this->request('GET', 'account/settings/')['status'], 'The test user should be able to log in.');
        }

    }

}
