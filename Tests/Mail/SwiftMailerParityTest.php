<?php

declare(strict_types=1);

namespace Tests\Mail;

use Idno\Core\Email;
use Tests\Fixtures\TestMailTransportFactory;
use Tests\IdnoTestCase;

/**
 * Existing mail settings keep doing what they did. For each row of SettingsMatrix, the stored settings
 * are set as a site has them and one mail is sent through the PRODUCTION wiring (the container's mailer,
 * the settings read at the time of the send, Idno\Mail\SmtpTransportFactory) to a ScriptedSmtpServer;
 * what the server saw is compared with what SwiftMailer 6.3.0 did with the same row, captured once into
 * fixtures/swiftmailer-transcripts.php (see its header and fixtures/capture-swiftmailer.php).
 *
 * Compared: the port the settings mean, whether the connection began with a TLS handshake, whether
 * STARTTLS was sent, MAIL FROM, the RCPT TO list in order, and delivered or not. The AUTH mechanism is
 * recorded for both and may differ when the server accepted the one used.
 *
 * The ports a site names (25, 465, 587) cannot be bound in a test, so the test wiring connects to the
 * server's port instead (Tests\Fixtures\TestMailTransportFactory replaces only the transport's host and
 * port, after the production factory built it) and the port the settings MEAN is read from what the
 * factory built, before the replacement. A test that put the server's port in smtp_port would prove
 * nothing about the defaults.
 */
final class SwiftMailerParityTest extends IdnoTestCase
{
    use MailTestSupport;

    /**
     * Where the new transport may differ from SwiftMailer on a row, and why. Anything else that differs
     * fails the test. Each entry names the compared fields that differ.
     */
    private const ALLOWED_DIFFERENCES = [
        'tls-server-offers-no-starttls' => [
            'fields' => ['startTlsSent'],
            'reason' => 'SwiftMailer sent STARTTLS without being offered it and was refused; the new transport refuses to go on without sending it. Both fail and nothing is sent in the clear.',
        ],
    ];

    /**
     * Rows on which SwiftMailer built an address that could never connect (a scheme in the host with
     * smtp_secure): it never delivered, so the new transport may behave in any clean way (smtp_secure
     * decides). They are checked only for not throwing.
     */
    private const NEVER_DELIVERED_BY_SWIFTMAILER = [
        'ssl-scheme-host-and-tls/implicit-server', 'ssl-scheme-host-and-tls/starttls-server',
        'tls-scheme-host-and-tls/implicit-server', 'tls-scheme-host-and-tls/starttls-server',
        'ssl-scheme-host-and-ssl/implicit-server', 'ssl-scheme-host-and-ssl/starttls-server',
        'tls-scheme-host-and-ssl/implicit-server', 'tls-scheme-host-and-ssl/starttls-server',
    ];

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

    /**
     * @return iterable<string, array{string}>
     */
    public static function rows(): iterable
    {
        foreach (array_keys(SettingsMatrix::rows()) as $name) {
            yield $name => [$name];
        }
    }

    public function testTheFixtureHoldsEveryRowOfTheMatrix(): void
    {
        $fixture = require __DIR__ . '/fixtures/swiftmailer-transcripts.php';
        $this->assertSame(array_keys(SettingsMatrix::rows()), array_keys($fixture));
        foreach (array_keys(self::ALLOWED_DIFFERENCES) as $name) {
            $this->assertArrayHasKey($name, $fixture);
        }
        foreach (self::NEVER_DELIVERED_BY_SWIFTMAILER as $name) {
            $this->assertSame('failed', $fixture[$name]['outcome'], $name . ' is listed as a row SwiftMailer never delivered');
        }
    }

    /**
     * @dataProvider rows
     */
    public function testTheNewTransportDoesWhatSwiftMailerDid(string $name): void
    {
        $old = (require __DIR__ . '/fixtures/swiftmailer-transcripts.php')[$name];
        $row = SettingsMatrix::rows()[$name];
        $message = SettingsMatrix::message();

        $server = $this->startSmtp($row['server']);
        $this->mailSettings($row['settings']);
        $email = new Email();
        $email->addTo($message['to']);
        $email->addBcc($message['bcc']);
        $email->setSubject($message['subject']);
        $email->setHTMLBody($message['html'], false);
        $email->setTextBody($message['text']);
        $sent = $email->send();

        $built = TestMailTransportFactory::built();
        $this->assertCount(1, $built, 'one transport is built for one mail');
        $connections = $server->connections();
        $first = $connections[0] ?? null;
        $delivered = false;
        foreach ($connections as $connection) {
            $delivered = $delivered || $connection['accepted'];
        }
        $delivered = $delivered && $sent === 2;
        $new = [
            'port' => $built[0]['port'],
            'beganWithTls' => $first['beganWithTls'] ?? false,
            'startTlsSent' => $first['startTlsSent'] ?? false,
            'mailFrom' => $first['mailFrom'] ?? null,
            'rcptTo' => $first['rcptTo'] ?? [],
            'outcome' => $delivered ? 'sent' : 'failed',
        ];

        if (in_array($name, self::NEVER_DELIVERED_BY_SWIFTMAILER, true)) {
            $this->assertContains($sent, [0, 2]);

            return;
        }

        $expected = [
            'port' => $old['connectedTo']['port'],
            'beganWithTls' => $old['beganWithTls'],
            'startTlsSent' => $old['startTlsSent'],
            'mailFrom' => $old['mailFrom'],
            'rcptTo' => $old['rcptTo'],
            'outcome' => $old['outcome'],
        ];
        $allowed = self::ALLOWED_DIFFERENCES[$name]['fields'] ?? [];
        foreach ($expected as $field => $value) {
            if (in_array($field, $allowed, true)) {
                $this->assertNotSame($value, $new[$field], $name . ': ' . $field . ' is listed as differing, so it must differ (remove it from the list when it no longer does)');
            } else {
                $this->assertSame($value, $new[$field], $name . ': ' . $field . ' differs from SwiftMailer. Allowed differences: ' . json_encode(array_keys(self::ALLOWED_DIFFERENCES)));
            }
        }

        // The AUTH mechanism may differ (the server offers several and both clients prefer one); what
        // matters is that a login was attempted when SwiftMailer attempted one.
        if ($old['authMechanism'] !== null) {
            $this->assertNotNull($first['authMechanism'] ?? null, $name . ': SwiftMailer logged in (' . $old['authMechanism'] . '), the new transport did not');
        } else {
            $this->assertNull($first['authMechanism'] ?? null, $name . ': SwiftMailer did not log in, the new transport did');
        }
        if ($new['outcome'] === 'failed') {
            $this->assertSame([], $server->messages(), $name . ': a failed row delivered a message');
        }
    }

    public function testPortsTheSettingsMean(): void
    {
        // The default ports, read from what the production factory built (nothing connects to them).
        $cases = [
            ['host-only', 25], ['host-587', 587], ['host-465-ssl', 465], ['host-ssl-no-port', 25],
            ['host-tls-no-port', 25], ['port-zero', 25], ['port-numeric-string', 2525], ['no-host', 25],
            ['ssl-scheme-host', 25], ['tls-scheme-host/implicit-server', 25],
        ];
        foreach ($cases as [$name, $port]) {
            TestMailTransportFactory::forget();
            $this->startSmtp(SettingsMatrix::rows()[$name]['server']);
            $this->mailSettings(SettingsMatrix::rows()[$name]['settings']);
            $this->sampleEmail()->send();
            $this->assertSame($port, TestMailTransportFactory::built()[0]['port'], $name);
        }
    }
}
