<?php

namespace Tests\Pages\Account {

    use Idno\Core\Idno;
    use Idno\Entities\Invitation;
    use Idno\Entities\User;
    use Tests\SmtpSink;

    /**
     * The pages that find an account by what a visitor types (sign in, registration, the
     * password recovery request and the password reset) take a text in each field. A field
     * sent as an array (name[]=x, name[$gt]=x, an object in a JSON body) is read as an empty
     * field: no account is selected, created, mailed or changed by it, and the page gives its
     * ordinary answer, not a server error.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT). Mail goes to a local sink (Tests\SmtpSink).
     */
    class ArrayInputTest extends \Tests\BrowserTestCase
    {

        /// @var SmtpSink
        protected static $sink;

        /// @var User The member the requests try to select
        protected static $victim;

        /// The site's settings before the tests, to put back
        protected static $settings = [];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$victim = static::member('arrayvictim');

            static::$sink = SmtpSink::start();
            static::$sink->configure(['from_email' => 'site@example.com', 'from_name' => 'The Press Room']);

            $config = Idno::site()->config();
            foreach (['walled_garden', 'open_registration'] as $name) {
                static::$settings[$name] = $config->$name;
            }
            $config->walled_garden = false;
            $config->open_registration = true;
            $config->save();
        }

        public static function tearDownAfterClass(): void
        {
            $config = Idno::site()->config();
            foreach (static::$settings as $name => $value) {
                $config->$name = $value;
            }
            $config->save();
            static::$sink->stop();
            static::$sink = null;

            foreach (Invitation::all() as $invitation) {
                if (strpos((string) $invitation->email, '@array-input-test.example') !== false) {
                    Invitation::unrestricted(
                        function () use ($invitation) {
                            $invitation->delete();
                        }
                    );
                }
            }
            Idno::site()->db()->setIgnoreAccess(true);
            foreach (User::get([], [], 500) ?: [] as $user) {
                if (strpos((string) $user->email, '@array-input-test.example') !== false) {
                    $user->delete();
                }
            }
            Idno::site()->db()->setIgnoreAccess(false);
            parent::tearDownAfterClass();
        }

        protected function setUp(): void
        {
            parent::setUp();
            static::$sink->reset();
            static::$sink->configure(['from_email' => 'site@example.com', 'from_name' => 'The Press Room']);
        }

        /**
         * The arrays a field is sent as. Each is a function of the text the field should have
         * held, so that the array can name the very account the request is after.
         *
         * @return array[] name => [callable]
         */
        public static function shapes()
        {
            $shapes = [
                'a list'           => function ($real) {
                    return ['a'];
                },
                'a list of the real value' => function ($real) {
                    return [$real];
                },
                'greater than a'   => function ($real) {
                    return ['$gt' => 'a'];
                },
                'greater than nothing' => function ($real) {
                    return ['$gt' => ''];
                },
                'less than z'      => function ($real) {
                    return ['$lt' => 'zzzzzzzz'];
                },
                'not a'            => function ($real) {
                    return ['$not' => 'a'];
                },
                'not equal to a'   => function ($real) {
                    return ['$ne' => 'a'];
                },
                'an expression'    => function ($real) {
                    return ['$regex' => '.*'];
                },
                'in a list of the real value' => function ($real) {
                    return ['$in' => [$real]];
                },
            ];
            $cases = [];
            foreach ($shapes as $name => $shape) {
                foreach (['a form field', 'the json field', 'a JSON body'] as $transport) {
                    $cases[$name . ', as ' . $transport] = [$shape, $transport];
                }
            }

            return $cases;
        }

        /**
         * A new visitor's browser
         */
        protected function browser()
        {
            $jar = tempnam(sys_get_temp_dir(), 'idno-array-input-test-');
            static::$jars[' ' . $jar] = $jar; // Deleted with the others

            return $jar;
        }

        /**
         * Send a form with a valid form token from a browser, one of three ways: as form
         * fields (an array becomes name[key]=value), with everything but the token in the
         * "json" field, or as a JSON body with the token in the address.
         */
        protected function send($jar, $method, $path, array $fields, $transport)
        {
            $tokens = $method === 'GET' ? [] : $this->tokenFields($jar, $path);
            if ($method === 'GET') {
                $this->assertSame('a form field', $transport);

                return $this->requestWithJar($jar, 'GET', $path . '?' . http_build_query($fields));
            }
            switch ($transport) {
                case 'a form field':
                    return $this->requestWithJar($jar, $method, $path, $tokens + $fields);
                case 'the json field':
                    return $this->requestWithJar($jar, $method, $path, $tokens + ['json' => json_encode($fields)]);
                default:
                    return $this->sendJson($jar, $method, $path . '?' . http_build_query($tokens), $fields);
            }
        }

