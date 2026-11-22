<?php

declare(strict_types=1);

namespace Tests\Mail;

/**
 * The mail settings a site can have, each with the kind of server it is meant for: one list, used by
 * the SwiftMailer capture (Tests/Mail/fixtures/capture-swiftmailer.php, which wrote
 * Tests/Mail/fixtures/swiftmailer-transcripts.php) and by SwiftMailerParityTest, so both run the
 * same rows. A row's settings are the five stored settings as an admin leaves them; its server is the
 * options ScriptedSmtpServer takes (the pki is added by the caller), chosen so that a client that
 * behaves as the row intends can complete.
 *
 * The host is always 127.0.0.1 where a host is given. The port a row names (25, 465, 587) cannot be
 * bound in a test: a test connects to the scripted server's port instead and checks the port the
 * settings MEAN separately (see SwiftMailerParityTest).
 *
 * This file depends on nothing: the capture script loads it without Idno's autoloader.
 */
final class SettingsMatrix
{
    public const PASSWORD_WITH_SPECIAL_CHARACTERS = 'p@ss:w/rd%#? é';

    /**
     * @return array<string, array{settings: array<string, mixed>, server: array<string, mixed>}> by row name
     */
    public static function rows(): array
    {
        // A plain server that also offers STARTTLS (a valid certificate): a client that is not asked
        // for TLS must not use it.
        $plain = ['implicitTls' => false, 'offerStartTls' => true, 'certificate' => 'valid-for-localhost', 'auth' => null];
        $implicit = ['implicitTls' => true, 'offerStartTls' => false, 'certificate' => 'valid-for-localhost', 'auth' => null];
        $startTls = ['implicitTls' => false, 'offerStartTls' => true, 'certificate' => 'valid-for-localhost', 'auth' => null];
        $login = ['user' => 'mailer', 'pass' => 'sesame'];

        $rows = [
            'no-host' => [[], $plain],
            'host-only' => [['smtp_host' => '127.0.0.1'], $plain],
            'host-25' => [['smtp_host' => '127.0.0.1', 'smtp_port' => 25], $plain],
            'host-587' => [['smtp_host' => '127.0.0.1', 'smtp_port' => 587], $plain],
            'host-465-ssl' => [['smtp_host' => '127.0.0.1', 'smtp_port' => 465, 'smtp_secure' => 'ssl'], $implicit],
            'host-ssl-no-port' => [['smtp_host' => '127.0.0.1', 'smtp_secure' => 'ssl'], $implicit],
            'host-tls-no-port' => [['smtp_host' => '127.0.0.1', 'smtp_secure' => 'tls'], $startTls],
            'host-587-tls' => [['smtp_host' => '127.0.0.1', 'smtp_port' => 587, 'smtp_secure' => 'tls'], $startTls],
            'port-zero' => [['smtp_host' => '127.0.0.1', 'smtp_port' => 0], $plain],
            'port-numeric-string' => [['smtp_host' => '127.0.0.1', 'smtp_port' => '2525'], $plain],
            'ssl-scheme-host' => [['smtp_host' => 'ssl://127.0.0.1'], $implicit],
            'tls-scheme-host/implicit-server' => [['smtp_host' => 'tls://127.0.0.1'], $implicit],
            'tls-scheme-host/starttls-server' => [['smtp_host' => 'tls://127.0.0.1'], $startTls],
            'username-and-password' => [['smtp_host' => '127.0.0.1', 'smtp_username' => 'mailer', 'smtp_password' => 'sesame'], ['auth' => $login] + $plain],
            'username-no-password' => [['smtp_host' => '127.0.0.1', 'smtp_username' => 'mailer'], ['auth' => ['user' => 'mailer', 'pass' => '']] + $plain],
            'password-no-username' => [['smtp_host' => '127.0.0.1', 'smtp_password' => 'sesame'], $plain],
            'password-with-special-characters' => [
                ['smtp_host' => '127.0.0.1', 'smtp_username' => 'mailer', 'smtp_password' => self::PASSWORD_WITH_SPECIAL_CHARACTERS],
                ['auth' => ['user' => 'mailer', 'pass' => self::PASSWORD_WITH_SPECIAL_CHARACTERS]] + $plain,
            ],
            'secure-TLS-upper-case' => [['smtp_host' => '127.0.0.1', 'smtp_secure' => 'TLS'], $plain],
            'secure-SSL-upper-case' => [['smtp_host' => '127.0.0.1', 'smtp_secure' => 'SSL'], $plain],
            'secure-starttls' => [['smtp_host' => '127.0.0.1', 'smtp_secure' => 'starttls'], $plain],
            'secure-none' => [['smtp_host' => '127.0.0.1', 'smtp_secure' => 'none'], $plain],
            'tls-server-offers-no-starttls' => [['smtp_host' => '127.0.0.1', 'smtp_secure' => 'tls'], ['offerStartTls' => false] + $plain],
            'tls-against-a-tls-first-server' => [['smtp_host' => '127.0.0.1', 'smtp_secure' => 'tls'], $implicit],
            'ssl-against-a-plain-server' => [['smtp_host' => '127.0.0.1', 'smtp_secure' => 'ssl'], $plain],
            'ssl-self-signed-certificate' => [['smtp_host' => '127.0.0.1', 'smtp_secure' => 'ssl'], ['certificate' => 'self-signed'] + $implicit],
            'tls-self-signed-certificate' => [['smtp_host' => '127.0.0.1', 'smtp_secure' => 'tls'], ['certificate' => 'self-signed'] + $startTls],
            'ssl-with-credentials' => [['smtp_host' => '127.0.0.1', 'smtp_secure' => 'ssl', 'smtp_username' => 'mailer', 'smtp_password' => 'sesame'], ['auth' => $login] + $implicit],
            'tls-with-credentials' => [['smtp_host' => '127.0.0.1', 'smtp_secure' => 'tls', 'smtp_username' => 'mailer', 'smtp_password' => 'sesame'], ['auth' => $login] + $startTls],
            // A scheme in the host combined with smtp_secure: each against both kinds of server.
            'ssl-scheme-host-and-tls/implicit-server' => [['smtp_host' => 'ssl://127.0.0.1', 'smtp_secure' => 'tls'], $implicit],
            'ssl-scheme-host-and-tls/starttls-server' => [['smtp_host' => 'ssl://127.0.0.1', 'smtp_secure' => 'tls'], $startTls],
            'tls-scheme-host-and-tls/implicit-server' => [['smtp_host' => 'tls://127.0.0.1', 'smtp_secure' => 'tls'], $implicit],
            'tls-scheme-host-and-tls/starttls-server' => [['smtp_host' => 'tls://127.0.0.1', 'smtp_secure' => 'tls'], $startTls],
            'ssl-scheme-host-and-ssl/implicit-server' => [['smtp_host' => 'ssl://127.0.0.1', 'smtp_secure' => 'ssl'], $implicit],
            'ssl-scheme-host-and-ssl/starttls-server' => [['smtp_host' => 'ssl://127.0.0.1', 'smtp_secure' => 'ssl'], $startTls],
            'tls-scheme-host-and-ssl/implicit-server' => [['smtp_host' => 'tls://127.0.0.1', 'smtp_secure' => 'ssl'], $implicit],
            'tls-scheme-host-and-ssl/starttls-server' => [['smtp_host' => 'tls://127.0.0.1', 'smtp_secure' => 'ssl'], $startTls],
            'username-empty-password/server-refuses' => [['smtp_host' => '127.0.0.1', 'smtp_username' => 'mailer'], ['auth' => $login] + $plain],
            'no-host-with-credentials/server-asks-for-none' => [['smtp_username' => 'mailer', 'smtp_password' => 'sesame'], $plain],
            'no-host-with-credentials/server-requires-login' => [['smtp_username' => 'mailer', 'smtp_password' => 'sesame'], ['auth' => $login] + $plain],
        ];

        $matrix = [];
        foreach ($rows as $name => [$settings, $server]) {
            $matrix[$name] = [
                'settings' => $settings + ['smtp_host' => '', 'smtp_port' => '', 'smtp_username' => '', 'smtp_password' => '', 'smtp_secure' => ''],
                'server' => $server,
            ];
        }

        return $matrix;
    }

    /**
     * The message every row sends: one To and one Bcc recipient.
     *
     * @return array{from: string, fromName: string, to: string, bcc: string, subject: string, html: string, text: string}
     */
    public static function message(): array
    {
        return [
            'from' => 'site@example.com',
            'fromName' => 'Parity Site',
            'to' => 'reader@example.net',
            'bcc' => 'archive@example.net',
            'subject' => 'Parity check',
            'html' => '<p>Hello, parity.</p>',
            'text' => 'Hello, parity.',
        ];
    }
}
