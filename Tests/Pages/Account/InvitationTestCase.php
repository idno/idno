<?php

namespace Tests\Pages\Account {

    use Idno\Core\Idno;
    use Idno\Entities\Invitation;
    use Idno\Entities\User;
    use Tests\SmtpSink;

    /**
     * What the invitation tests share: a private site that is closed to registration, an
     * administrator who invites, a mail sink (Tests\SmtpSink) to read the links from, and
     * visitors' browsers to open them with.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    abstract class InvitationTestCase extends \Tests\BrowserTestCase
    {

        /// @var SmtpSink
        protected static $sink;

        protected static $admin;
        protected static $member;

        /// The site's settings before the tests, to put back
        protected static $settings = [];

        /// Cookie jars of visitors, to delete
        protected static $visitors = [];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$admin = static::member('inviter', true);
            // A name with markup in it: it must arrive in the mail and the pages as text
            static::$admin->setTitle('Ada <img src=x onerror=alert(1)> Admin');
            static::$admin->save(true);
            static::$member = static::member('invmember');

            static::$sink = SmtpSink::start();
            static::$sink->configure(['from_email' => 'site@example.com', 'from_name' => 'The Press Room']);

            $config = Idno::site()->config();
            foreach (['walled_garden', 'open_registration'] as $name) {
                static::$settings[$name] = $config->$name;
            }
            $config->walled_garden = true;
            $config->open_registration = false;
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
                if (strpos((string) $invitation->email, '@invite-test.example') !== false) {
                    Invitation::unrestricted(
                        function () use ($invitation) {
                            $invitation->delete();
                        }
                    );
                }
            }
            Idno::site()->db()->setIgnoreAccess(true);
            foreach (User::get([], [], 500) ?: [] as $user) {
                if (strpos((string) $user->email, '@invite-test.example') !== false) {
                    $user->delete();
                }
            }
            Idno::site()->db()->setIgnoreAccess(false);
            foreach (static::$visitors as $jar) {
                if (file_exists($jar)) {
                    unlink($jar);
                }
            }
            static::$visitors = [];
            parent::tearDownAfterClass();
        }

        protected function setUp(): void
        {
            parent::setUp();
            static::$sink->reset();
            static::$sink->configure(['from_email' => 'site@example.com', 'from_name' => 'The Press Room']);
        }

        protected static function address($name)
        {
            return $name . substr(md5(rand() . microtime(true)), 0, 8) . '@invite-test.example';
        }

        /**
         * A visitor's browser: a cookie jar that has not signed in
         */
        protected function visitor()
        {
            $jar = tempnam(sys_get_temp_dir(), 'idno-visitor-test-');
            static::$visitors[] = $jar;

            return $jar;
        }

        /**
         * A request from a visitor's browser
         */
        protected function visit($jar, $method, $uri, $body = null)
        {
            $base = rtrim(Idno::site()->config()->getDisplayURL(), '/');
            if (strpos($uri, 'http') !== 0) {
                $uri = $base . $uri;
            }
            $curl = curl_init($uri);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            curl_setopt($curl, CURLOPT_COOKIEFILE, $jar);
            curl_setopt($curl, CURLOPT_COOKIEJAR, $jar);
            if ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body));
            }
            $response = (string) curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            unset($curl); // Writes the cookie jar
            $head = substr($response, 0, $headerSize);
            $location = preg_match('/^Location:\s*(\S+)/mi', $head, $match) ? $match[1] : '';

            return ['status' => $status, 'headers' => $head, 'body' => substr($response, $headerSize), 'location' => $location];
        }

        /**
         * Invite addresses from the admin Users page, as the administrator
         */
        protected function invite($addresses, array $fields = [])
        {
            return $this->submit('/admin/users', $fields + ['action' => 'invite_users', 'invitation_emails' => $addresses, 'invitation_role' => 'member'], static::$admin);
        }

        /**
         * An address from a mail, on the host these tests reach the site by. The web server
         * writes links with the name it was started on, which need not be the name the tests
         * use (CI serves on 0.0.0.0 and is reached as localhost), and a browser keeps its
         * cookies by host name.
         */
        protected static function onThisSite($url)
        {
            $base = rtrim(Idno::site()->config()->getDisplayURL(), '/');
            $path = (string) parse_url($url, PHP_URL_PATH);
            $query = (string) parse_url($url, PHP_URL_QUERY);

            return $base . $path . ($query !== '' ? '?' . $query : '');
        }

        /**
         * The link in the invitation mail to an address
         */
        protected function linkFor($address)
        {
            $message = static::$sink->lastTo($address);
            $this->assertNotNull($message, 'There should be an invitation mail to ' . $address);
            $this->assertSame(1, preg_match('#https?://\S+/account/register/\?email=\S+?&code=[0-9a-f]{64}#', $message['text'], $match), 'The mail should hold the link');

            return static::onThisSite($match[0]);
        }

        /**
         * Fill in the registration form behind a link as a visitor
         */
        protected function register($jar, $link, $handle, array $fields = [])
        {
            $form = $this->visit($jar, 'GET', $link);
            $tokens = $this->formFields($form['body'], '/account/register');
            $this->assertCount(3, $tokens, 'The registration page should hold a form (status ' . $form['status'] . ')');
            parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

            return $this->visit(
                $jar, 'POST', '/account/register', $tokens + $fields + [
                    'name' => 'New ' . $handle, 'handle' => $handle, 'email' => $query['email'], 'code' => $query['code'],
                    'password' => 'a-good-password-1', 'password2' => 'a-good-password-1',
                ]
            );
        }

        protected static function handle()
        {
            return 'inv' . substr(md5(rand() . microtime(true)), 0, 10);
        }
    }

}
