<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use Idno\Mail\MailTransportDsn;
use Idno\Mail\SmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * The DSN each combination of mail settings gives, handed to the factory the site uses, and what the
 * transport it builds would do: its host, port, whether it connects with TLS at once (implicit TLS),
 * whether it starts STARTTLS when the server offers it, and whether it insists on it. Nothing connects:
 * a transport opens its socket only when it sends.
 */
final class SmtpTransportFactoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string, int, bool, bool, bool}>
     */
    public static function mappings(): iterable
    {
        // settings, host, port, implicit TLS, STARTTLS when offered, STARTTLS required (with implicit TLS the
        // connection is encrypted already, so the STARTTLS flag has nothing to do)
        yield 'no host' => [[], 'localhost', 25, false, false, false];
        yield 'host only' => [['smtp_host' => 'mail.example.com'], 'mail.example.com', 25, false, false, false];
        yield 'host and 25' => [['smtp_host' => 'mail.example.com', 'smtp_port' => 25], 'mail.example.com', 25, false, false, false];
        yield 'host and 587' => [['smtp_host' => 'mail.example.com', 'smtp_port' => 587], 'mail.example.com', 587, false, false, false];
        yield 'host, 465 and ssl' => [['smtp_host' => 'mail.example.com', 'smtp_port' => 465, 'smtp_secure' => 'ssl'], 'mail.example.com', 465, true, true, false];
        yield 'host and ssl, no port is port 25' => [['smtp_host' => 'mail.example.com', 'smtp_secure' => 'ssl'], 'mail.example.com', 25, true, true, false];
        yield 'host and tls, no port' => [['smtp_host' => 'mail.example.com', 'smtp_secure' => 'tls'], 'mail.example.com', 25, false, true, true];
        yield 'host, 587 and tls' => [['smtp_host' => 'mail.example.com', 'smtp_port' => 587, 'smtp_secure' => 'tls'], 'mail.example.com', 587, false, true, true];
        yield 'tls on 465 is still STARTTLS on a plain connection' => [['smtp_host' => 'mail.example.com', 'smtp_port' => 465, 'smtp_secure' => 'tls'], 'mail.example.com', 465, false, true, true];
        yield 'port 0' => [['smtp_host' => 'mail.example.com', 'smtp_port' => 0], 'mail.example.com', 25, false, false, false];
        yield 'port as a numeric string' => [['smtp_host' => 'mail.example.com', 'smtp_port' => '2525'], 'mail.example.com', 2525, false, false, false];
        yield 'no host with a port and ssl' => [['smtp_port' => 2465, 'smtp_secure' => 'ssl'], 'localhost', 2465, true, true, false];
        yield 'ssl:// in the host' => [['smtp_host' => 'ssl://mail.example.com'], 'mail.example.com', 25, true, true, false];
        yield 'tls:// in the host is implicit tls' => [['smtp_host' => 'tls://mail.example.com'], 'mail.example.com', 25, true, true, false];
        yield 'an IPv6 host' => [['smtp_host' => '2001:db8::1'], '[2001:db8::1]', 25, false, false, false];
    }

    /**
     * @param array<string, mixed> $settings
     * @dataProvider mappings
     */
    public function testTheTransportTheSettingsGive(array $settings, string $host, int $port, bool $implicitTls, bool $autoTls, bool $requireTls): void
    {
        $transport = (new SmtpTransportFactory())->create(Dsn::fromString(MailTransportDsn::fromSettings($settings)));

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertSame($host, $stream->getHost());
        $this->assertSame($port, $stream->getPort());
        $this->assertSame($implicitTls, $stream->isTLS());
        $this->assertSame($autoTls, $transport->isAutoTls());
        $this->assertSame($requireTls, $transport->isTlsRequired());
    }

    public function testCredentialsReachTheTransportExactly(): void
    {
        $password = 'p@ss:w/rd%#?';
        $transport = (new SmtpTransportFactory())->create(Dsn::fromString(MailTransportDsn::fromSettings([
            'smtp_host' => 'mail.example.com', 'smtp_username' => 'me@example.com', 'smtp_password' => $password,
        ])));

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('me@example.com', $transport->getUsername());
        $this->assertSame($password, $transport->getPassword());
    }

    public function testASettingsPasswordWithoutAUsernameIsNotUsed(): void
    {
        $transport = (new SmtpTransportFactory())->create(Dsn::fromString(MailTransportDsn::fromSettings(['smtp_host' => 'h.example', 'smtp_password' => 'x'])));
        $this->assertSame('', $transport->getUsername());
        $this->assertSame('', $transport->getPassword());
    }

    public function testOnlySmtpSchemesAreSupported(): void
    {
        $this->assertTrue((new SmtpTransportFactory())->supports(Dsn::fromString('smtp://h:25')));
        $this->assertTrue((new SmtpTransportFactory())->supports(Dsn::fromString('smtps://h:465')));
        $this->assertFalse((new SmtpTransportFactory())->supports(Dsn::fromString('sendmail://default')));
    }

    public function testTheConnectionAndEveryReadAreBoundedAtThirtySeconds(): void
    {
        $transport = (new SmtpTransportFactory())->create(Dsn::fromString('smtp://h:25'));
        $this->assertSame(30.0, $transport->getStream()->getTimeout());
        $this->assertSame(30.0, SmtpTransportFactory::TIMEOUT_SECONDS);

        $short = (new SmtpTransportFactory(timeout: 2.5))->create(Dsn::fromString('smtp://h:25'));
        $this->assertSame(2.5, $short->getStream()->getTimeout());
    }

    public function testAnEmptyPasswordIsAuthenticatedWithAsItWasUnderSwiftMailer(): void
    {
        $transport = (new SmtpTransportFactory())->create(Dsn::fromString('smtp://me@h:25'));
        $this->assertSame('me', $transport->getUsername());
        $this->assertSame('', $transport->getPassword());

        $reflection = new \ReflectionProperty($transport, 'authenticators');
        $keywords = array_map(static fn ($a) => $a->getAuthKeyword(), $reflection->getValue($transport));
        $this->assertSame(['CRAM-MD5', 'LOGIN', 'PLAIN', 'XOAUTH2'], $keywords);
        $this->assertInstanceOf(\Idno\Mail\CramMd5Authenticator::class, $reflection->getValue($transport)[0]);
    }

    public function testNoVerificationOptionIsReadFromTheDsn(): void
    {
        $transport = (new SmtpTransportFactory())->create(Dsn::fromString('smtp://h:25?verify_peer=0&cafile=/tmp/x&peer_fingerprint=ab'));
        $this->assertSame([], $transport->getStream()->getStreamOptions());
    }
}
