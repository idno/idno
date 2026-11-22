<?php

declare(strict_types=1);

namespace Idno\Mail;

/**
 * A mail setting that cannot be used. The message and setting() name the setting, never its value, so
 * both are safe to show an admin.
 */
final class InvalidMailSetting extends \InvalidArgumentException
{
    public function __construct(private readonly string $setting, string $reason)
    {
        parent::__construct(sprintf('The setting %s %s.', $setting, $reason));
    }

    public function setting(): string
    {
        return $this->setting;
    }
}
