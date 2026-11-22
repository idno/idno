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
 * smtp_secure) as this process last loaded them (the SiteSettings it is given: in a web request the
 * settings it read at its start, in a long-running queue worker the settings it read when it started).
 * The mailer is built at each call and never kept, so a change made in THIS process's configuration
 * applies to its next mail; a change an administrator saves in a web request reaches a running worker
 * only when the worker next starts (it stops itself after an hour). Nothing from the settings is
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
