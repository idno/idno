<?php

namespace Tests\Core {

    use Idno\Core\Email;
    use Idno\Core\Idno;
    use Tests\SmtpSink;

    /**
     * Sending mail: the connection is made from the stored settings, a message is sent From
     * the configured name and address, and a failure is explained in plain words that never
     * hold the user name or the password. Mail goes to a local sink (Tests\SmtpSink).
     */
    class EmailTest extends \Tests\IdnoTestCase
    {

        /// @var SmtpSink
        private $sink;

        protected function tearDown(): void
        {
            if ($this->sink) {
                $this->sink->stop();
                $this->sink = null;
            }
            parent::tearDown();
        }

        private function message($to = 'reader@example.com', $subject = 'Hello')
        {
            $message = new Email();
            $message->addTo($to);
            $message->setSubject($subject);
            $message->setHTMLBody('<p>Body</p>', false);
            $message->setTextBody('Body');

            return $message;
        }

        public function testAMessageIsSentFromTheConfiguredNameAndAddressWithTheReplyAddress()
        {
            $this->sink = SmtpSink::start();
            $this->sink->configure(['from_email' => 'news@example.com', 'from_name' => 'The Newsroom', 'reply_to_email' => 'editor@example.com']);

            $message = $this->message();
            $this->assertSame(1, $message->send());
            $this->assertSame('', $message->error);

            $received = $this->sink->lastTo('reader@example.com');
            $this->assertNotNull($received, 'The sink should have the message');
            $this->assertSame('news@example.com', $received['from']);
            $this->assertStringContainsString('The Newsroom <news@example.com>', $received['headers']['from']);
            $this->assertStringContainsString('editor@example.com', $received['headers']['reply-to']);
        }

        public function testTheFromNameIsTheSiteNameWhenNoneIsSet()
        {
            $this->sink = SmtpSink::start();
            $this->sink->configure(['from_name' => '']);

            $this->assertSame(1, $this->message()->send());
            $received = $this->sink->lastTo('reader@example.com');
            $this->assertStringContainsString(Email::headerText(Idno::site()->config()->title), $received['headers']['from']);
        }

        public function testAMessageThatNamesAReplyAddressKeepsIt()
        {
            $this->sink = SmtpSink::start();
            $this->sink->configure(['reply_to_email' => 'editor@example.com']);

            $message = $this->message();
            $message->setReplyTo('inviter@example.com');
            $this->assertSame(1, $message->send());
            $received = $this->sink->lastTo('reader@example.com');
            $this->assertStringContainsString('inviter@example.com', $received['headers']['reply-to']);
            $this->assertStringNotContainsString('editor@example.com', $received['headers']['reply-to']);
        }

        public function testNothingTypedIntoASubjectOrANameCanStartAHeader()
        {
            $this->sink = SmtpSink::start();
            $this->sink->configure(['from_name' => "Newsroom\r\nBcc: thief@example.com"]);

            $message = $this->message('reader@example.com', "Hello\r\nBcc: other@example.com\r\n\r\nInjected body");
            $this->assertSame(1, $message->send());

            $received = $this->sink->lastTo('reader@example.com');
            $this->assertSame(['reader@example.com'], $received['to'], 'Only the intended recipient gets the message');
            $this->assertArrayNotHasKey('bcc', $received['headers']);
            $this->assertStringContainsString('Hello Bcc: other@example.com Injected body', $received['headers']['subject']);
        }

        public function testTheUserNameAndPasswordAreSentWhenTheServerAsksForThem()
        {
            $this->sink = SmtpSink::start(['auth=apikey:SG.right-password']);
            $this->sink->configure(['smtp_username' => 'apikey', 'smtp_password' => 'SG.right-password']);

            $this->assertSame(1, $this->message()->send());
        }

        public function testAWrongPasswordIsExplainedWithoutTheCredentials()
        {
            $this->sink = SmtpSink::start(['auth=apikey:SG.right-password']);
            $this->sink->configure(['smtp_username' => 'secret-user-token', 'smtp_password' => 'SG.wrong-password']);

            $message = $this->message();
            $this->assertSame(0, $message->send());
            $this->assertSame('auth', $message->errorKind);
            $this->assertStringContainsString('did not accept the user name and password', $message->error);
            $this->assertStringNotContainsString('SG.wrong-password', $message->error);
            $this->assertStringNotContainsString('secret-user-token', $message->error);
            $this->assertCount(0, $this->sink->messages());
        }

        public function testARefusedSenderIsExplainedWithWhatTheServerSaid()
        {
            $this->sink = SmtpSink::start(['rejectfrom=unverified@example.com']);
            $this->sink->configure(['from_email' => 'unverified@example.com']);

            $message = $this->message();
            $this->assertSame(0, $message->send());
            $this->assertSame('sender', $message->errorKind);
            $this->assertStringContainsString('would not send from this address', $message->error);
            $this->assertStringContainsString('verified sender identity', $message->error);
        }

