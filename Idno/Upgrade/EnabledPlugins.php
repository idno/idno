<?php

declare(strict_types=1);

namespace Idno\Upgrade;

use Doctrine\DBAL\Connection;
use Idno\Config\BootConfig;
use Idno\Storage\SettingsRecordStore;

/**
 * The plugins the site has enabled, decided without the legacy application (the upgrade runs while
 * it is not booted), by the rule Idno\Core\Config and Idno\Core\Plugins load them with:
 *
 *  - Config merges the boot configuration over the stored settings with array_replace_recursive():
 *    a boot `plugins` list replaces the stored `plugins` list index by index (`[IndiePub]` over
 *    `[Status, Text, Photo]` is `[IndiePub, Text, Photo]`; an empty boot list leaves the stored one),
 *    and the same for `prerequisiteplugins`; `alwaysplugins` and `antiplugins` are never read from
 *    the settings (Config drops them);
 *  - Plugins loads the prerequisites first, then the plugins plus `alwaysplugins`, less
 *    `antiplugins` (not from the prerequisites).
 *
 * The stored lists are the `plugins` and `prerequisiteplugins` rows of `idno_settings`: none when the
 * table or a row is not there (legacy would then fall back to its defaults for a site with no saved
 * settings at all; the upgrade reconciles only what is recorded or pinned). Any other database error
 * is thrown. Names are plugin directory names (`[A-Za-z0-9_]+`; anything else is left out), each once,
 * in that order. Reading is one statement; it never writes.
 */
final class EnabledPlugins
{
    public function __construct(
        private readonly BootConfig $config,
        private readonly Connection $connection,
    )
    {
    }

    /**
     * @return list<string>
     *
     * @throws \Doctrine\DBAL\Exception when the settings cannot be read for any reason but their
     *                                    table not being there
     */
    public function names(): array
    {
        $stored = $this->stored();
        $plugins = self::list(array_replace_recursive($stored['plugins'] ?? [], self::array($this->config->get('plugins'))));
        $prerequisites = self::list(array_replace_recursive($stored['prerequisiteplugins'] ?? [], self::array($this->config->get('prerequisiteplugins'))));
        $anti = self::list($this->config->get('antiplugins'));
        $names = $prerequisites;
        foreach ([...$plugins, ...self::list($this->config->get('alwaysplugins'))] as $name) {
            if (!in_array($name, $anti, true)) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The stored `plugins` and `prerequisiteplugins` settings (decoded), by name; [] when the table
     * is not there.
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        try {
            $rows = $this->connection->fetchAllKeyValue(
                'select name, value from ' . SettingsRecordStore::TABLE . ' where name in (?, ?)',
                ['plugins', 'prerequisiteplugins'],
            );
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException) {
            return [];
        }

        return array_map(static fn (mixed $value): mixed => is_string($value) ? json_decode($value, true) : null, $rows);
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $name): bool => is_string($name) && preg_match('/^[A-Za-z0-9_]+$/', $name) === 1));
    }
}
