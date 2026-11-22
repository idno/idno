<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Email;
use Idno\Core\Event;
use Idno\Core\Idno;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email as MimeEmail;
use Tests\Fixtures\RecordingMailTransport;
use Tests\IdnoTestCase;

/**
 * Idno\Core\Email on Symfony Mailer. Nothing here sends mail: the test container's transport is
 * Tests\Fixtures\RecordingMailTransport, which appends each message to a file, and a failing
 * transport is a Mailer handed to Email::useMailer().
 */
final class EmailAdapterTest extends IdnoTestCase
{
    private const SETTINGS = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_secure', 'from_email', 'title', 'oops_notify'];

    private static ?\Closure $listener = null;

    private string $errorLog = '';
    private string $previousErrorLog = '';
    private ?string $previousRequestUri = null;

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'idno-email-log');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
        $this->previousRequestUri = $_SERVER['REQUEST_URI'] ?? null;
        unset($_SERVER['REQUEST_URI']); // as in a console process

        $config = Idno::site()->config();
        foreach (self::SETTINGS as $key) {
            $this->saved[$key] = $config->config[$key] ?? null;
        }
        $config->config['smtp_host'] = 'smtp.example.test';
        $config->config['smtp_port'] = 2525;
        $config->config['smtp_username'] = '';
        $config->config['smtp_password'] = '';
        $config->config['smtp_secure'] = '';
        $config->config['from_email'] = 'site@example.com';
        $config->config['title'] = 'Adapter Site';
        $config->config['oops_notify'] = '';
        RecordingMailTransport::drain();
        self::$listener = null;
    }

    protected function tearDown(): void
    {
        Email::useMailer(null);
        self::$listener = null;
        $config = Idno::site()->config();
        foreach ($this->saved as $key => $value) {
            if ($value === null) {
                unset($config->config[$key]);
            } else {
                $config->config[$key] = $value;
            }
        }
        RecordingMailTransport::drain();
        unset($_SERVER['HTTP_USER_AGENT']);
        if ($this->previousRequestUri === null) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->previousRequestUri;
        }
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLog);
        parent::tearDown();
    }

    private function simple(string $to = 'to@example.net'): Email
    {
        $email = new Email();
        $email->addTo($to);
        $email->setSubject('A subject');
        $email->setHTMLBody('<p>Hello <b>there</b></p>', false);
        $email->setTextBody('Hello there');

        return $email;
    }

    private function log(): string
    {
        return (string) file_get_contents($this->errorLog);
    }

    public function testSendDeliversHtmlAndTextPartsToTheRecorder(): void
    {
        $this->assertSame(1, $this->simple()->send());

        $recorded = RecordingMailTransport::drain();
        $this->assertCount(1, $recorded);
        $mail = $recorded[0];
        $this->assertSame(['to@example.net'], array_column($mail['to'], 'address'));
        $this->assertSame('A subject', $mail['subject']);
        $this->assertSame('<p>Hello <b>there</b></p>', $mail['html']);
        $this->assertSame('Hello there', $mail['text']);
        $this->assertSame(['to@example.net'], $mail['envelope_recipients']);
    }

    public function testTheShellWrapsTheHtmlBody(): void
    {
        $email = new Email();
        $email->addTo('to@example.net');
        $email->setHTMLBody('<p>Inside</p>');
        $email->setTextBody('Inside');
        $this->assertSame(1, $email->send());

        $mail = RecordingMailTransport::drain()[0];
        $this->assertStringContainsString('<html lang="en">', $mail['html']);
        $this->assertStringContainsString('<p>Inside</p>', $mail['html']);
    }

    public function testSendReturnsTheNumberOfToAndBccRecipients(): void
    {
        $email = $this->simple();
        $email->addTo('second@example.net', 'Second');
        $email->addBcc('hidden@example.net');
        $email->setReplyTo('reply@example.net', 'Replies');
        $this->assertSame(3, $email->send());

        $mail = RecordingMailTransport::drain()[0];
        $this->assertSame(['to@example.net', 'second@example.net'], array_column($mail['to'], 'address'));
        $this->assertSame('Second', $mail['to'][1]['name']);
        $this->assertSame(['hidden@example.net'], array_column($mail['bcc'], 'address'));
        $this->assertSame([['address' => 'reply@example.net', 'name' => 'Replies']], $mail['reply_to']);
        $this->assertEqualsCanonicalizing(['to@example.net', 'second@example.net', 'hidden@example.net'], $mail['envelope_recipients']);
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $mail['headers']);
    }

    public function testFromFallsBackToTheSitesFromEmailAndTitle(): void
    {
        $email = $this->simple();
        $email->setFrom('someone@example.org', 'Someone');
        $email->send();
        $this->assertSame([['address' => 'site@example.com', 'name' => 'Adapter Site']], RecordingMailTransport::drain()[0]['from']);

        // With no from_email, what the caller set is kept.
        Idno::site()->config()->config['from_email'] = '';
        $email = $this->simple();
        $email->setFrom('someone@example.org', 'Someone');
        $this->assertSame(1, $email->send());
        $this->assertSame([['address' => 'someone@example.org', 'name' => 'Someone']], RecordingMailTransport::drain()[0]['from']);

        // With neither there is no sender: nothing is sent.
        $this->assertSame(0, $this->simple()->send());
        $this->assertSame([], RecordingMailTransport::drain());
    }

    public function testAFailingTransportReturnsZeroLogsTheClassAndNeverThrows(): void
    {
        $password = 'hunter2-secret-password';
        $config = Idno::site()->config();
        $config->config['smtp_host'] = 'secret-host.example.test';
        $config->config['smtp_username'] = 'secret-user';
        $config->config['smtp_password'] = $password;
        Email::useMailer(new Mailer(new class () extends AbstractTransport {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Connection to smtp://secret-user:hunter2-secret-password@secret-host.example.test:25 failed');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        }));

        $this->assertSame(0, $this->simple()->send());

        $log = $this->log();
        $this->assertStringContainsString('Error sending mail: ' . TransportException::class, $log);
        $this->assertStringNotContainsString($password, $log);
        $this->assertStringNotContainsString('secret-host', $log);
        $this->assertStringNotContainsString('secret-user', $log);
        $this->assertStringNotContainsString('Connection to', $log);
    }

    public function testASettingThatCannotBeADsnReturnsZeroAndLogsOnlyTheClass(): void
    {
        Idno::site()->config()->config['smtp_host'] = "evil\nhost";

        $this->assertSame(0, $this->simple()->send());

        $this->assertStringContainsString('Error sending mail: ' . \Idno\Mail\InvalidMailSetting::class, $this->log());
        $this->assertStringNotContainsString('evil', $this->log());
        $this->assertSame([], RecordingMailTransport::drain());
    }

    public function testANewlineInASubjectNameOrAddressSendsNoExtraHeaderOrRecipient(): void
    {
        // A subject.
        $email = $this->simple();
        $email->setSubject("a\r\nBcc: x@example.com");
        $email->send();
        $mail = RecordingMailTransport::drain()[0];
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $mail['headers']);
        $this->assertSame(['to@example.net'], $mail['envelope_recipients']);
        $this->assertSame([], $mail['bcc']);

        // A name, of a recipient and of the sender.
        $email = new Email();
        $email->addTo('to@example.net', "Eve\r\nBcc: x@example.com");
        $email->setReplyTo('reply@example.net', "Eve\r\nBcc: y@example.com");
        $email->setSubject('Names');
        $email->setTextBody('x');
        $email->send();
        $mail = RecordingMailTransport::drain()[0];
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $mail['headers']);
        $this->assertSame(['to@example.net'], $mail['envelope_recipients']);

        // An address: refused, nothing sent, no exception.
        foreach ([['to', "to@example.net\r\nBcc: x@example.com"], ['bcc', "b@example.net\r\nBcc: x@example.com"], ['reply', "r@example.net\nBcc: x@example.com"]] as [$kind, $address]) {
            $email = $this->simple();
            match ($kind) {
                'to' => $email->addTo($address),
                'bcc' => $email->addBcc($address),
                'reply' => $email->setReplyTo($address),
            };
            $this->assertSame(0, $email->send(), $kind);
        }
        $email = $this->simple();
        $email->setFrom("f@example.net\r\nBcc: x@example.com");
        Idno::site()->config()->config['from_email'] = '';
        $this->assertSame(0, $email->send());
        $this->assertSame([], RecordingMailTransport::drain());
    }

    public function testTheEmailSendEventCanReplaceTheMessage(): void
    {
        self::$listener = static function (Event $event): void {
            self::$listener = null;
            $message = $event->response();
            if ($message instanceof MimeEmail) {
                $event->setResponse((clone $message)->subject('Replaced by a listener'));
            }
        };
        Idno::site()->events()->addListener('email/send', static function (Event $event): void {
            if (self::$listener !== null) {
                (self::$listener)($event);
            }
        });

        $this->assertSame(1, $this->simple()->send());
        $this->assertSame('Replaced by a listener', RecordingMailTransport::drain()[0]['subject']);
    }

    public function testAnythingElseAListenerReturnsIsIgnored(): void
    {
        $seen = [];
        self::$listener = static function (Event $event) use (&$seen): void {
            self::$listener = null;
            $seen[] = $event->response() instanceof MimeEmail;
            $event->setResponse('not a message');
        };
        Idno::site()->events()->addListener('email/send', static function (Event $event): void {
            if (self::$listener !== null) {
                (self::$listener)($event);
            }
        });

        $this->assertSame(1, $this->simple()->send());
        $this->assertSame([true], $seen);
        $this->assertSame('A subject', RecordingMailTransport::drain()[0]['subject']);
    }

    public function testAChangedSmtpSettingAppliesToTheNextMailInTheSameProcess(): void
    {
        $this->simple()->send();
        $first = RecordingMailTransport::drain()[0]['transport'];
        $this->assertSame(['scheme' => 'smtp', 'host' => 'smtp.example.test', 'port' => 2525, 'user' => null, 'require_tls' => false], $first);

        $config = Idno::site()->config();
        $config->config['smtp_host'] = 'other.example.test';
        $config->config['smtp_port'] = 587;
        $config->config['smtp_username'] = 'me@example.com';
        $config->config['smtp_password'] = 'new-password';
        $config->config['smtp_secure'] = 'tls';
        $this->simple()->send();
        $second = RecordingMailTransport::drain()[0]['transport'];
        $this->assertSame(['scheme' => 'smtp', 'host' => 'other.example.test', 'port' => 587, 'user' => 'me@example.com', 'require_tls' => true], $second);

        $config->config['smtp_host'] = '';
        $config->config['smtp_port'] = '';
        $config->config['smtp_username'] = '';
        $config->config['smtp_secure'] = 'ssl';
        $this->simple()->send();
        $this->assertSame(['scheme' => 'smtps', 'host' => 'localhost', 'port' => 25, 'user' => null, 'require_tls' => false], RecordingMailTransport::drain()[0]['transport']);
    }

    public function testThePasswordNeverReachesTheRecorder(): void
    {
        $config = Idno::site()->config();
        $config->config['smtp_username'] = 'me';
        $config->config['smtp_password'] = 'recorded-password-check';
        $this->simple()->send();
        $this->assertStringNotContainsString('recorded-password-check', (string) json_encode(RecordingMailTransport::drain()));
    }

    public function testTheMailerServiceIsPublicAndNotShared(): void
    {
        $container = Idno::container();
        $first = $container->get(MailerInterface::class);
        $second = $container->get(MailerInterface::class);
        $this->assertInstanceOf(MailerInterface::class, $first);
        $this->assertNotSame($first, $second);
    }

    public function testOopsAlertSendsWhenCalledFromAnErrorHandler(): void
    {
        Idno::site()->config()->config['oops_notify'] = ['ops@example.net', 'not an address', 'second@example.net'];

        \Idno\Core\Logging::oopsAlert('It broke', 'Oh no');

        $recorded = RecordingMailTransport::drain();
        $this->assertCount(2, $recorded);
        $this->assertSame(['ops@example.net'], array_column($recorded[0]['to'], 'address'));
        $this->assertSame(['second@example.net'], array_column($recorded[1]['to'], 'address'));
        $this->assertSame(Idno::site()->config()->host . ': Oh no', $recorded[0]['subject']);
        $this->assertStringContainsString('It broke', $recorded[0]['text']);
    }

    public function testOopsAlertNeverThrowsWhenTheMailCannotBeSent(): void
    {
        Idno::site()->config()->config['oops_notify'] = 'ops@example.net';
        Email::useMailer(new class () implements MailerInterface {
            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                throw new \LogicException('the container is not there');
            }
        });

        \Idno\Core\Logging::oopsAlert('It broke', 'Oh no');

        $this->assertStringContainsString('Error sending mail: LogicException', $this->log());
        $this->assertStringNotContainsString('the container is not there', $this->log());
    }

    public function testASchemeInTheHostIsReadAsThatSecurity(): void
    {
        Idno::site()->config()->config['smtp_host'] = 'ssl://smtp.example.test';
        Idno::site()->config()->config['smtp_port'] = '';
        $this->assertSame(1, $this->simple()->send());
        $this->assertSame(['scheme' => 'smtps', 'host' => 'smtp.example.test', 'port' => 25, 'user' => null, 'require_tls' => false], RecordingMailTransport::drain()[0]['transport']);

        Idno::site()->config()->config['smtp_host'] = 'tls://smtp.example.test';
        $this->assertSame(1, $this->simple()->send());
        // PHP's tls:// stream scheme is TLS from the first byte, so SwiftMailer connected that way (see SwiftMailerParityTest).
        $this->assertSame(['scheme' => 'smtps', 'host' => 'smtp.example.test', 'port' => 25, 'user' => null, 'require_tls' => false], RecordingMailTransport::drain()[0]['transport']);
    }

    public function testLastFailureSaysWhyInAFixedVocabulary(): void
    {
        $email = $this->simple();
        $this->assertNull($email->lastFailure());
        $this->assertSame(1, $email->send());
        $this->assertNull($email->lastFailure());

        $config = Idno::site()->config();
        $config->config['smtp_host'] = 'http://smtp.example.test';
        $this->assertSame(0, $email->send());
        $this->assertSame('settings: smtp_host', $email->lastFailure());

        $config->config['smtp_host'] = 'smtp.example.test';
        $config->config['smtp_port'] = 'abc';
        $this->assertSame(0, $email->send());
        $this->assertSame('settings: smtp_port', $email->lastFailure());

        $config->config['smtp_port'] = 25;
        $this->assertSame(1, $email->send(), 'the next send starts clean');
        $this->assertNull($email->lastFailure());

        $bad = $this->simple("bad\r\n@example.net");
        $this->assertSame(0, $bad->send());
        $this->assertSame('message', $bad->lastFailure());

        Email::useMailer(new class () implements MailerInterface {
            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                throw new TransportException('secret-host.example.test refused');
            }
        });
        $failing = $this->simple();
        $this->assertSame(0, $failing->send());
        $this->assertSame('transport', $failing->lastFailure());
        $this->assertStringNotContainsString('secret-host', (string) $failing->lastFailure());
    }

    public function testOopsAlertSendsOnTheConsolePathWithNoRequestUri(): void
    {
        $this->assertArrayNotHasKey('REQUEST_URI', $_SERVER);
        Idno::site()->config()->config['oops_notify'] = 'ops@example.net';

        \Idno\Core\Logging::oopsAlert('It broke', 'Oh no');

        $recorded = RecordingMailTransport::drain();
        $this->assertCount(1, $recorded);
        $this->assertStringContainsString('<html lang="en">', $recorded[0]['html']);
        $this->assertSame('', $this->log(), 'the shell warned about nothing');
    }

    public function testOopsAlertReturnsWithoutThrowingWhenATemplateThrows(): void
    {
        Idno::site()->config()->config['oops_notify'] = 'ops@example.net';
        // The user agent is drawn by the template: a value that cannot be printed makes it throw (the
        // test site turns every PHP error into an exception).
        $_SERVER['HTTP_USER_AGENT'] = ['not', 'a', 'string'];

        \Idno\Core\Logging::oopsAlert('It broke', 'Oh no');

        $this->assertSame([], RecordingMailTransport::drain());
        $this->assertStringContainsString('Could not send the alert: ', $this->log());
    }
}
