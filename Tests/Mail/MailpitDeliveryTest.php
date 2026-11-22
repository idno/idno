<?php

declare(strict_types=1);

namespace Tests\Mail;

use Idno\Core\Email;
use Idno\Core\Idno;
use Idno\Entities\Invitation;
use Tests\Fixtures\TestMailTransportFactory;
use Tests\IdnoTestCase;

/**
 * Mail delivered to a REAL mail server, Mailpit (https://mailpit.axllent.org), over its three kinds of
 * connection: plain, STARTTLS on 587 and implicit TLS on 465, each with a login, and read back through
 * Mailpit's API: subject, both bodies, From, To and Reply-To. The messages go through the production
 * mailer and transport; only the address is the test wiring's (the identity here: the settings name the
 * Mailpit server's own host and port).
 *
 * It runs only in `composer test:mail` (Tests/rig/mail.sh, in Docker, where the three Mailpit servers are
 * on a network of their own), and in the CI job `mail`. It skips without IDNO_TEST_MAILPIT_SMTP and
 * IDNO_TEST_MAILPIT_API, and the runner (Tests/rig/mail-check.php) fails a run in which it skipped.
 *
 * IDNO_TEST_MAILPIT_SMTP and IDNO_TEST_MAILPIT_API are `plain=<value>,starttls=<value>,tls=<value>`: the
 * SMTP host:port and the API's base URL of each server. IDNO_TEST_MAILPIT_CA is the PEM file of the
 * authority that signed the servers' certificates.
 */
final class MailpitDeliveryTest extends IdnoTestCase
{
    use MailTestSupport;

    /** @var array<string, string> */
    private array $smtpServers = [];

    /** @var array<string, string> */
    private array $apis = [];

    private string $ca = '';

    protected function setUp(): void
    {
        parent::setUp();
        $smtp = (string) getenv('IDNO_TEST_MAILPIT_SMTP');
        $api = (string) getenv('IDNO_TEST_MAILPIT_API');
        if ($smtp === '' || $api === '') {
            $this->markTestSkipped('IDNO_TEST_MAILPIT_SMTP and IDNO_TEST_MAILPIT_API name the Mailpit servers: composer test:mail runs this test.');
        }
        $this->smtpServers = self::map($smtp);
        $this->apis = self::map($api);
        $this->ca = (string) getenv('IDNO_TEST_MAILPIT_CA');
        $this->mailSetUp();
    }

