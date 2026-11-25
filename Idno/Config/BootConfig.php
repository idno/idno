<?php

declare(strict_types=1);

namespace Idno\Config;

/**
 * Immutable configuration read at boot from config.ini, config.json and the
 * environment. Anything present here is "pinned": it overrides the same
 * setting stored in the database and cannot be changed from the admin UI.
 */
final class BootConfig
{
    /**
     * @param array<string, mixed> $values
     * @param bool                 $default True when no config file or JSON file was found and the environment names no database
     */
    public function __construct(
        private readonly array $values,
        private readonly bool $default,
    )
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * True when the site is not installed: no config file or JSON file was found, and the environment
     * names no database.
     */
    public function isDefault(): bool
    {
        return $this->default;
    }
}
