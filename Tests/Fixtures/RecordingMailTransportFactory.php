<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Test-environment only: the factory MailerFactory is given in the test container (it reads the
 * same DSN the production factory would), so the settings still decide what is recorded.
 */
final class RecordingMailTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        return RecordingMailTransport::forDsn($dsn);
    }

    protected function getSupportedSchemes(): array
    {
        return ['smtp', 'smtps'];
    }
}