        protected function sendJson($jar, $method, $uri, array $fields)
        {
            $curl = curl_init(rtrim(Idno::site()->config()->getDisplayURL(), '/') . $uri);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            curl_setopt($curl, CURLOPT_COOKIEFILE, $jar);
            curl_setopt($curl, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($fields));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $response = (string) curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            unset($curl); // Writes the cookie jar
            $head = substr($response, 0, $headerSize);
            $location = preg_match('/^Location:\s*(\S+)/mi', $head, $match) ? $match[1] : '';

            return ['status' => $status, 'headers' => $head, 'body' => substr($response, $headerSize), 'location' => $location];
        }

        protected function assertNoServerError(array $response, $what)
        {
            $this->assertNotSame(0, $response['status'], $what . ': the site should answer');
            $this->assertLessThan(500, $response['status'], $what . ': no server error. ' . substr(strip_tags($response['body']), 0, 400));
        }

        /**
         * Is the browser with this cookie jar signed in?
         */
        protected function signedIn($jar)
        {
            return $this->requestWithJar($jar, 'GET', '/account/settings/')['status'] === 200;
        }

        protected function password()
        {
            return static::$passwords[static::$victim->getHandle()];
        }

        // Signing in

        /**
         * Each way of sending a form signs a member in when the fields are texts: the cases
         * below would pass for a way that never works otherwise
         *
         * @dataProvider transports
         */
        public function testATextSignsIn($transport)
        {
            $jar = $this->browser();
            $this->assertFalse($this->signedIn($jar));
            $response = $this->send($jar, 'POST', '/session/login', ['email' => static::$victim->getHandle(), 'password' => $this->password()], $transport);
            $this->assertNoServerError($response, 'Signing in');
            $this->assertTrue($this->signedIn($jar), 'The right handle and password, sent as ' . $transport . ', sign in');

            $jar = $this->browser();
            $this->send($jar, 'POST', '/session/login', ['email' => static::$victim->email, 'password' => $this->password()], $transport);
            $this->assertTrue($this->signedIn($jar), 'The right address and password, sent as ' . $transport . ', sign in');
        }

        public static function transports()
        {
            return [['a form field'], ['the json field'], ['a JSON body']];
        }

        /**
         * @dataProvider shapes
         */
        public function testSigningInSelectsNoMemberByAnArray($shape, $transport)
        {
            foreach ([static::$victim->getHandle(), static::$victim->email] as $real) {
                $jar = $this->browser();
                $response = $this->send($jar, 'POST', '/session/login', ['email' => $shape($real), 'password' => $this->password()], $transport);
                $this->assertNoServerError($response, 'Signing in');
                $this->assertFalse($this->signedIn($jar), 'Nobody is signed in');
            }
        }

        /**
         * @dataProvider shapes
         */
        public function testSigningInWithAPasswordThatIsAnArray($shape, $transport)
        {
            $jar = $this->browser();
            $response = $this->send($jar, 'POST', '/session/login', ['email' => static::$victim->getHandle(), 'password' => $shape($this->password())], $transport);
            $this->assertNoServerError($response, 'Signing in');
            $this->assertFalse($this->signedIn($jar), 'Nobody is signed in');
        }

        // Registration

        protected static function address($name = 'new')
        {
            return $name . bin2hex(random_bytes(5)) . '@array-input-test.example';
        }

        protected function registration(array $with = [])
        {
            $handle = 'arr' . bin2hex(random_bytes(5));

            return $with + [
                'name'      => 'Array Input',
                'handle'    => $handle,
                'email'     => static::address(),
                'password'  => 'a-long-password-9274',
                'password2' => 'a-long-password-9274',
            ];
        }

        protected function memberCount()
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $count = User::count();
            Idno::site()->db()->setIgnoreAccess(false);

