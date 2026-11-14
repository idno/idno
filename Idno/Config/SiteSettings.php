<?php

declare(strict_types=1);

namespace Idno\Config;

/**
 * Admin-editable site settings, stored in the database.
 */
interface SiteSettings
{
    public function get(string $key, mixed $default = null): mixed;

    /**
     * @throws \LogicException when the setting is pinned by the boot configuration
     */
    public function set(string $key, mixed $value): void;

    /**
     * True when the boot configuration (file or environment) pins this setting.
     */
    public function isOverridden(string $key): bool;

    public function save(): void;
}
