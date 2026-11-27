<?php

namespace Tests\Pages\Admin {

    use Idno\Core\Idno;
    use Tests\SmtpSink;

    /**
     * The email settings page (/admin/email) and its test mail (/admin/emailtest), over HTTP:
     * only an administrator's browser reaches them, the stored password is never printed in
     * any template type, an empty password field keeps the stored one, what is typed is
     * checked, and the test mail reports what happened without the credentials.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT). Mail goes to a local sink (Tests\SmtpSink).
     */
    class EmailSettingsTest extends \Tests\BrowserTestCase
    {

        const PASSWORD = 'SG.stored-secret-password';

        /// @var SmtpSink
        protected static $sink;

        protected static $admin;
        protected static $member;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$admin = static::member('mailadmin', true);
            static::$member = static::member('mailmember');
            static::$sink = SmtpSink::start(['auth=apikey:' . self::PASSWORD]);
        }

        public static function tearDownAfterClass(): void
        {
            static::$sink->stop();
            static::$sink = null;
            parent::tearDownAfterClass();
        }

        protected function setUp(): void
        {
            parent::setUp();
            static::$sink->reset();
            static::$sink->configure(['smtp_username' => 'apikey', 'smtp_password' => self::PASSWORD, 'from_email' => 'site@example.com']);
        }

        /**
         * The settings as the web server sees them now
         */
        private function stored($name)
        {
            Idno::site()->config()->load();

            return Idno::site()->config()->$name;
        }

        private function fields(array $changes = [])
        {
            return $changes + [
                'from_email' => 'site@example.com', 'from_name' => 'The Site', 'reply_to_email' => '',
                'smtp_host' => '127.0.0.1', 'smtp_port' => (string) static::$sink->port(), 'smtp_secure' => '',
                'smtp_username' => 'apikey', 'smtp_password' => '',
            ];
        }

        public function testOnlyAnAdministratorsBrowserReachesThePage()
        {
            $this->assertSame(200, $this->get('/admin/email', static::$admin)['status']);
            $this->assertSame(403, $this->get('/admin/email', static::$member)['status']);
            $this->assertSame(403, $this->get('/admin/email')['status']);

            // A request signed with an administrator's API key is not an administrator
            $signed = $this->get('/admin/email', null, $this->signature(static::$admin, '/admin/email'));
            $this->assertSame(403, $signed['status']);
            $this->assertStringNotContainsString('smtp_host', $signed['body']);
        }

        public function testTheStoredPasswordIsNeverPrinted()
        {
            foreach (['', '?_t=default', '?_t=modern', '?_t=json', '?_t=jsonp', '?_t=rss'] as $type) {
                $page = $this->get('/admin/email' . $type, static::$admin);
                $this->assertStringNotContainsString(self::PASSWORD, $page['body'], 'The password must not be in /admin/email' . $type);
                $this->assertStringNotContainsString(base64_encode(self::PASSWORD), $page['body']);
            }
            $page = $this->get('/admin/email', static::$admin)['body'];
            $this->assertMatchesRegularExpression('/<input[^>]*name="smtp_password"[^>]*value=""/', $page);
            $this->assertMatchesRegularExpression('/<input[^>]*name="smtp_password"[^>]*autocomplete="new-password"/', $page);
            $this->assertStringContainsString('A password is saved', $page);
        }

        public function testTheDiagnosticsPageDoesNotShowTheCredentials()
        {
            foreach (['', '?_t=json'] as $type) {
                $page = $this->get('/admin/diagnostics' . $type, static::$admin);
                $this->assertStringNotContainsString(self::PASSWORD, $page['body']);
            }
        }

