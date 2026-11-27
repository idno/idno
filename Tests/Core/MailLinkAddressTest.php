<?php

namespace Tests\Core {

    use Idno\Entities\Invitation;
    use Tests\SmtpSink;

    /**
     * The links of a mail are on the address the site is configured with, whatever request
     * had the mail sent. A site configured as https that also answers plain http must not
     * mail a member an http link to reset their password, which would carry the code of the
     * reset in the clear when it is opened; and a mail sent by a console process, which has
     * no request, must link to the site all the same.
     *
     * Each mail is sent by Tests/Core/fixtures/mail_for_request.php in a process of its own,
     * so that the request is what the site finds when it starts, and is read from a local
     * sink (Tests\SmtpSink).
     */
    class MailLinkAddressTest extends \Tests\BrowserTestCase
    {

        const SITE = 'https://press.example/';

        /// @var SmtpSink
        private static $sink;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$sink = SmtpSink::start();
            static::$sink->configure();
        }

        public static function tearDownAfterClass(): void
        {
            static::$sink->stop();
            static::$sink = null;
            parent::tearDownAfterClass();
        }

        /**
         * Have the site send a mail in a process of its own and give the mail back.
         *
         * @param  string $mail    'password' or 'invitation'
         * @param  string $request 'http', 'https' or 'none'
         * @param  string $address Who the mail is for
         * @return string The mail as the sink received it, decoded
         */
        private function mailSentBy($mail, $request, $address)
        {
            static::$sink->reset();
            // 22527 is E_ALL without deprecations, which pedantic mode would turn into errors
            $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=1 '
                . escapeshellarg(__DIR__ . '/fixtures/mail_for_request.php') . ' '
                . escapeshellarg($mail) . ' ' . escapeshellarg($request) . ' ' . escapeshellarg(self::SITE) . ' ' . escapeshellarg($address) . ' 2>&1';
            $output = (string) shell_exec($command);

            $message = static::$sink->lastTo($address);
            $this->assertNotNull($message, 'The mail should have been sent. The process said: ' . $output);

            return $message['text'];
        }

        private function assertLinksToTheConfiguredSite($text, $path)
        {
            $this->assertStringContainsString(self::SITE . $path, $text, 'The link is on the address the site is configured with');
            $this->assertStringNotContainsString('http://press.example', $text, 'No link of the mail has the scheme of the request instead of the site\'s own');
        }

        /**
         * A reset asked for over plain http on an https site: the member's link, which holds
         * the code, is https.
         */
        public function testAPasswordResetAskedForOverPlainHttpLinksToTheHttpsSite()
        {
            $member = static::member('resetlink');

            $text = $this->mailSentBy('password', 'http', $member->email);

            $this->assertLinksToTheConfiguredSite($text, 'account/password/reset/?email=');
        }

        /**
         * An invitation sent by an administrator who reached the https site over plain http:
         * the invited person's link, which holds the code, is https.
         */
        public function testAnInvitationSentOverPlainHttpLinksToTheHttpsSite()
        {
            $address = 'invited' . bin2hex(random_bytes(4)) . '@example.com';
            try {
                $text = $this->mailSentBy('invitation', 'http', $address);

                $this->assertLinksToTheConfiguredSite($text, 'account/register/?email=');
            } finally {
                Invitation::removeByEmail($address);
            }
        }

        /**
         * A mail sent by a console process (the queue's, say), which has no request: its
         * links are still on the site's address, https included.
         */
        public function testAMailSentWithNoRequestLinksToTheConfiguredSite()
        {
            $address = 'invited' . bin2hex(random_bytes(4)) . '@example.com';
            try {
                $text = $this->mailSentBy('invitation', 'none', $address);

                $this->assertLinksToTheConfiguredSite($text, 'account/register/?email=');
            } finally {
                Invitation::removeByEmail($address);
            }
        }
    }

}
