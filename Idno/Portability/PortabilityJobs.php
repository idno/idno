<?php

declare(strict_types=1);

namespace Idno\Portability;

use Idno\Storage\Files\FileStore;
use Psr\Log\LoggerInterface;

/**
 * The work behind the admin pages of the full-archive export and of the imports, which takes too long for a
 * request: the pages start it (as the queued events `portability/export` and `portability/import` when
 * the site's queue is asynchronous, else after the response) and each job ends, whatever happens, with a mail
 * to the administrator who asked: success, or failure. A failure of any kind (an \Error too) is a failure: it
 * is logged, the state is cleared, the failure mail is sent, and nothing is thrown, so the worker does not
 * park the event.
 *
 * Also what the pages need to know: whether an export is running (one at a time; a flag older than six hours
 * is a job that died and is ignored), the archive that was made (removed when a new one is made and when it
 * is a week old, checked whenever the export page is loaded or an export asked for: there is no scheduler),
 * and where an upload waits for its import (the file store, for administrators only, removed when the
 * import ends).
 */
final class PortabilityJobs
{
    public const EXPORT_EVENT = 'portability/export';

    public const IMPORT_EVENT = 'portability/import';

    /** An export flag older than this is a job that died. */
    public const STALE_EXPORT_SECONDS = 6 * 3600;

    /** An archive older than this is removed. */
    public const ARCHIVE_LIFETIME_SECONDS = 7 * 86400;

    /** The import types the pages offer, as the event names them. */
    public const IMPORT_TYPES = ['wordpress', 'blogger'];

    /** What the file store's metadata says of an upload waiting for its import. */
    public const UPLOAD_PURPOSE = 'import';

    /** The metadata key that says when an upload was staged. */
    public const UPLOADED_AT = 'portability_upload';

    /** The metadata key that says when a job began to import an upload (the time, as seconds). */
    public const STARTED_AT = 'import_started';

    /** Working folders and staged uploads older than this are leftovers of a job that was killed. */
    public const LEFTOVER_SECONDS = 86400;

    /** Leftovers are looked for at most this often. */
    public const SWEEP_INTERVAL_SECONDS = 3600;

    /** At most this many stored files are tracked for the sweep; the oldest beyond it are removed. */
    public const TRACKED_LIMIT = 50;

    private readonly \Closure $clock;

    private readonly \Closure $uid;

    public function __construct(
        private readonly ArchiveExporter $exporter,
        private readonly WordPressImporter $wordpress,
        private readonly BloggerImporter $blogger,
        private readonly FileStore $files,
        private readonly ExportState $state,
        private readonly ResultNotifier $notifier,
        private readonly SiteFacts $site,
        private readonly LoggerInterface $logger,
        ?\Closure $clock = null,
        ?\Closure $uid = null,
    )
    {
        $this->clock = $clock ?? static fn (): int => time();
        $this->uid = $uid ?? static fn (): int => function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
    }

    // ------------------------------------------------------------------ what the pages ask

    /**
     * @return array{requested: int, inProgress: bool, fileId: ?string, filename: ?string} the export state, after an archive that is too old has been removed
     */
    public function exportState(): array
    {
        $this->sweep();
        $this->removeExpiredArchive();
        $this->markRecordedArchive();
        $state = $this->state->read();
        // A flag older than the stale limit is a job that died: it is not "in progress" for the page either,
        // which would otherwise offer no way to start another.
        if ($state['inProgress'] && ($this->clock)() - $state['requested'] >= self::STALE_EXPORT_SECONDS) {
            $state['inProgress'] = false;
        }

        return $state;
    }

    /**
     * Starts an export: false when one is running (nothing is changed).
     */
    public function beginExport(): bool
    {
        $this->sweep();
        $this->removeExpiredArchive();
        $this->markRecordedArchive();

        return $this->state->begin(($this->clock)(), self::STALE_EXPORT_SECONDS);
    }

    /**
     * Removes the recorded archive when it is a week old.
     *
     * @return bool whether one was removed
     */
    public function removeExpiredArchive(): bool
    {
        $state = $this->state->read();
        if ($state['fileId'] === null || ($this->clock)() - $state['created'] < self::ARCHIVE_LIFETIME_SECONDS) {
            return false;
        }
        $id = $this->state->forgetArchive();
        if ($id !== null) {
            $this->deleteStored($id);
        }

        return true;
    }

