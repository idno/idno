<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Config;
use Idno\Portability\ExportState;

/**
 * The archive export's state in the stored settings (`export_last_requested`, `export_in_progress`,
 * `export_file_id`, `export_filename`). Each change reads the stored settings again first, so that a
 * job that began long ago (a queue worker's configuration object is as old as the worker) never writes
 * back a setting an administrator has changed since: it sets only its own keys.
 */
final class LegacyExportState implements ExportState
{
    public function __construct(private readonly Config $config)
    {
    }

    public function read(): array
    {
        $settings = $this->config->config;

        return [
            'requested' => (int) ($settings['export_last_requested'] ?? 0),
            'created' => (int) ($settings['export_file_created'] ?? $settings['export_last_requested'] ?? 0),
            'swept' => (int) ($settings['portability_last_sweep'] ?? 0),
            'inProgress' => !empty($settings['export_in_progress']),
            'fileId' => !empty($settings['export_file_id']) && is_string($settings['export_file_id']) ? $settings['export_file_id'] : null,
            'filename' => !empty($settings['export_filename']) && is_string($settings['export_filename']) ? $settings['export_filename'] : null,
        ];
    }

    public function begin(int $now, int $staleAfter): bool
    {
        $this->reload();
        $state = $this->read();
        if ($state['inProgress'] && $now - $state['requested'] < $staleAfter) {
            return false;
        }
        $this->config->export_last_requested = $now;
        $this->config->export_in_progress = 1;
        $this->save();

        return true;
    }

    public function trackedFiles(): array
    {
        $tracked = [];
        foreach ((array) ($this->config->config['portability_tracked'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['id'], $entry['kind'], $entry['at']) && is_string($entry['id']) && is_string($entry['kind'])) {
                $tracked[] = ['id' => $entry['id'], 'kind' => $entry['kind'], 'at' => (int) $entry['at']];
            }
        }

        return $tracked;
    }

    public function trackFile(string $id, string $kind, int $at): void
    {
        $this->reload();
        $tracked = $this->trackedFiles();
        $tracked[] = ['id' => $id, 'kind' => $kind, 'at' => $at];
        $this->config->portability_tracked = $tracked;
        $this->save();
    }

    public function untrackFiles(array $ids): void
    {
        $this->reload();
        $before = $this->trackedFiles();
        $after = array_values(array_filter($before, static fn (array $entry): bool => !in_array($entry['id'], $ids, true)));
        if (count($after) === count($before)) {
            return;
        }
        $this->config->portability_tracked = $after;
        $this->save();
    }

    public function markSwept(int $now): void
    {
        $this->reload();
        $this->config->portability_last_sweep = $now;
        $this->save();
    }

    public function finish(string $fileId, string $filename, int $created): ?string
    {
        $this->reload();
        $previous = $this->read()['fileId'];
        $this->config->export_file_id = $fileId;
        $this->config->export_filename = $filename;
        $this->config->export_file_created = $created;
        $this->config->export_in_progress = 0;
        $this->save();

        return $previous !== $fileId ? $previous : null;
    }

    public function fail(): void
    {
        $this->reload();
        $this->config->export_in_progress = 0;
        $this->save();
    }

    public function forgetArchive(): ?string
    {
        $this->reload();
        $previous = $this->read()['fileId'];
        if ($previous === null) {
            return null;
        }
        $this->config->export_file_id = false;
        $this->config->export_filename = false;
        $this->config->export_file_created = false;
        $this->save();

        return $previous;
    }

    /**
     * Saves the settings. Config::save() (like init()) starts again from the defaults and loads the stored
     * settings over them, which leaves PHP's default time zone at the default's: the site's own is put back,
     * so that what the job draws afterwards (the archive's pages show dates) is in the site's time zone.
     */
    private function save(): void
    {
        $this->config->save();
        $this->restoreTimezone();
    }

    private function reload(): void
    {
        // What Config::save() does after it saves: the defaults, then the stored settings over them.
        $this->config->init();
        $this->config->load();
        $this->restoreTimezone();
    }

    private function restoreTimezone(): void
    {
        $timezone = $this->config->config['timezone'] ?? null;
        if (is_string($timezone) && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            date_default_timezone_set($timezone);
        }
    }
}
