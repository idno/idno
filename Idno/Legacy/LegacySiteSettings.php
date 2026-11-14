<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Config\BootConfig;
use Idno\Config\SiteSettings;
use Idno\Core\Config;

/**
 * SiteSettings backed by the legacy Idno\Core\Config object.
 */
final class LegacySiteSettings implements SiteSettings
{
    public function __construct(
        private readonly Config $config,
        private readonly BootConfig $bootConfig,
    )
    {
    }

    /**
     * Container factory. Idno\Core\Config is not a container service yet, so it is
     * fetched from the legacy singleton at the moment this service is first used
     * (never at compile time).
     */
    public static function fromLegacySite(BootConfig $bootConfig): self
    {
        return new self(\Idno\Core\Idno::site()->config(), $bootConfig);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->config->config) ? $this->config->config[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        if ($this->isOverridden($key)) {
            throw new \LogicException(sprintf('Setting "%s" is set in config.ini, config.json or the environment and cannot be changed.', $key));
        }
        $this->config->config[$key] = $value;
    }

    public function isOverridden(string $key): bool
    {
        return $this->bootConfig->has($key);
    }

    public function save(): void
    {
        $this->config->save();
    }
}
