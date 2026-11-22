<?php

declare(strict_types=1);

namespace Idno\Mail;

use Idno\Config\SiteSettings;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;

/**
 * Builds the site's mailer from the mail settings (smtp_host, smtp_port, smtp_username, smtp_password,
 * smtp_secure) as they are at the moment of the call. They are stored settings, which an admin edits,
 * so the mailer is never kept: the container service is not shared, and the next mail after a change
 * uses the new settings (a queue worker never holds an old password). Nothing from the settings is
 * compiled into the container.
 *
 * Reading the settings needs the legacy application behind SiteSettings, so the service must not be
 * fetched before it has booted. The password is in the DSN handed to the transport factory and
 * nowhere else; an exception from here names a setting, never its value.
 */
final class MailerFactory
{
    public function __construct(
        private readonly SiteSettings $settings,
        private readonly TransportFactoryInterface $transportFactory,
    )
    {
    }

    /**
     * @throws \InvalidArgumentException when smtp_host or smtp_port cannot be written in a DSN
     */
    public function create(): MailerInterface
    {
        $values = [];
        foreach (['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_secure'] as $key) {
            $values[$key] = $this->settings->get($key);
        }

        return new Mailer($this->transportFactory->create(Dsn::fromString(MailTransportDsn::fromSettings($values))));
    }
}
