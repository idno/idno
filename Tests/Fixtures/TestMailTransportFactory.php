<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Idno\Mail\SmtpTransportFactory;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Test-environment only (config/services_test.php): the transport factory MailerFactory is given in the
 * test container. By default it is RecordingMailTransportFactory: nothing is sent, each message is
 * recorded. When the environment variable IDNO_TEST_MAIL_DELIVER is `host:port` (read at each call, from
 * $_SERVER then the process environment, as the other test hooks are; the rig's web server never has it),
 * it builds the PRODUCTION transport (Idno\Mail\SmtpTransportFactory, from the DSN the settings give) and
 * changes only WHERE it connects: the transport's own host and port are replaced by that address, so the
 * port the settings mean (25, 465, 587) is not bound, and a test can still say which port that was
 * (built()). Nothing else of the transport changes: security, STARTTLS, credentials and the timeout are
 * the production ones, and the certificate is verified.
 *
 * One more variable, read the same way and inert unless the first is set: IDNO_TEST_MAIL_CAFILE, a PEM
 * file of an authority the TLS stream trusts as well (the stream option `cafile`, the way a test trusts
 * its own certificate authority). Nothing in Idno\ reads either.
 */
final class TestMailTransportFactory extends AbstractTransportFactory
{
    public const DELIVER = 'IDNO_TEST_MAIL_DELIVER';
    public const CAFILE = 'IDNO_TEST_MAIL_CAFILE';

    /**
     * What the production factory built for each transport made while delivering, in this process: the
     * host and port the settings mean (before they were replaced), whether the connection is encrypted
     * from the first byte, whether STARTTLS is tried and whether it is required, the timeout and the
     * username.
     *
     * @var list<array{host: string, port: int, implicitTls: bool, autoTls: bool, requireTls: bool, timeout: float, username: string}>
     */
    private static array $built = [];

    /**
     * @return list<array{host: string, port: int, implicitTls: bool, autoTls: bool, requireTls: bool, timeout: float, username: string}>
     */
    public static function built(): array
    {
        return self::$built;
    }

    public static function forget(): void
    {
        self::$built = [];
    }

    public function create(Dsn $dsn): TransportInterface
    {
        $target = self::variable(self::DELIVER);
        if ($target === null) {
            return RecordingMailTransport::forDsn($dsn);
        }
        $transport = (new SmtpTransportFactory())->create($dsn);
        if (!$transport instanceof EsmtpTransport) {
            throw new \LogicException('The SMTP factory built something else than an EsmtpTransport.');
        }
        $stream = $transport->getStream();
        self::$built[] = [
            'host' => $stream->getHost(),
            'port' => $stream->getPort(),
            'implicitTls' => $stream->isTLS(),
            'autoTls' => $transport->isAutoTls(),
            'requireTls' => $transport->isTlsRequired(),
            'timeout' => $stream->getTimeout(),
            'username' => $transport->getUsername(),
        ];
        [$host, $port] = explode(':', $target) + [1 => '0'];
        $stream->setHost($host)->setPort((int) $port);
        if (($cafile = self::variable(self::CAFILE)) !== null) {
            $stream->setStreamOptions(['ssl' => ['cafile' => $cafile]]);
        }

        return $transport;
    }

    protected function getSupportedSchemes(): array
    {
        return ['smtp', 'smtps'];
    }

    private static function variable(string $name): ?string
    {
        $value = $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