            return $count;
        }

        /**
         * The control for the tests below: the same form, sent with texts, makes the account.
         *
         * @dataProvider transports
         */
        public function testATextRegisters($transport)
        {
            $fields = $this->registration();
            $response = $this->send($this->browser(), 'POST', '/account/register', $fields, $transport);
            $this->assertNoServerError($response, 'Registering');
            $made = User::getByHandle($fields['handle']);
            $this->assertInstanceOf(User::class, $made, 'Texts, sent as ' . $transport . ', make an account');
            $this->assertSame($fields['email'], $made->email);
        }

        /**
         * @dataProvider shapes
         */
        public function testRegistrationMakesNoAccountFromAnArray($shape, $transport)
        {
            foreach (['email', 'handle', 'password'] as $field) {
                $fields = $this->registration();
                $real = $fields[$field];
                if ($field === 'handle') {
                    // The array names a handle that is taken; with a text the account is refused for that
                    $real = static::$victim->getHandle();
                }
                $sent = [$field => $shape($real)] + $fields;
                if ($field === 'password') {
                    $sent['password2'] = $sent['password'];
                }
                $before = $this->memberCount();
                $response = $this->send($this->browser(), 'POST', '/account/register', $sent, $transport);
                $this->assertNoServerError($response, 'Registering with ' . $field . ' as an array');
                $this->assertSame($before, $this->memberCount(), 'No account is made with ' . $field . ' as an array');
                $this->assertFalse(User::getByEmail($fields['email']), 'No account has the address (' . $field . ' as an array)');
                if ($field !== 'handle') {
                    $this->assertFalse(User::getByHandle($fields['handle']), 'No account has the handle (' . $field . ' as an array)');
                }
            }
        }

        /**
         * On a site anyone can join, a code sent as an array is no code: the account is an
         * ordinary member's, as it is with no code at all
         *
         * @dataProvider shapes
         */
        public function testACodeThatIsAnArrayMakesNoAdministrator($shape, $transport)
        {
            $admin = static::address('admin');
            $invitation = new Invitation();
            $invitation->associateWithEmail($admin);
            $invitation->setRole(Invitation::ROLE_ADMIN);
            $invitation->save();

            $fields = $this->registration(['code' => $shape($invitation->code)]);
            $response = $this->send($this->browser(), 'POST', '/account/register', $fields, $transport);
            $this->assertNoServerError($response, 'Registering with the code as an array');
            if ($made = User::getByHandle($fields['handle'])) {
                $this->assertFalse($made->isAdmin(), 'The account is a member\'s');
            }
            $this->assertCount(1, Invitation::allForEmail($admin), 'The invitation is not used up');
        }

        /**
         * On a site closed to registration an invitation is found by its address and its code,
         * both texts
         *
         * @dataProvider shapes
         */
        public function testAnInvitationIsNotFoundByAnArray($shape, $transport)
        {
            $config = Idno::site()->config();
            $config->open_registration = false;
            $config->save();
            try {
                $invited = static::address('invited');
                $invitation = new Invitation();
                $invitation->associateWithEmail($invited);
                $invitation->save();

                foreach (['email' => $invited, 'code' => $invitation->code] as $field => $real) {
                    $fields = $this->registration(['email' => $invited, 'code' => $invitation->code]);
                    $sent = [$field => $shape($real)] + $fields;
                    $before = $this->memberCount();
                    $response = $this->send($this->browser(), 'POST', '/account/register', $sent, $transport);
                    $this->assertNoServerError($response, 'Registering with ' . $field . ' as an array');
                    $this->assertSame(403, $response['status'], 'The registration is refused (' . $field . ' as an array)');
                    $this->assertSame($before, $this->memberCount(), 'No account is made');
                    $this->assertCount(1, Invitation::allForEmail($invited), 'The invitation is not used up');
                    if ($transport === 'a form field') {
                        $page = $this->send($this->browser(), 'GET', '/account/register', [$field => $shape($real)] + ['email' => $invited, 'code' => $invitation->code], $transport);
                        $this->assertSame(403, $page['status'], 'The form is refused (' . $field . ' as an array)');
                    }
                }
            } finally {
                $config->open_registration = true;
                $config->save();
            }
        }

        // The password recovery request

        /**
         * @dataProvider transports
         */
        public function testATextAsksForARecoveryMail($transport)
        {
            $response = $this->send($this->browser(), 'POST', '/account/password', ['email' => static::$victim->email], $transport);
            $this->assertNoServerError($response, 'Asking for a recovery mail');
            $this->assertNotNull(static::$sink->lastTo(static::$victim->email), 'A text, sent as ' . $transport . ', sends the mail');
            $this->clearRecoveryCode();
        }

        protected function clearRecoveryCode()
        {
            $victim = static::fresh(static::$victim);
            $victim->clearPasswordRecoveryCode();
            $victim->save(true);
        }

        /**
         * @dataProvider shapes
         */
        public function testNoRecoveryMailIsSentForAnArray($shape, $transport)
        {
            $this->clearRecoveryCode();
            $response = $this->send($this->browser(), 'POST', '/account/password', ['email' => $shape(static::$victim->email)], $transport);
            $this->assertNoServerError($response, 'Asking for a recovery mail');
            $this->assertStringContainsString('account/password/?sent=true', $response['location'], 'The answer is the one every request gets');
            $this->assertSame([], static::$sink->messages(), 'No mail is sent');
            $this->assertEmpty(static::fresh(static::$victim)->getPasswordRecoveryCode(), 'No recovery code is made');
        }

        // The password reset

        /**
         * Give the member a recovery code, as the recovery request does
         */
        protected function recoveryCode()
        {
            $victim = static::fresh(static::$victim);
            $code = $victim->addPasswordRecoveryCode();
            $victim->save(true);

            return $code;
        }

        public function testATextOpensTheResetForm()
        {
            $code = $this->recoveryCode();
            $page = $this->send($this->browser(), 'GET', '/account/password/reset', ['email' => static::$victim->email, 'code' => $code], 'a form field');
            $this->assertSame(200, $page['status']);
            $this->assertStringContainsString('password2', $page['body'], 'The address and the code of the mail open the form');
        }

        /**
         * @dataProvider shapes
         */
        public function testAPasswordIsNotResetByAnArray($shape, $transport)
        {
            $code = $this->recoveryCode();
            $real = ['email' => static::$victim->email, 'code' => $code];
            foreach (['email', 'code', 'password'] as $field) {
                $sent = $real + ['password' => 'another-long-password-5531', 'password2' => 'another-long-password-5531'];
                $sent[$field] = $shape($sent[$field]);
                if ($field === 'password') {
                    $sent['password2'] = $sent['password'];
                }
                $response = $this->send($this->browser(), 'POST', '/account/password/reset', $sent, $transport);
                $this->assertNoServerError($response, 'Resetting with ' . $field . ' as an array');
                $victim = static::fresh(static::$victim);
                $this->assertTrue($victim->checkPassword($this->password()), 'The password is the one it was (' . $field . ' as an array)');
                $this->assertSame($code, $victim->getPasswordRecoveryCode(), 'The recovery code is not used up (' . $field . ' as an array)');

                if ($transport === 'a form field' && $field !== 'password') {
                    $page = $this->send($this->browser(), 'GET', '/account/password/reset', [$field => $shape($real[$field])] + $real, $transport);
                    $this->assertNoServerError($page, 'Opening the reset form with ' . $field . ' as an array');
                    $this->assertStringNotContainsString('password2', $page['body'], 'The form is not shown (' . $field . ' as an array)');
                }
            }
        }

        /**
         * With texts the same request changes the password: the cases above would pass for a
         * form that never works otherwise. Run last, as it changes the member's password.
         *
         * @dataProvider transports
         */
        public function testZTextsResetThePassword($transport)
        {
            $code = $this->recoveryCode();
            $new = 'a-new-long-password-' . bin2hex(random_bytes(4));
            $response = $this->send(
                $this->browser(), 'POST', '/account/password/reset',
                ['email' => static::$victim->email, 'code' => $code, 'password' => $new, 'password2' => $new], $transport
            );
            $this->assertNoServerError($response, 'Resetting');
            $this->assertTrue(static::fresh(static::$victim)->checkPassword($new), 'Texts, sent as ' . $transport . ', change the password');
            static::$passwords[static::$victim->getHandle()] = $new;
        }

        // The form token

        public function testAFormTokenThatIsAnArrayIsNoToken()
        {
            $jar = $this->browser();
            $tokens = $this->tokenFields($jar, '/session/login');
            foreach (['__bTs', '__bTk', '__bTa'] as $field) {
                $sent = [$field => [$tokens[$field]]] + $tokens + ['email' => static::$victim->getHandle(), 'password' => $this->password()];
                $response = $this->requestWithJar($jar, 'POST', '/session/login', $sent);
                $this->assertNoServerError($response, 'A form token with ' . $field . ' as an array');
                $this->assertFalse($this->signedIn($jar), 'Nobody is signed in (' . $field . ' as an array)');
            }
        }
    }

}