        public function testSavingWithAnEmptyPasswordKeepsTheStoredOne()
        {
            $response = $this->submit('/admin/email', $this->fields(['from_name' => 'Renamed Site', 'reply_to_email' => 'editor@example.com', 'smtp_secure' => 'tls', 'smtp_port' => '']), static::$admin);
            $this->assertSame(302, $response['status']);
            $this->assertStringContainsString('/admin/email', $response['location']);

            $this->assertSame(self::PASSWORD, $this->stored('smtp_password'));
            $this->assertSame('Renamed Site', $this->stored('from_name'));
            $this->assertSame('editor@example.com', $this->stored('reply_to_email'));
            $this->assertSame('tls', $this->stored('smtp_secure'));
            $this->assertSame(587, (int) $this->stored('smtp_port'), 'An empty port becomes the usual one for STARTTLS');
        }

        public function testANewPasswordReplacesTheStoredOneAndItCanBeRemoved()
        {
            $this->submit('/admin/email', $this->fields(['smtp_password' => 'SG.new-password']), static::$admin);
            $this->assertSame('SG.new-password', $this->stored('smtp_password'));

            $this->submit('/admin/email', $this->fields(['smtp_password_remove' => '1']), static::$admin);
            $this->assertSame('', (string) $this->stored('smtp_password'));
        }

        public function testWhatIsTypedIsCheckedAndNothingIsSavedWhenItIsWrong()
        {
            $response = $this->submit('/admin/email', $this->fields(['smtp_host' => 'https://smtp.example.com/', 'smtp_port' => '99999', 'from_email' => 'not-an-address', 'from_name' => 'Should Not Be Saved']), static::$admin);
            $this->assertSame(400, $response['status']);
            $this->assertStringContainsString('were not saved', $response['body']);
            $this->assertStringContainsString('no &quot;https://&quot;', $response['body']);
            $this->assertStringContainsString('between 1 and 65535', $response['body']);
            $this->assertStringContainsString("doesn&#039;t look like an email address", $response['body']);
            // What was typed is still in the form, escaped
            $this->assertStringContainsString('value="https://smtp.example.com/"', $response['body']);
            $this->assertStringNotContainsString(self::PASSWORD, $response['body']);

            $this->assertSame('127.0.0.1', $this->stored('smtp_host'));
            $this->assertNotSame('Should Not Be Saved', $this->stored('from_name'));
        }

        public function testAFieldCannotCarryMarkupIntoThePage()
        {
            $response = $this->submit('/admin/email', $this->fields(['from_name' => '"><script>alert(1)</script>']), static::$admin);
            $this->assertSame(302, $response['status']);
            $page = $this->get('/admin/email', static::$admin)['body'];
            $this->assertStringNotContainsString('<script>alert(1)</script>', $page);
            $this->assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        }

        public function testSavingNeedsAnAdministratorAndAFormToken()
        {
            $before = $this->stored('from_name');
            // A member, with a form token of their own
            $tokens = $this->formFields($this->get('/account/settings/', static::$member)['body']);
            $this->assertCount(3, $tokens);
            $response = $this->request('POST', '/admin/email', $tokens + $this->fields(['from_name' => 'By A Member']), static::$member);
            $this->assertSame(403, $response['status']);
            // An administrator's browser with no token at all (a forged cross-site request)
            $this->request('POST', '/admin/email', $this->fields(['from_name' => 'No Token']), static::$admin);
            // An administrator's API key
            $this->request('POST', '/admin/email', $this->fields(['from_name' => 'By API Key']), null, $this->signature(static::$admin, '/admin/email'));
            $this->assertSame($before, $this->stored('from_name'));
        }

        public function testTheTestMailIsSentToTheAddressTyped()
        {
            $page = $this->get('/admin/email', static::$admin)['body'];
            $this->assertMatchesRegularExpression('/<input[^>]*name="to_email"[^>]*value="' . preg_quote(static::$admin->email, '/') . '"/', $page, "The administrator's own address is suggested");

            $response = $this->submit('/admin/emailtest', ['to_email' => 'somebody@example.org'], static::$admin, '/admin/email');
            $this->assertSame(302, $response['status']);
            $page = $this->get('/admin/email', static::$admin)['body'];
            $this->assertStringContainsString('accepted a test email for somebody@example.org', $page);

            $message = static::$sink->lastTo('somebody@example.org');
            $this->assertNotNull($message);
            $this->assertStringContainsString('site@example.com', $message['headers']['from']);
        }