        public function testAServerThatDoesNotAnswerFailsQuicklyAndIsExplained()
        {
            // A port nothing listens on: the sink's own port, once it has stopped
            $this->sink = SmtpSink::start();
            $port = $this->sink->port();
            $this->sink->configure();
            $this->sink->stop();
            $this->sink = null;

            $config = Idno::site()->config();
            $before = [$config->smtp_host, $config->smtp_port, $config->from_email];
            $config->smtp_host = '127.0.0.1';
            $config->smtp_port = $port;
            $config->from_email = 'site@example.com';
            try {
                $message = $this->message();
                $started = microtime(true);
                $this->assertSame(0, $message->send());
                $this->assertLessThan(Email::DEFAULT_TIMEOUT + 2, microtime(true) - $started);
                $this->assertSame('connect', $message->errorKind);
                $this->assertStringContainsString('port ' . $port, $message->error);
            } finally {
                list($config->smtp_host, $config->smtp_port, $config->from_email) = $before;
            }
        }

        public function testStartTlsOnAServerThatDoesNotOfferItIsExplainedAsASecurityProblem()
        {
            $this->sink = SmtpSink::start();
            $this->sink->configure(['smtp_secure' => 'tls']);

            $message = $this->message();
            $this->assertSame(0, $message->send());
            $this->assertSame('tls', $message->errorKind);
            $this->assertStringContainsString('STARTTLS', $message->error);
        }

        public function testThePortFollowsTheSecuritySettingWhenNoneIsStored()
        {
            $config = Idno::site()->config();
            $before = [$config->smtp_port, $config->smtp_secure];
            try {
                $config->smtp_port = 0;
                foreach (['tls' => 587, 'ssl' => 465, '' => 25, 'nonsense' => 25] as $secure => $port) {
                    $config->smtp_secure = $secure;
                    $this->assertSame($port, Email::port(), 'Port for security "' . $secure . '"');
                    $this->assertSame($port, Email::transport()->getPort());
                }
                $config->smtp_secure = 'tls';
                $config->smtp_port = 2525;
                $this->assertSame(2525, Email::port());
                $transport = Email::transport();
                $this->assertSame('tls', $transport->getEncryption(), 'STARTTLS is SwiftMailer\'s "tls"');
                $this->assertSame(Email::DEFAULT_TIMEOUT, $transport->getTimeout());
            } finally {
                list($config->smtp_port, $config->smtp_secure) = $before;
            }
        }

        public function testTheSiteIsConfiguredOnlyWithAServerAndAFromAddress()
        {
            $config = Idno::site()->config();
            $before = [$config->smtp_host, $config->from_email];
            try {
                $config->smtp_host = '';
                $config->from_email = 'site@example.com';
                $this->assertFalse(Email::isConfigured());
                $config->smtp_host = 'smtp.example.com';
                $this->assertTrue(Email::isConfigured());
                $config->from_email = 'not an address';
                $this->assertFalse(Email::isConfigured());
            } finally {
                list($config->smtp_host, $config->from_email) = $before;
            }
        }

        public function testScrubTakesTheCredentialsOutInEveryForm()
        {
            $config = Idno::site()->config();
            $before = [$config->smtp_username, $config->smtp_password];
            try {
                $config->smtp_username = 'apikey-user';
                $config->smtp_password = 'SG.very-secret';
                $text = 'user "apikey-user" pass SG.very-secret b64 ' . base64_encode('SG.very-secret') . ' ' . base64_encode('apikey-user')
                    . ' plain ' . base64_encode("\0apikey-user\0SG.very-secret");
                $scrubbed = Email::scrub($text);
                foreach (['apikey-user', 'SG.very-secret', base64_encode('SG.very-secret'), base64_encode('apikey-user'), base64_encode("\0apikey-user\0SG.very-secret")] as $secret) {
                    $this->assertStringNotContainsString($secret, $scrubbed);
                }
            } finally {
                list($config->smtp_username, $config->smtp_password) = $before;
            }
        }

        public static function failures(): array
        {
            return [
                'name not found' => ['Connection could not be established with host smtp.example.invalid :stream_socket_client(): php_network_getaddresses: getaddrinfo for smtp.example.invalid failed: nodename nor servname provided, or not known', 'connect', 'could not be found'],
                'timed out'      => ['Connection could not be established with host 10.255.255.1 :stream_socket_client(): Unable to connect to 10.255.255.1:587 (Operation timed out)', 'connect', 'did not answer'],
                'read timeout'   => ['Connection to tcp://smtp.example.com:465 Timed Out', 'connect', 'security setting matches the port'],
                'certificate'    => ['Connection could not be established with host ssl://smtp.example.com :stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed', 'tls', 'certificate'],
                'temporary'      => ['Expected response code 250 but got code "451", with message "451 4.7.1 Try again later"', 'temporary', 'Try again'],
                'refused'        => ['Expected response code 250 but got code "554", with message "554 5.7.1 Message refused as spam"', 'rejected', 'Message refused as spam'],
            ];
        }

        /**
         * @dataProvider failures
         */
        public function testFailuresAreExplained($raw, $kind, $words)
        {
            list($gotKind, $message) = Email::explain(new \Swift_TransportException($raw));
            $this->assertSame($kind, $gotKind);
            $this->assertStringContainsString($words, $message);
        }
    }

}
