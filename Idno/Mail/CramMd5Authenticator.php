<?php

declare(strict_types=1);

namespace Idno\Mail;

use Symfony\Component\Mailer\Transport\Smtp\Auth\AuthenticatorInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * CRAM-MD5 (RFC 2195, RFC 4954), as Symfony's authenticator does it, except that an empty password is
 * used as it is: Symfony refuses one, SwiftMailer logged in with it, and a site with a username and no
 * password keeps its login attempt. The response is the keyed MD5 digest of the server's challenge.
 */
final class CramMd5Authenticator implements AuthenticatorInterface
{
    public function getAuthKeyword(): string
    {
        return 'CRAM-MD5';
    }

    public function authenticate(EsmtpTransport $client): void
    {
        $challenge = $client->executeCommand("AUTH CRAM-MD5\r\n", [334]);
        $challenge = (string) base64_decode(substr($challenge, 4));
        $response = $client->getUsername() . ' ' . hash_hmac('md5', $challenge, $client->getPassword());
        $client->executeCommand(base64_encode($response) . "\r\n", [235]);
    }
}
