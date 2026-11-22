<?php

declare(strict_types=1);

namespace Tests\Portability;

use Idno\Portability\ExportState;

/**
 * An ExportState that keeps its state in properties: the jobs' tests need no stored settings.
 */
final class InMemoryExportState implements ExportState
{
    public int $requested = 0;

    public bool $inProgress = false;

    public ?string $fileId = null;

    public ?string $filename = null;

    public int $created = 0;

    public int $swept = 0;

    /** @var list<string> what was called, in order */
    public array $calls = [];

    /** @var \Throwable|null thrown by finish() */
    public ?\Throwable $finishFails = null;

    public function read(): array
    {
        return ['requested' => $this->requested, 'created' => $this->created ?: $this->requested, 'inProgress' => $this->inProgress, 'fileId' => $this->fileId, 'filename' => $this->filename, 'swept' => $this->swept];
    }

    public function begin(int $now, int $staleAfter): bool
    {
        $this->calls[] = 'begin';
        if ($this->inProgress && $now - $this->requested < $staleAfter) {
            return false;
        }
        $this->requested = $now;
        $this->inProgress = true;

        return true;
    }

    /** @var list<array{id: string, kind: string, at: int}> */
    public array $tracked = [];

    public function trackedFiles(): array
    {
        return $this->tracked;
    }

    public function trackFile(string $id, string $kind, int $at): void
    {
        $this->tracked[] = ['id' => $id, 'kind' => $kind, 'at' => $at];
    }

    public function untrackFiles(array $ids): void
    {
        $this->tracked = array_values(array_filter($this->tracked, static fn (array $entry): bool => !in_array($entry['id'], $ids, true)));
    }

    public function markSwept(int $now): void
    {
        $this->calls[] = 'swept';
        $this->swept = $now;
    }

    public function finish(string $fileId, string $filename, int $created): ?string
    {
        $this->calls[] = 'finish:' . $fileId;
        if ($this->finishFails !== null) {
            throw $this->finishFails;
        }
        $previous = $this->fileId;
        $this->fileId = $fileId;
        $this->filename = $filename;
        $this->created = $created;
        $this->inProgress = false;

        return $previous;
    }

    public function fail(): void
    {
        $this->calls[] = 'fail';
        $this->inProgress = false;
    }

    public function forgetArchive(): ?string
    {
        $this->calls[] = 'forget';
        $previous = $this->fileId;
        $this->fileId = null;
        $this->filename = null;

        return $previous;
    }
}
