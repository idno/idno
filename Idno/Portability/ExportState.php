<?php

declare(strict_types=1);

namespace Idno\Portability;

/**
 * What the site remembers of its archive export: when one was last requested, whether one is running,
 * and the stored file that holds the last archive. Every change reads the stored settings again first and
 * sets only its own keys, so it never reverts a setting an administrator changed meanwhile
 * (Idno\Legacy\LegacyExportState is the adapter over the stored settings).
 */
interface ExportState
{
    /**
     * `requested` is when the last export was asked for; `created` is when the recorded archive was made (the
     * request's time for an archive recorded before it was kept); `swept` is when leftovers were last looked for.
     *
     * @return array{requested: int, created: int, inProgress: bool, fileId: ?string, filename: ?string, swept: int}
     */
    public function read(): array;

    /**
     * Marks an export as started at $now, unless one is running that was started less than
     * $staleAfter seconds ago (a flag older than that is a job that died, and is ignored).
     *
     * @return bool false when another export is running (nothing was changed)
     */
    public function begin(int $now, int $staleAfter): bool;

    /**
     * Records the finished archive and clears the running flag.
     *
     * @return ?string the file id of the archive it replaces, if any
     */
    public function finish(string $fileId, string $filename, int $created): ?string;

    /**
     * The stored files the jobs made that may be left behind if a job is killed (an upload waiting for its
     * import, an archive), with the time each was made: what the sweep looks at, instead of listing the file
     * store (which, on a large store or S3, takes minutes).
     *
     * @return list<array{id: string, kind: string, at: int}> `kind` is `upload` or `archive`
     */
    public function trackedFiles(): array;

    /** Adds a stored file to those the sweep looks at. */
    public function trackFile(string $id, string $kind, int $at): void;

    /** @param list<string> $ids files that are gone, or no longer the sweep's business */
    public function untrackFiles(array $ids): void;

    /** Records when leftovers were last looked for. */
    public function markSwept(int $now): void;

    /** Clears the running flag of an export that failed. */
    public function fail(): void;

    /**
     * Forgets the recorded archive.
     *
     * @return ?string its file id, if there was one
     */
    public function forgetArchive(): ?string;
}