    protected function tearDown(): void
    {
        if ($this->smtpServers !== []) {
            $this->mailTearDown();
        }
        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private static function map(string $value): array
    {
        $map = [];
        foreach (explode(',', $value) as $pair) {
            [$name, $address] = explode('=', $pair, 2) + [1 => ''];
            $map[trim($name)] = trim($address);
        }

        return $map;
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function connections(): iterable
    {
        yield 'plain, with a login' => ['plain', 1025, ''];
        yield 'STARTTLS on 587, with a login' => ['starttls', 587, 'tls'];
        yield 'implicit TLS on 465, with a login' => ['tls', 465, 'ssl'];
    }

    /**
     * @dataProvider connections
     */
    public function testTheFourMailsAreDeliveredAndReadBack(string $kind, int $port, string $secure): void
    {
        $address = $this->smtpServers[$kind];
        [$host] = explode(':', $address);
        // The settings name the server's own host and port: the test wiring's address is the same one.
        $this->mailEnvironment(TestMailTransportFactory::DELIVER, $address);
        if ($this->ca !== '') {
            $this->mailEnvironment(TestMailTransportFactory::CAFILE, $this->ca);
        }
        $this->mailSettings(['smtp_host' => $host, 'smtp_port' => $port, 'smtp_secure' => $secure, 'smtp_username' => 'mailer', 'smtp_password' => 'p@ss:w/rd%#? é', 'oops_notify' => 'ops@example.net', 'title' => 'Mailpit Site']);
        $this->deleteAll($kind);

        // The password reset, as Idno\Pages\Account\Password builds it.
        $t = clone Idno::site()->template();
        $t->setTemplateType('email');
        $reset = new Email();
        $reset->setSubject('Password reset');
        $reset->addTo('someone@example.net');
        $reset->setHTMLBody($t->__(['email' => 'someone@example.net', 'code' => 'code-1'])->draw('account/password'));
        $reset->setTextBodyFromTemplate('account/password', ['email' => 'someone@example.net', 'code' => 'code-1']);
        $this->assertSame(1, $reset->send(), 'password reset ' . $this->mailErrorLogContents());

        // An invitation (with a Reply-To).
        $previous = $this->swapUser($this->admin());
        $invitation = new Invitation();
        try {
            $this->assertSame(1, $invitation->sendToEmail('invitee@example.org', 'inviter@example.com'), 'invitation');
        } finally {
            $this->swapUser($previous);
            $invitation->delete();
        }

        // The admin's test mail.
        $test = new Email();
        $test->addTo('admin@example.net');
        $test->setSubject('Test email from ' . Idno::site()->config()->title . '!');
        $test->setHTMLBodyFromTemplate('admin/emailtest');
        $test->setTextBodyFromTemplate('admin/emailtest');
        $this->assertSame(1, $test->send(), 'test mail');

        // The alert for a fatal error.
        \Idno\Core\Logging::oopsAlert('Something broke', 'Oh no');

        $this->assertSame($port, TestMailTransportFactory::built()[0]['port'], 'the port the settings mean');
        $this->assertSame($secure === 'ssl', TestMailTransportFactory::built()[0]['implicitTls']);
        $this->assertSame($secure === 'tls', TestMailTransportFactory::built()[0]['requireTls']);

        $messages = $this->messages($kind);
        $this->assertCount(4, $messages, 'four mails arrived');
        $bySubject = [];
        foreach ($messages as $message) {
            $bySubject[$message['Subject']] = $message;
        }
        $this->assertEqualsCanonicalizing([
            'Password reset',
            "You've been invited to join Mailpit Site",
            'Test email from Mailpit Site!',
            Idno::site()->config()->host . ': Oh no',
        ], array_keys($bySubject));

        $reset = $bySubject['Password reset'];
        $this->assertSame('site@example.com', $reset['From']['Address']);
        $this->assertSame('Mailpit Site', $reset['From']['Name']);
        $this->assertSame(['someone@example.net'], array_column($reset['To'], 'Address'));
        $this->assertStringContainsString('account/password/reset/?email=someone%40example.net&code=code-1', $reset['Text']);
        $this->assertStringContainsString('<html', $reset['HTML']);

        $invited = $bySubject["You've been invited to join Mailpit Site"];
        $this->assertSame(['invitee@example.org'], array_column($invited['To'], 'Address'));
        $this->assertSame(['inviter@example.com'], array_column($invited['ReplyTo'], 'Address'));
        $this->assertNotSame('', $invited['Text']);
        $this->assertNotSame('', $invited['HTML']);

        $test = $bySubject['Test email from Mailpit Site!'];
        $this->assertSame(['admin@example.net'], array_column($test['To'], 'Address'));
        $this->assertNotSame('', $test['Text']);
        $this->assertNotSame('', $test['HTML']);

        $alert = $bySubject[Idno::site()->config()->host . ': Oh no'];
        $this->assertSame(['ops@example.net'], array_column($alert['To'], 'Address'));
        $this->assertStringContainsString('Something broke', $alert['Text']);
        $this->assertStringContainsString('Something broke', $alert['HTML']);
    }

    private function deleteAll(string $kind): void
    {
        $this->api($kind, 'DELETE', '/api/v1/messages');
    }

    /**
     * @return list<array<string, mixed>> the messages, each in full
     */
    private function messages(string $kind): array
    {
        $summaries = $this->api($kind, 'GET', '/api/v1/messages?limit=50');
        $messages = [];
        foreach ($summaries['messages'] ?? [] as $summary) {
            $messages[] = $this->api($kind, 'GET', '/api/v1/message/' . $summary['ID']);
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private function api(string $kind, string $method, string $path): array
    {
        $context = stream_context_create(['http' => ['method' => $method, 'timeout' => 20, 'ignore_errors' => true]]);
        $body = @file_get_contents(rtrim($this->apis[$kind], '/') . $path, false, $context);
        $this->assertNotFalse($body, 'Mailpit\'s API answered ' . $path);

        return $body === '' || $body === 'ok' ? [] : (array) json_decode($body, true);
    }
}