    /**
     * An archive recorded by an earlier release has no `restricted` mark: write it, so that it stays
     * restricted after a newer archive replaces the setting that names it.
     */
    public function markRecordedArchive(): void
    {
        $state = $this->state->read();
        if ($state['fileId'] === null) {
            return;
        }
        try {
            $stored = $this->files->find($state['fileId']);
            if ($stored !== null && !array_key_exists('restricted', $stored->metadata)) {
                $this->files->updateMetadata($state['fileId'], ['restricted' => ArchiveExporter::RESTRICTED_TO, 'purpose' => 'export', ArchiveExporter::MADE_AT => $state['created']]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('The recorded archive could not be marked: ' . $e::class);
        }
    }

    /**
     * Removes what a job that was killed left: the working directories this install made in the temporary
     * directory (`idno-export-`, `idno-import-` and `idno-archive-` and 32 hex digits, a directory this user owns,
     * older than 24 hours), and, from the files the jobs recorded (never by listing the file store), an upload
     * older than 24 hours and an archive that is not the recorded one when it is a week old. Done at most once an
     * hour. Nothing that does not match those names exactly is touched: the temporary directory is shared.
     */
    public function sweep(): void
    {
        $now = ($this->clock)();
        try {
            if ($now - $this->state->read()['swept'] < self::SWEEP_INTERVAL_SECONDS) {
                return;
            }
            $this->state->markSwept($now);

            $temporary = rtrim($this->site->temporaryDirectory(), '/\\');
            $me = ($this->uid)();
            foreach (glob($temporary . '/idno-*') ?: [] as $path) {
                if (preg_match('~/idno-(?:export|import|archive)-[0-9a-f]{32}$~', $path) === 1
                    && is_dir($path) && !is_link($path)
                    && @fileowner($path) === $me
                    && $now - (int) @filemtime($path) > self::LEFTOVER_SECONDS
                ) {
                    ArchiveExporter::removeTree($path);
                }
            }

            $recorded = $this->state->read()['fileId'];
            $done = [];
            foreach ($this->state->trackedFiles() as $entry) {
                $age = $now - $entry['at'];
                if (!$this->files->has($entry['id'])) {
                    $done[] = $entry['id']; // gone already
                } elseif ($entry['kind'] === 'upload' && $age > self::LEFTOVER_SECONDS) {
                    $this->deleteStored($entry['id']);
                } elseif ($entry['kind'] === 'archive' && $entry['id'] !== $recorded && $age > self::ARCHIVE_LIFETIME_SECONDS) {
                    $this->deleteStored($entry['id']);
                }
            }
            if ($done !== []) {
                $this->state->untrackFiles($done);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Looking for leftovers of killed jobs failed: ' . $e::class);
        }
    }

    /**
     * Records a stored file for the sweep, and removes the oldest files beyond the limit.
     */
    private function track(string $id, string $kind): void
    {
        try {
            $this->state->trackFile($id, $kind, ($this->clock)());
            $tracked = $this->state->trackedFiles();
            $surplus = count($tracked) - self::TRACKED_LIMIT;
            if ($surplus <= 0) {
                return;
            }
            usort($tracked, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);
            $recorded = $this->state->read()['fileId'];
            $dropped = [];
            foreach ($tracked as $entry) {
                if ($surplus <= 0) {
                    break;
                }
                if ($entry['id'] === $recorded) {
                    continue; // the archive the page offers is the expiry's
                }
                $this->deleteStored($entry['id']);
                $dropped[] = $entry['id'];
                $surplus--;
            }
            $this->state->untrackFiles($dropped);
        } catch (\Throwable $e) {
            $this->logger->warning('A stored file could not be recorded for cleaning up: ' . $e::class);
        }
    }

    /**
     * Keeps an uploaded file for its import, where only administrators can fetch it.
     *
     * @return string the id of the stored file
     *
     * @throws \RuntimeException when the file cannot be stored
     */
    public function stageUpload(string $path, string $name): string
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('The upload cannot be read.');
        }
        try {
            $id = $this->files->store($stream, [
                'filename' => basename($name) === '' ? 'import' : basename($name),
                'mime_type' => 'application/xml',
                'restricted' => ArchiveExporter::RESTRICTED_TO,
                'purpose' => self::UPLOAD_PURPOSE,
                self::UPLOADED_AT => ($this->clock)(),
            ]);
            $this->track($id, 'upload');

            return $id;
        } finally {
            fclose($stream);
        }
    }

    /** Removes a staged upload that will not be imported (the job could not be queued). */
    public function discardUpload(string $id): void
    {
        $this->deleteStored($id);
    }

    /** Clears the running flag of an export that could not be queued. */
    public function cancelExport(): void
    {
        try {
            $this->state->fail();
        } catch (\Throwable $e) {
            $this->logger->error('The export\'s state could not be cleared: ' . $this->describe($e));
        }
    }

    // ------------------------------------------------------------------ the jobs

    /**
     * Makes the archive for the administrator and mails them how it went.
     */
    public function runExport(string $userUuid): bool
    {
        $succeeded = false;
        $report = new ExportReport();
        try {
            $this->sweep();
            $id = $this->exporter->export($userUuid, $report);
            $this->track($id, 'archive'); // so that a job killed before it records the archive does not leave it for ever
            try {
                $previous = $this->state->finish($id, $this->exporter->archiveName(), ($this->clock)());
            } catch (\Throwable $e) {
                $this->deleteStored($id);

                throw $e;
            }
            if ($previous !== null) {
                $this->deleteStored($previous);
            }
            $succeeded = true;
        } catch (\Throwable $e) {
            $this->logger->error('The export failed: ' . $this->describe($e));
            try {
                $this->state->fail();
            } catch (\Throwable $again) {
                $this->logger->error('The export\'s state could not be cleared: ' . $this->describe($again));
            }
        }
        $this->notifier->exportFinished($userUuid, $succeeded, $report->unrendered);

        return $succeeded;
    }

    /**
     * Imports the staged upload as the administrator, removes the upload, and mails them how it went.
     *
     * @param string $type one of IMPORT_TYPES
     */
    public function runImport(string $userUuid, string $uploadId, string $type): bool
    {
        // Only an upload this class staged is read, and only such an upload is removed: the event names a file
        // by its id, and must not be a way to read or delete any other file of the store.
        $staged = false;
        $this->sweep();
        try {
            $stored = $this->files->find($uploadId);
            $staged = $stored !== null && ($stored->metadata['purpose'] ?? null) === self::UPLOAD_PURPOSE;
        } catch (\Throwable $e) {
            $this->logger->error('The uploaded file could not be looked up: ' . $this->describe($e));
        }

        if (!$this->site->isAdministrator($userUuid)) {
            $this->logger->warning('An import was asked for by someone who is not an administrator, and was not run.');
            if ($staged) {
                $this->deleteStored($uploadId);
            }

            return false;
        }

        $succeeded = false;
        $work = null;
        $counts = ImportResult::counts();
        $startedBefore = false;
        $stopped = false;
        try {
            if (!$staged) {
                throw new \RuntimeException('The uploaded file is not there.');
            }
            // An upload is run once. A job that finds the mark was started before (a message put back after a
            // worker was killed, a second click) and did not finish: the posts it saved are there, and running
            // it again would import them twice. It is refused, and the administrator told to check.
            if (isset($stored->metadata[self::STARTED_AT])) {
                $startedBefore = true;
                $this->logger->warning('An import was refused: its upload had been started before.');

                throw new \RuntimeException('The import was started before.');
            }
            if (!$this->files->updateMetadata($uploadId, [self::STARTED_AT => ($this->clock)()])) {
                throw new \RuntimeException('The upload cannot be marked as started.');
            }
            $work = $this->copyToWorkingFile($uploadId);
            $counts = match ($type) {
                'wordpress' => $this->wordpress->import($work, $userUuid),
                'blogger' => $this->blogger->import($work, $userUuid),
                default => throw new \InvalidArgumentException('Unknown import type.'),
            };
            $stopped = !empty($counts['stopped']);
            // An import stopped after repeated errors is a failure, whatever it had imported before it stopped.
            $succeeded = $counts['posts'] > 0 && !$stopped;
            $this->logger->info('Import finished: ' . $counts['posts'] . ' posts, ' . $counts['comments'] . ' comments, ' . $counts['media'] . ' pictures, ' . $counts['skipped'] . ' skipped.');
        } catch (\Throwable $e) {
            $this->logger->error('The import failed: ' . $this->describe($e));
        } finally {
            if ($work !== null) {
                ArchiveExporter::removeTree(dirname($work));
            }
            if ($staged) {
                $this->deleteStored($uploadId);
            }
        }
        $this->notifier->importFinished($userUuid, $succeeded, $counts['posts'], $counts['skipped'], $startedBefore, $stopped);

        return $succeeded;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The stored upload's bytes in a file of a private directory this install makes
     * (`<temporary>/idno-import-<32 hex>`, mode 0700), whose path is where the parser reads it. The caller removes
     * the directory (dirname of the path).
     */
    private function copyToWorkingFile(string $id): string
    {
        $source = $this->files->readStream($id);
        $directory = rtrim($this->site->temporaryDirectory(), '/\\') . '/idno-import-' . bin2hex(random_bytes(16));
        if (!@mkdir($directory, 0700)) {
            fclose($source);

            throw new \RuntimeException('A working directory cannot be made.');
        }
        @chmod($directory, 0700);
        $path = $directory . '/import.xml';
        try {
            $target = fopen($path, 'wb');
            if ($target === false) {
                throw new \RuntimeException('A working file cannot be written.');
            }
            try {
                stream_copy_to_stream($source, $target);
            } finally {
                fclose($target);
            }
            @chmod($path, 0600);

            return $path;
        } catch (\Throwable $e) {
            ArchiveExporter::removeTree($directory);

            throw $e;
        } finally {
            fclose($source);
        }
    }

    private function deleteStored(string $id): void
    {
        try {
            $this->files->delete($id);
            $this->state->untrackFiles([$id]);
        } catch (\Throwable $e) {
            $this->logger->warning('A stored file could not be removed: ' . $e::class);
        }
    }

    private function describe(\Throwable $e): string
    {
        return $e::class . ': ' . substr((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $e->getMessage()), 0, 200);
    }
}