        public function testAFailedTestMailSaysWhyWithoutTheCredentials()
        {
            static::$sink->configure(['smtp_username' => 'apikey', 'smtp_password' => 'SG.wrong-password']);

            $this->submit('/admin/emailtest', ['to_email' => 'somebody@example.org'], static::$admin, '/admin/email');
            $page = $this->get('/admin/email', static::$admin)['body'];
            $this->assertStringContainsString('was not sent', $page);
            $this->assertStringContainsString('did not accept the user name and password', $page);
            $this->assertStringNotContainsString('SG.wrong-password', $page);
            $this->assertNull(static::$sink->lastTo('somebody@example.org'));
        }

        public function testATestMailToAServerThatIsNotThereFailsQuickly()
        {
            $dead = SmtpSink::start();
            $port = $dead->port();
            $dead->stop();
            static::$sink->configure(['smtp_port' => $port]);

            $started = microtime(true);
            $response = $this->submit('/admin/emailtest', ['to_email' => 'somebody@example.org'], static::$admin, '/admin/email');
            $this->assertLessThan(15, microtime(true) - $started);
            $this->assertSame(302, $response['status']);
            $this->assertStringContainsString('refused the connection', $this->get('/admin/email', static::$admin)['body']);
        }

        public function testTheTestMailNeedsAnAdministratorAndAnAddress()
        {
            $tokens = $this->formFields($this->get('/account/settings/', static::$member)['body']);
            $response = $this->request('POST', '/admin/emailtest', $tokens + ['to_email' => 'somebody@example.org'], static::$member);
            $this->assertSame(403, $response['status']);
            $this->request('POST', '/admin/emailtest', ['to_email' => 'somebody@example.org'], static::$admin);
            $this->request('POST', '/admin/emailtest', ['to_email' => 'somebody@example.org'], null, $this->signature(static::$admin, '/admin/emailtest'));
            $this->assertNull(static::$sink->lastTo('somebody@example.org'));

            $this->submit('/admin/emailtest', ['to_email' => 'not an address'], static::$admin, '/admin/email');
            $this->assertStringContainsString('Enter the email address to send the test to', $this->get('/admin/email', static::$admin)['body']);
            $this->assertCount(0, static::$sink->messages());
        }

        public function testThePageSaysWhenNoMailServiceIsSetUp()
        {
            static::$sink->configure(['smtp_host' => '']);
            $page = $this->get('/admin/email', static::$admin)['body'];
            $this->assertStringContainsString('No mail service is set up yet', $page);

            static::$sink->configure();
            $page = $this->get('/admin/email', static::$admin)['body'];
            $this->assertStringNotContainsString('No mail service is set up yet', $page);
            $this->assertStringContainsString('Mail is sent through 127.0.0.1', $page);
        }

        public function testThePageNamesTheSettingsThatTheConfigurationFileSets()
        {
            $config = Idno::site()->config();
            $before = $config->ini_config;
            try {
                $config->ini_config = ['dbname' => 'x'];
                $this->assertSame([], \Idno\Pages\Admin\Email::settingsFromFile());
                $config->ini_config = ['dbname' => 'x', 'smtp_host' => 'smtp.example.com', 'smtp_password' => 'from-the-file'];
                $this->assertSame(['smtp_host', 'smtp_password'], \Idno\Pages\Admin\Email::settingsFromFile(), 'Names only, never values');
            } finally {
                $config->ini_config = $before;
            }
            // The test site's configuration file sets no mail setting, so its page has no such note
            $this->assertStringNotContainsString('config.ini', $this->get('/admin/email', static::$admin)['body']);
        }
    }

}
