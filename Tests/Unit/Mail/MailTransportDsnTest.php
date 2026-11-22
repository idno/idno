<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use Idno\Mail\MailTransportDsn;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Dsn;

final class MailTransportDsnTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function plainSettings(): iterable
    {
        // The port is always in the DSN: smtp_port, or 25 when none is set (with ssl too, as SwiftMailer did). Nothing is encrypted
        // unless smtp_secure says so (auto_tls=false: no STARTTLS even when the server offers it).
        yield 'no host is the local mail server' => [['smtp_host' => ''], 'smtp://localhost:25?auto_tls=false'];
        yield 'nothing set at all' => [[], 'smtp://localhost:25?auto_tls=false'];
        yield 'no host still takes the port' => [['smtp_host' => '', 'smtp_port' => 587], 'smtp://localhost:587?auto_tls=false'];
        yield 'no host still takes the security' => [['smtp_host' => '', 'smtp_secure' => 'ssl'], 'smtps://localhost:25'];
        yield 'a host alone is port 25' => [['smtp_host' => 'mail.example.com'], 'smtp://mail.example.com:25?auto_tls=false'];
        yield 'a host and a port' => [['smtp_host' => 'mail.example.com', 'smtp_port' => 587], 'smtp://mail.example.com:587?auto_tls=false'];
        yield 'a port as text, as the admin form saves it' => [['smtp_host' => 'mail.example.com', 'smtp_port' => '2525'], 'smtp://mail.example.com:2525?auto_tls=false'];
        yield 'an empty port' => [['smtp_host' => 'mail.example.com', 'smtp_port' => ''], 'smtp://mail.example.com:25?auto_tls=false'];
        yield 'port 0' => [['smtp_host' => 'mail.example.com', 'smtp_port' => 0], 'smtp://mail.example.com:25?auto_tls=false'];
        yield 'port "0"' => [['smtp_host' => 'mail.example.com', 'smtp_port' => '0'], 'smtp://mail.example.com:25?auto_tls=false'];
        yield 'ssl is implicit tls' => [['smtp_host' => 'mail.example.com', 'smtp_port' => 465, 'smtp_secure' => 'ssl'], 'smtps://mail.example.com:465'];
        yield 'ssl without a port is port 25, as it always was' => [['smtp_host' => 'mail.example.com', 'smtp_secure' => 'ssl'], 'smtps://mail.example.com:25'];
        yield 'ssl on another port' => [['smtp_host' => 'mail.example.com', 'smtp_port' => 2465, 'smtp_secure' => 'ssl'], 'smtps://mail.example.com:2465'];
        yield 'tls requires starttls, port 25 by default' => [['smtp_host' => 'mail.example.com', 'smtp_secure' => 'tls'], 'smtp://mail.example.com:25?require_tls=true'];
        yield 'tls with a port' => [['smtp_host' => 'mail.example.com', 'smtp_port' => 587, 'smtp_secure' => 'tls'], 'smtp://mail.example.com:587?require_tls=true'];
        yield 'any other secure value is not encrypted' => [['smtp_host' => 'mail.example.com', 'smtp_secure' => 'starttls'], 'smtp://mail.example.com:25?auto_tls=false'];
        yield 'secure is compared exactly' => [['smtp_host' => 'mail.example.com', 'smtp_secure' => 'SSL'], 'smtp://mail.example.com:25?auto_tls=false'];
        yield 'an IPv4 address' => [['smtp_host' => '192.0.2.10', 'smtp_port' => 25], 'smtp://192.0.2.10:25?auto_tls=false'];
        yield 'an IPv6 address is bracketed' => [['smtp_host' => '2001:db8::1', 'smtp_port' => 25], 'smtp://[2001:db8::1]:25?auto_tls=false'];
        yield 'a password without a username is not used' => [['smtp_host' => 'mail.example.com', 'smtp_password' => 'x'], 'smtp://mail.example.com:25?auto_tls=false'];
        yield 'ssl:// in the host is the host with implicit tls' => [['smtp_host' => 'ssl://mail.example.com'], 'smtps://mail.example.com:25'];
        yield 'ssl:// in the host with a port' => [['smtp_host' => 'SSL://mail.example.com', 'smtp_port' => 2465], 'smtps://mail.example.com:2465'];
        yield 'tls:// in the host is implicit tls too (the tls:// stream scheme of PHP)' => [['smtp_host' => 'tls://mail.example.com', 'smtp_port' => 465], 'smtps://mail.example.com:465'];
        yield 'tls:// in the host without a port' => [['smtp_host' => 'tls://mail.example.com'], 'smtps://mail.example.com:25'];
        yield 'credentials of an empty host are not used' => [['smtp_host' => '', 'smtp_username' => 'me', 'smtp_password' => 'x'], 'smtp://localhost:25?auto_tls=false'];
        yield 'smtp_secure wins over a scheme in the host (SwiftMailer never delivered with both)' => [['smtp_host' => 'ssl://mail.example.com', 'smtp_secure' => 'tls'], 'smtp://mail.example.com:25?require_tls=true'];
    }

    /**
     * @param array<string, mixed> $settings
     * @dataProvider plainSettings
     */
    public function testTheDsnOfTheSettings(array $settings, string $expected): void
    {
        $this->assertSame($expected, MailTransportDsn::fromSettings($settings));
    }

    public function testCredentialsArePercentEncodedAndReadBackExactly(): void
    {
        $password = 'p@ss:w/rd%#?';
        $dsn = MailTransportDsn::fromSettings([
            'smtp_host' => 'mail.example.com',
            'smtp_port' => 587,
            'smtp_username' => 'me@example.com',
            'smtp_password' => $password,
            'smtp_secure' => 'tls',
        ]);

        $this->assertStringNotContainsString($password, $dsn);
        $parsed = Dsn::fromString($dsn);
        $this->assertSame('smtp', $parsed->getScheme());
        $this->assertSame('mail.example.com', $parsed->getHost());
        $this->assertSame(587, $parsed->getPort());
        $this->assertSame('me@example.com', $parsed->getUser());
        $this->assertSame($password, $parsed->getPassword());
        $this->assertTrue($parsed->getBooleanOption('require_tls'));
    }

    public function testAUsernameWithoutAPasswordIsSentAlone(): void
    {
        $parsed = Dsn::fromString(MailTransportDsn::fromSettings(['smtp_host' => 'mail.example.com', 'smtp_username' => 'me']));
        $this->assertSame('me', $parsed->getUser());
        $this->assertNull($parsed->getPassword());
    }

    public function testEveryByteOfACredentialSurvives(): void
    {
        $user = "us er/\u{e9}\u{1f600}:x";
        $password = " !\"#\$%&'()*+,-./:;<=>?@[\\]^_`{|}~\u{e9}";
        $parsed = Dsn::fromString(MailTransportDsn::fromSettings(['smtp_host' => 'h.example', 'smtp_username' => $user, 'smtp_password' => $password]));
        $this->assertSame($user, $parsed->getUser());
        $this->assertSame($password, $parsed->getPassword());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedSettings(): iterable
    {
        yield 'a newline in the host' => [['smtp_host' => "evil\nhost"], 'smtp_host'];
        yield 'a slash in the host' => [['smtp_host' => 'evil.example/path'], 'smtp_host'];
        yield 'another scheme in the host' => [['smtp_host' => 'http://mail.example.com'], 'smtp_host'];
        yield 'a scheme with nothing after it' => [['smtp_host' => 'ssl://'], 'smtp_host'];
        yield 'an at sign in the host' => [['smtp_host' => 'user@mail.example.com'], 'smtp_host'];
        yield 'a space in the host' => [['smtp_host' => 'mail example.com'], 'smtp_host'];
        yield 'a query in the host' => [['smtp_host' => 'mail.example.com?x=1'], 'smtp_host'];
        yield 'a host that is not text' => [['smtp_host' => ['a']], 'smtp_host'];
        yield 'a word as the port' => [['smtp_host' => 'mail.example.com', 'smtp_port' => 'abc'], 'smtp_port'];
        yield 'a port out of range' => [['smtp_host' => 'mail.example.com', 'smtp_port' => 70000], 'smtp_port'];
        yield 'a negative port' => [['smtp_host' => 'mail.example.com', 'smtp_port' => -1], 'smtp_port'];
        yield 'a port with a newline' => [['smtp_host' => 'mail.example.com', 'smtp_port' => "25\nx"], 'smtp_port'];
        yield 'a port with a path' => [['smtp_host' => 'mail.example.com', 'smtp_port' => '25/x'], 'smtp_port'];
    }

    /**
     * @param array<string, mixed> $settings
     * @dataProvider refusedSettings
     */
    public function testAHostOrPortThatCannotBeADsnIsRefusedWithoutItsValue(array $settings, string $setting): void
    {
        try {
            MailTransportDsn::fromSettings($settings);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertInstanceOf(\Idno\Mail\InvalidMailSetting::class, $e);
            $this->assertSame($setting, $e->setting());
            $this->assertStringContainsString($setting, $e->getMessage());
            $value = $settings[$setting];
            if (is_string($value)) {
                $this->assertStringNotContainsString($value, $e->getMessage());
            }
        }
    }
}
