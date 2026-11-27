<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use Idno\Mail\MailFailure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;

final class MailFailureTest extends TestCase
{
    /**
     * What Symfony Mailer says when it fails, and the kind the administrator is told it is (the words are Symfony's: see
     * Transport\Smtp\EsmtpTransport and Stream\SocketStream).
     *
     * @return iterable<string, array{string, int, string, string, string}>
     */
    public static function failures(): iterable
    {
        yield 'a refused login' => ['Failed to authenticate on SMTP server with username "u" using the following authenticators: "PLAIN". Authenticator "PLAIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Authentication failed".".', 535, '', 'auth', ''];
        yield 'STARTTLS asked for first' => ['Expected response code "250" but got code "530", with message "530 5.7.0 Must issue a STARTTLS command first".', 530, '', 'tls', 'starttls'];
        yield 'a certificate that does not verify' => ['Connection could not be established with host "ssl://smtp.example.org:465": stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages: error:0A000086:SSL routines::certificate verify failed', 0, 'ssl', 'tls', 'certificate'];
        yield 'STARTTLS that cannot start' => ['Unable to connect with STARTTLS: stream_socket_enable_crypto(): SSL operation failed with code 1', 0, 'tls', 'tls', 'starttls'];
        yield 'TLS on a port that speaks plain' => ['Connection could not be established with host "ssl://smtp.example.org:587": stream_socket_client(): SSL operation failed with code 1. OpenSSL Error messages: error:0A00010B:SSL routines::wrong version number', 0, 'ssl', 'tls', 'handshake'];
        yield 'a host that is not there' => ['Connection could not be established with host "tcp://nosuch.example:25": php_network_getaddresses: getaddrinfo for nosuch.example failed: Name or service not known', 0, '', 'connect', 'unknown-host'];
        yield 'a port nothing listens on' => ['Connection could not be established with host "tcp://127.0.0.1:1": stream_socket_client(): Unable to connect to tcp://127.0.0.1:1 (Connection refused)', 0, '', 'connect', 'refused'];
        yield 'a server that does not answer' => ['Connection to "tcp://smtp.example.org:25" timed out.', 0, '', 'connect', 'no-answer'];
        yield 'a sender the service has not verified' => ['Expected response code "250" but got code "550", with message "550 5.7.1 The from address is not verified".', 550, '', 'sender', ''];
        yield 'a message refused' => ['Expected response code "250" but got code "554", with message "554 Message rejected as spam".', 554, '', 'rejected', ''];
        yield 'try again later' => ['Expected response code "250" but got code "451", with message "451 Try again later".', 451, '', 'temporary', ''];
        yield 'something else' => ['It broke.', 0, '', 'unknown', ''];
    }

    /**
     * @dataProvider failures
     */
    public function testAFailureOfTheMailServerIsToldByItsKind(string $message, int $code, string $security, string $kind, string $variant): void
    {
        $found = MailFailure::classify(new TransportException($message, $code), $security);

        $this->assertSame($kind, $found['kind']);
        $this->assertSame($variant, $found['variant']);
    }

    /**
     * The server quotes the login back in its reply, in several forms: none of them may reach an administrator's screen or a log.
     */
    public function testTheServersReplyHasTheUserNameAndThePasswordTakenOutInEveryFormTheyTravelIn(): void
    {
        $user = 'mailer-user-77';
        $password = 'mailer-secret-91';
        $blob = base64_encode("\0" . $user . "\0" . $password);
        $e = new TransportException('Expected response code "235" but got code "535", with message "535 No such login ' . $user . ' with ' . $password . ' / ' . $blob . ' / ' . base64_encode($password) . '".', 535);

        $reply = MailFailure::reply($e, $user, $password);

        $this->assertStringContainsString('No such login', $reply);
        foreach ([$user, $password, $blob, base64_encode($password)] as $secret) {
            $this->assertStringNotContainsString($secret, $reply);
        }
    }

    // A reply with a right-to-left override or an isolate would reorder the words the administrator reads around it.
    public function testDirectionControlsInTheServersReplyAreDropped(): void
    {
        $e = new TransportException('Expected response code "250" but got code "550", with message "550 no ' . "\u{202E}" . 'such' . "\u{2066}" . ' user' . "\u{200F}" . "\u{061C}" . '".', 550);

        $this->assertSame('550 no such user', MailFailure::reply($e, '', ''));
    }

    // A hostile server's very long reply must not flood the administrator's page.
    public function testAVeryLongReplyIsCutShort(): void
    {
        $e = new TransportException('Expected response code "250" but got code "554", with message "' . str_repeat('x', 2000) . '".', 554);

        $this->assertLessThanOrEqual(MailFailure::REPLY_LENGTH + 3, mb_strlen(MailFailure::reply($e, '', '')));
    }
}
