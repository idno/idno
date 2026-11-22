<?php

declare(strict_types=1);

namespace Tests\Mail;

use Idno\Config\SiteSettings;

/**
 * Site settings held in an array, for a test that builds Idno\Mail\MailerFactory itself.
 */
final class ArraySettings implements SiteSettings
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function isOverridden(string $key): bool
    {
        return false;
    }

    public function save(): void
    {
    }
}
