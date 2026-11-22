<?php

declare(strict_types=1);

namespace Idno\Mail;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\Auth;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds the SMTP transport of a DSN from MailTransportDsn, and does exactly what the DSN says. Symfony's
 * own factory decides some things by itself (a DSN without a port goes to 465 with implicit TLS for any
 * host but localhost; port 465 is implicit TLS whatever the DSN asks for; STARTTLS is tried whenever the
 * server offers it, and the certificate verified), which would change how a site's settings behave. Here
 * the port is the DSN's, a connection is encrypted from the start only with `smtps`, STARTTLS is tried
 * only unless `auto_tls=false`, and `require_tls=true` makes it mandatory. The connection and every read
 * are bounded by TIMEOUT_SECONDS (Symfony's own default is PHP's default_socket_timeout, 60 seconds, and a
 * hung mail server would hold a page that long). Certificates are always verified; nothing in the DSN or
 * the settings changes that.
 */
final class SmtpTransportFactory extends AbstractTransportFactory
{
    /** SwiftMailer's own timeout (its transport's `timeout` parameter), in seconds. */
    public const TIMEOUT_SECONDS = 30.0;

    /**
     * @param float $timeout seconds for the connection and for each read; tests give a short one
     */
    public function __construct(
        private readonly float $timeout = self::TIMEOUT_SECONDS,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    )
    {
        parent::__construct($dispatcher, null, $logger);
    }

    public function create(Dsn $dsn): TransportInterface
    {
        if (!$this->supports($dsn)) {
            throw new \InvalidArgumentException('The mail transport scheme is not supported.');
        }

        $implicitTls = $dsn->getScheme() === 'smtps';
        $transport = new EsmtpTransport($dsn->getHost(), $dsn->getPort(25), $implicitTls, $this->dispatcher, $this->logger);
        $transport->getStream()->setTimeout($this->timeout);
        // The default authenticators, with a CRAM-MD5 that accepts an empty password.
        $transport->setAuthenticators([new CramMd5Authenticator(), new Auth\LoginAuthenticator(), new Auth\PlainAuthenticator(), new Auth\XOAuth2Authenticator()]);
        $transport->setAutoTls(filter_var($dsn->getOption('auto_tls', 'true'), FILTER_VALIDATE_BOOL));
        $transport->setRequireTls($dsn->getBooleanOption('require_tls'));
        if (($user = $dsn->getUser()) !== null && $user !== '') {
            $transport->setUsername($user);
            if (($password = $dsn->getPassword()) !== null && $password !== '') {
                $transport->setPassword($password);
            }
        }

        return $transport;
    }

    protected function getSupportedSchemes(): array
    {
        return ['smtp', 'smtps'];
    }
}
