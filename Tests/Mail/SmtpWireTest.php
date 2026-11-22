<?php

declare(strict_types=1);

namespace Tests\Mail;

use Idno\Core\Email;
use Idno\Core\Idno;
use Idno\Entities\Invitation;
use Idno\Mail\MailerFactory;
use Idno\Mail\SmtpTransportFactory;
use Tests\Fixtures\TestMailTransportFactory;
use Tests\IdnoTestCase;

/**
 * Mail on the wire, beyond parity with SwiftMailer: the site's settings, the production mailer and
 * transport, and a real SMTP server (Tests\Fixtures\Smtp\ScriptedSmtpServer) that records what arrives.
 * Nothing leaves the machine. The certificates are made per test class; the client trusts the test
 * authority only through the test wiring's `cafile`, and verifies certificates as it always does.
 */
final class SmtpWireTest extends IdnoTestCase
{
    use MailTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailSetUp();
    }

    protected function tearDown(): void
    {
        $this->mailTearDown();
        parent::tearDown();
    }

    private const PLAIN_WITH_SELF_SIGNED_STARTTLS = ['offerStartTls' => true, 'certificate' => 'self-signed'];

    public function testEmptySecureNeverSendsStartTlsEvenWhenOfferedWithASelfSignedCertificate(): void
    {
        $server = $this->startSmtp(self::PLAIN_WITH_SELF_SIGNED_STARTTLS);
        $this->mailSettings(['smtp_host' => '127.0.0.1']);

        $this->assertSame(1, $this->sampleEmail()->send());

        $connection = $server->connections()[0];
        $this->assertFalse($connection['startTlsSent']);
        $this->assertFalse($connection['tlsEstablished']);
        $this->assertCount(1, $server->messages(), 'the message is delivered, in clear');
        $this->assertStringContainsString('Hello, wire.', $server->messages()[0]);
    }

    public function testTlsRequiresStartTlsAndFailsWhenTheServerDoesNotOfferIt(): void
    {
        $server = $this->startSmtp([]);
        $this->mailSettings(['smtp_host' => '127.0.0.1', 'smtp_secure' => 'tls']);
        $email = $this->sampleEmail();

        $this->assertSame(0, $email->send());

        $this->assertSame('transport', $email->lastFailure());
        $this->assertSame([], $server->messages());
        $this->assertNull($server->connections()[0]['mailFrom'], 'nothing was sent in clear: no MAIL FROM');
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function certificates(): iterable
    {
        yield 'tls, a certificate of the trusted authority' => ['tls', 'valid-for-localhost', true];
        yield 'tls, a self-signed certificate' => ['tls', 'self-signed', false];
        yield 'ssl, a certificate of the trusted authority' => ['ssl', 'valid-for-localhost', true];
        yield 'ssl, a self-signed certificate' => ['ssl', 'self-signed', false];
    }

    /**
     * @dataProvider certificates
     */
    public function testTlsAndSslVerifyTheCertificate(string $secure, string $certificate, bool $delivered): void
    {
        $server = $this->startSmtp([
            'implicitTls' => $secure === 'ssl',
            'offerStartTls' => $secure === 'tls',
            'certificate' => $certificate,
        ]);
        $this->mailSettings(['smtp_host' => '127.0.0.1', 'smtp_secure' => $secure]);
        $email = $this->sampleEmail();

        $this->assertSame($delivered ? 1 : 0, $email->send());

        $connection = $server->connections()[0];
        $this->assertSame($delivered, $connection['tlsEstablished']);
        $this->assertCount($delivered ? 1 : 0, $server->messages());
        if (!$delivered) {
            $this->assertNull($connection['mailFrom'], 'nothing is sent over a connection whose certificate was refused');
            $this->assertSame('transport', $email->lastFailure());
        }
    }

    public function testAPasswordWithSpecialCharactersAuthenticates(): void
    {
        $password = SettingsMatrix::PASSWORD_WITH_SPECIAL_CHARACTERS;
        $server = $this->startSmtp(['auth' => ['user' => 'mail user@example.com', 'pass' => $password]]);
        $this->mailSettings(['smtp_host' => '127.0.0.1', 'smtp_username' => 'mail user@example.com', 'smtp_password' => $password]);

        $this->assertSame(1, $this->sampleEmail()->send());

        $this->assertTrue($server->connections()[0]['authAccepted']);
        $this->assertCount(1, $server->messages());
    }

    public function testAUsernameWithAnEmptyPasswordLogsInWithIt(): void
    {
        $server = $this->startSmtp(['auth' => ['user' => 'mailer', 'pass' => '']]);
        $this->mailSettings(['smtp_host' => '127.0.0.1', 'smtp_username' => 'mailer', 'smtp_password' => '']);

        $this->assertSame(1, $this->sampleEmail()->send());
        $this->assertTrue($server->connections()[0]['authAccepted']);
    }

    public function testAUsernameWithAnEmptyPasswordIsRefusedWhenTheServerRefuses(): void
    {
        $server = $this->startSmtp(['auth' => ['user' => 'mailer', 'pass' => 'sesame']]);
        $this->mailSettings(['smtp_host' => '127.0.0.1', 'smtp_username' => 'mailer']);
        $email = $this->sampleEmail();

        $this->assertSame(0, $email->send());
        $this->assertSame('transport', $email->lastFailure());
        $this->assertNotNull($server->connections()[0]['authMechanism'], 'a login was attempted');
        $this->assertFalse($server->connections()[0]['authAccepted']);
        $this->assertSame([], $server->messages());
    }

    public function testARefusedLoginReturnsZeroAndLogsNeitherUserNorPassword(): void
    {
        $user = 'private-user-name';
        $password = 'private-pass-word-9';
        $this->startSmtp(['auth' => ['user' => $user, 'pass' => $password], 'failAt' => 'auth']);
        $this->mailSettings(['smtp_host' => '127.0.0.1', 'smtp_username' => $user, 'smtp_password' => $password]);
        $email = $this->sampleEmail();

        $this->assertSame(0, $email->send());

        $this->assertSame('transport', $email->lastFailure());
        $log = $this->mailErrorLogContents();
        $this->assertStringContainsString('Error sending mail', $log);
        $this->assertStringNotContainsString($user, $log);
        $this->assertStringNotContainsString($password, $log);
        $this->assertStringNotContainsString($password, (string) $email->lastFailure());
    }

    public function testARefusedRecipientReturnsZero(): void
    {
        $server = $this->startSmtp(['failAt' => 'rcpt']);
        $this->mailSettings(['smtp_host' => '127.0.0.1']);

        $this->assertSame(0, $this->sampleEmail()->send());
        $this->assertSame([], $server->messages());
    }

    public function testAMessageRefusedAfterDataReturnsZero(): void
    {
        $server = $this->startSmtp(['failAt' => 'data']);
        $this->mailSettings(['smtp_host' => '127.0.0.1']);

        $this->assertSame(0, $this->sampleEmail()->send());
        $this->assertSame([], $server->messages());
    }

    public function testAServerThatRefusesTheConnectionReturnsZero(): void
    {
        $this->startSmtp(['failAt' => 'connect']);
        $this->mailSettings(['smtp_host' => '127.0.0.1']);

        $this->assertSame(0, $this->sampleEmail()->send());
    }

    public function testNothingIsListeningReturnsZero(): void
    {
        $server = $this->startSmtp([]);
        $port = $server->port();
        $server->stop();
        $this->mailSettings(['smtp_host' => '127.0.0.1']);
        $this->mailEnvironment(TestMailTransportFactory::DELIVER, '127.0.0.1:' . $port);

        $email = $this->sampleEmail();
        $this->assertSame(0, $email->send());
        $this->assertSame('transport', $email->lastFailure());
    }

    public function testTheProductionBoundIsThirtySeconds(): void
    {
        $this->startSmtp([]);
        $this->mailSettings(['smtp_host' => '127.0.0.1']);
        $this->sampleEmail()->send();

        $this->assertSame(30.0, TestMailTransportFactory::built()[0]['timeout']);
        $this->assertSame(30.0, SmtpTransportFactory::TIMEOUT_SECONDS);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function hangs(): iterable
    {
        yield 'a server that never greets' => [['failAt' => 'hang'], []];
        yield 'a server that never finishes STARTTLS' => [['failAt' => 'hangAtStartTls', 'offerStartTls' => true, 'certificate' => 'valid-for-localhost'], ['smtp_secure' => 'tls']];
    }

    /**
     * The factory is the production one with a short bound (its constructor argument; production uses
     * 30 seconds), so the test waits seconds, not the half minute.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $settings
     * @dataProvider hangs
     */
    public function testAServerThatHangsIsGivenUpOnWithinTheTimeout(array $server, array $settings): void
    {
        $smtp = $this->startSmtp($server);
        $mailer = (new MailerFactory(new ArraySettings($settings + ['smtp_host' => '127.0.0.1', 'smtp_port' => $smtp->port()]), new SmtpTransportFactory(timeout: 1.0)))->create();
        Email::useMailer($mailer);
        $email = $this->sampleEmail();

        $started = microtime(true);
        $sent = $email->send();
        $elapsed = microtime(true) - $started;

        $this->assertSame(0, $sent);
        $this->assertSame('transport', $email->lastFailure());
        $this->assertLessThan(6.0, $elapsed, 'the page does not wait for a server that hangs');
        $this->assertGreaterThan(0.5, $elapsed, 'it did wait for the bound');
    }

    public function testHtmlAndTextPartsAndHeadersArriveAsBuilt(): void
    {
        $server = $this->startSmtp([]);
        $this->mailSettings(['smtp_host' => '127.0.0.1', 'title' => "Sit\u{e9} \u{dc}n\u{ef}"]);
        $email = new Email();
        $email->addTo('reader@example.net', "R\u{e9}ader");
        $email->addBcc('archive@example.net');
        $email->setReplyTo('reply@example.net', 'Reply Person');
        $email->setSubject("Caf\u{e9} \u{2713} news");
        $email->setHTMLBody('<p>Hello, <b>html</b>.</p>', false);
        $email->setTextBody('Hello, plain.');

        $this->assertSame(2, $email->send());

        $this->assertSame(['site@example.com'], [$server->connections()[0]['mailFrom']]);
        $this->assertSame(['reader@example.net', 'archive@example.net'], $server->connections()[0]['rcptTo']);
        $message = ReceivedMessage::parse($server->messages()[0]);
        $this->assertStringStartsWith('multipart/alternative', (string) $message->header('Content-Type'));
        $this->assertSame("Sit\u{e9} \u{dc}n\u{ef} <site@example.com>", $message->header('From'));
        $this->assertSame("R\u{e9}ader <reader@example.net>", $message->header('To'));
        $this->assertSame('Reply Person <reply@example.net>', $message->header('Reply-To'));
        $this->assertSame("Caf\u{e9} \u{2713} news", $message->header('Subject'));
        $this->assertNotContains('bcc', $message->headerNames(), 'a Bcc recipient is in the envelope only');
        $this->assertStringNotContainsString('archive@example.net', $server->messages()[0]);
        $this->assertSame('Hello, plain.', $message->part('text/plain'));
        $this->assertSame('<p>Hello, <b>html</b>.</p>', $message->part('text/html'));
    }

    public function testAnInjectedHeaderNeverReachesTheWire(): void
    {
        $server = $this->startSmtp([]);
        $this->mailSettings(['smtp_host' => '127.0.0.1']);

        // A line break in a subject: one line, no new header.
        $email = $this->sampleEmail('reader@example.net', "Hi\r\nBcc: evil@example.net\r\nX-Injected: yes");
        $this->assertSame(1, $email->send());

        // A line break in a name or an address: refused, or delivered with no extra header.
        $named = new Email();
        $named->addTo('reader@example.net', "Bob\r\nBcc: evil@example.net");
        $named->setSubject('Named');
        $named->setTextBody('x');
        $named->send();
        $address = new Email();
        $address->addTo("reader@example.net\r\nBcc: evil@example.net");
        $address->setSubject('Address');
        $address->setTextBody('x');
        $this->assertSame(0, $address->send());
        $this->assertSame('message', $address->lastFailure());

        $allowed = ['message-id', 'from', 'to', 'subject', 'date', 'mime-version', 'content-type', 'content-transfer-encoding'];
        $this->assertGreaterThanOrEqual(1, count($server->messages()));
        foreach ($server->messages() as $raw) {
            $message = ReceivedMessage::parse($raw);
            $this->assertSame([], array_values(array_diff($message->headerNames(), $allowed)), 'exactly the expected headers: ' . $raw);
            $this->assertMatchesRegularExpression('/^[^\r\n]*reader@example\.net>?$/', (string) $message->header('To'), 'the name stayed inside the To header');
        }
        $this->assertSame('Hi Bcc: evil@example.net X-Injected: yes', ReceivedMessage::parse($server->messages()[0])->header('Subject'));
        foreach ($server->connections() as $connection) {
            $this->assertNotContains('evil@example.net', $connection['rcptTo']);
        }
    }

    // ------------------------------------------------------------------ every call site

    public function testEveryCallSiteDelivers(): void
    {
        $server = $this->startSmtp([]);
        $this->mailSettings(['smtp_host' => '127.0.0.1', 'oops_notify' => 'ops@example.net']);
        $expected = [];

        // The password reset form (Idno\Pages\Account\Password::postContent()).
        $t = clone Idno::site()->template();
        $t->setTemplateType('email');
        $email = new Email();
        $email->setSubject('Password reset');
        $email->addTo('someone@example.net');
        $email->setHTMLBody($t->__(['email' => 'someone@example.net', 'code' => 'code-1'])->draw('account/password'));
        $email->setTextBodyFromTemplate('account/password', ['email' => 'someone@example.net', 'code' => 'code-1']);
        $this->assertSame(1, $email->send());
        $expected['Password reset'] = ['someone@example.net', 'code=code-1'];

        // An invitation.
        $admin = $this->admin();
        $previous = $this->swapUser($admin);
        $invitation = new Invitation();
        try {
            $this->assertSame(1, $invitation->sendToEmail('invitee@example.org', 'inviter@example.com'));
        } finally {
            $this->swapUser($previous);
            $invitation->delete();
        }
        $expected["You've been invited to join Parity Site"] = ['invitee@example.org', 'code='];

        // The admin's test mail (Idno\Pages\Admin\EmailTest).
        $message = new Email();
        $message->addTo('admin@example.net');
        $message->setSubject('Test email from ' . Idno::site()->config()->title . '!');
        $message->setHTMLBodyFromTemplate('admin/emailtest');
        $message->setTextBodyFromTemplate('admin/emailtest');
        $this->assertSame(1, $message->send());
        $expected['Test email from Parity Site!'] = ['admin@example.net', ''];

        // The alert for a fatal error.
        \Idno\Core\Logging::oopsAlert('Something broke', 'Oh no');
        $expected[Idno::site()->config()->host . ': Oh no'] = ['ops@example.net', 'Something broke'];

        // The results of an import (both outcomes) and of an export (Idno\Pages\Admin\Import,
        // Idno\Pages\Admin\Export\Generate), built as those pages build them.
        foreach ([
            'admin/import' => 'Idno - Your data import is complete',
            'admin/import_failure' => 'Idno - Problem with your import',
            'admin/export' => 'Your data export is ready',
        ] as $template => $subject) {
            $mail = new Email();
            $mail->setHTMLBodyFromTemplate($template);
            $mail->setTextBodyFromTemplate($template);
            $mail->addTo('owner@example.net');
            $mail->setSubject($subject);
            $this->assertSame(1, $mail->send(), $template);
            $expected[$subject] = ['owner@example.net', ''];
        }

        $received = [];
        foreach ($server->messages() as $raw) {
            $parsed = ReceivedMessage::parse($raw);
            $received[(string) $parsed->header('Subject')] = $parsed;
        }
        $this->assertSame(array_keys($expected), array_keys($received), 'each call site delivered one message');
        foreach ($expected as $subject => [$to, $needle]) {
            $parsed = $received[$subject];
            $this->assertStringContainsString($to, (string) $parsed->header('To'), $subject);
            $this->assertSame('Parity Site <site@example.com>', $parsed->header('From'), $subject);
            $this->assertNotNull($parsed->part('text/html'), $subject . ' has an HTML part');
            $this->assertNotNull($parsed->part('text/plain'), $subject . ' has a text part');
            $this->assertStringContainsString($needle, (string) $parsed->part('text/plain'), $subject);
        }
        foreach ($server->connections() as $connection) {
            $this->assertSame('site@example.com', $connection['mailFrom'] ?? 'site@example.com');
        }
    }
}
