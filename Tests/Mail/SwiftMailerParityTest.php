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
     * smtp_secure): it never delivered, so there is nothing to compare; the new rule (smtp_secure decides) is
     * pinned by testSmtpSecureDecidesWhenTheHostHasASchemeToo.
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
     * Sends one mail with the settings to a server of the kind given, through the production wiring, and
     * says what the server saw and what the production factory built (before the test wiring changed the
     * address): the host and port the settings MEAN.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $server
     *
     * @return array{host: string, port: int, beganWithTls: bool, startTlsSent: bool, authMechanism: ?string, authAccepted: ?bool, mailFrom: ?string, rcptTo: list<string>, outcome: string, messages: list<string>}
     */
    private function observe(array $settings, array $server): array
    {
        $message = SettingsMatrix::message();
        $smtp = $this->startSmtp($server);
        TestMailTransportFactory::forget();
        $this->mailSettings($settings);
        $email = new Email();
        $email->addTo($message['to']);
        $email->addBcc($message['bcc']);
        $email->setSubject($message['subject']);
        $email->setHTMLBody($message['html'], false);
        $email->setTextBody($message['text']);
        $sent = $email->send();

        $built = TestMailTransportFactory::built();
        $this->assertCount(1, $built, 'one transport is built for one mail');
        $connections = $smtp->connections();
        $first = $connections[0] ?? null;
        $delivered = false;
        foreach ($connections as $connection) {
            $delivered = $delivered || $connection['accepted'];
        }

        return [
            'host' => $built[0]['host'],
            'port' => $built[0]['port'],
            'beganWithTls' => $first['beganWithTls'] ?? false,
            'startTlsSent' => $first['startTlsSent'] ?? false,
            'authMechanism' => $first['authMechanism'] ?? null,
            'authAccepted' => $first['authAccepted'] ?? null,
            'mailFrom' => $first['mailFrom'] ?? null,
            'rcptTo' => $first['rcptTo'] ?? [],
            'outcome' => $delivered && $sent === 2 ? 'sent' : 'failed',
            'messages' => $smtp->messages(),
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rowsSwiftMailerCouldDeliver(): iterable
    {
        foreach (array_keys(SettingsMatrix::rows()) as $name) {
            if (!in_array($name, self::NEVER_DELIVERED_BY_SWIFTMAILER, true)) {
                yield $name => [$name];
            }
        }
    }

    /**
     * @dataProvider rowsSwiftMailerCouldDeliver
     */
    public function testTheNewTransportDoesWhatSwiftMailerDid(string $name): void
    {
        $old = (require __DIR__ . '/fixtures/swiftmailer-transcripts.php')[$name];
        $row = SettingsMatrix::rows()[$name];
        $new = $this->observe($row['settings'], $row['server']);

        $expected = [
            // The host the settings mean: SwiftMailer's own, less the scheme it was written with.
            'host' => (string) preg_replace('~\A(ssl|tls)://~', '', $old['connectedTo']['host']),
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

        // The AUTH mechanism: both are recorded. A login was attempted exactly when SwiftMailer attempted
        // one; the mechanism itself may differ (the server offers several and each client prefers one),
        // and then both are named and the server must have accepted the new one.
        $mechanisms = $name . ': SwiftMailer used ' . ($old['authMechanism'] ?? 'no AUTH') . ', the new transport used ' . ($new['authMechanism'] ?? 'no AUTH');
        $this->assertSame($old['authMechanism'] === null, $new['authMechanism'] === null, $mechanisms);
        if ($old['authMechanism'] !== $new['authMechanism'] && $old['outcome'] === 'sent' && $new['outcome'] === 'sent') {
            $this->assertTrue($new['authAccepted'], $mechanisms . ' and the server did not accept it');
        }
        if ($new['outcome'] === 'failed') {
            $this->assertSame([], $new['messages'], $name . ': a failed row delivered a message');
        }
    }

    /**
     * Rows with a scheme in the host AND smtp_secure `ssl` or `tls`: SwiftMailer built an address that could
     * never connect, so there is nothing to be on a par with. The rule of the new code is that smtp_secure
     * decides, so such a row must look on the wire exactly like the row without the scheme.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function schemeRows(): iterable
    {
        foreach (self::NEVER_DELIVERED_BY_SWIFTMAILER as $name) {
            yield $name => [$name, str_contains($name, '-and-tls') ? 'tls' : 'ssl'];
        }
    }

    /**
     * @dataProvider schemeRows
     */
    public function testSmtpSecureDecidesWhenTheHostHasASchemeToo(string $name, string $secure): void
    {
        $row = SettingsMatrix::rows()[$name];
        $reference = ['smtp_host' => '127.0.0.1', 'smtp_secure' => $secure] + $row['settings'];
        $this->assertSame('', $reference['smtp_port']);

        $with = $this->observe($row['settings'], $row['server']);
        $without = $this->observe($reference, $row['server']);

        $messagesWith = $with['messages'];
        unset($with['messages'], $without['messages']);
        $this->assertSame($without, $with, $name . ' is observed on the wire as the row without the scheme');

        // Against the wrong kind of server (tls on a server that speaks TLS first, ssl on one that does
        // not) nothing is sent in the clear.
        $wrongKind = $secure === 'tls' ? $row['server']['implicitTls'] : !$row['server']['implicitTls'];
        if ($wrongKind) {
            $this->assertSame('failed', $with['outcome'], $name);
            $this->assertNull($with['mailFrom'], $name . ': nothing was sent over a connection of the wrong kind');
            $this->assertSame([], $messagesWith, $name . ': a message arrived');
        } else {
            $this->assertSame('sent', $with['outcome'], $name);
        }
    }

    public function testPortsTheSettingsMean(): void
    {
        // The default ports, read from what the production factory built (nothing connects to them).
        $cases = [
            ['host-only', 25], ['host-587', 587], ['host-465-ssl', 465], ['host-ssl-no-port', 25],
            ['host-tls-no-port', 25], ['port-zero', 25], ['port-numeric-string', 2525], ['no-host', 25],
            ['ssl-scheme-host', 25], ['tls-scheme-host/implicit-server', 25], ['host-465-tls', 465],
        ];
        foreach ($cases as [$name, $port]) {
            TestMailTransportFactory::forget();
            $this->startSmtp(SettingsMatrix::rows()[$name]['server']);
            $this->mailSettings(SettingsMatrix::rows()[$name]['settings']);
            $this->sampleEmail()->send();
            $this->assertSame($port, TestMailTransportFactory::built()[0]['port'], $name);
            if ($name === 'host-465-tls') {
                // Where Symfony's own factory would make the connection encrypted from the first byte.
                $this->assertFalse(TestMailTransportFactory::built()[0]['implicitTls']);
                $this->assertTrue(TestMailTransportFactory::built()[0]['requireTls']);
            }
        }
    }
}
