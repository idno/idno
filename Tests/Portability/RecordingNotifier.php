<?php

declare(strict_types=1);

namespace Tests\Portability;

use Idno\Portability\ResultNotifier;

/**
 * A ResultNotifier that records what it was asked to tell, instead of mailing it.
 */
final class RecordingNotifier implements ResultNotifier
{
    /** @var list<array{string, bool}> */
    public array $imports = [];

    /** @var list<array{string, bool}> */
    public array $exports = [];

    /** @var list<int> the number of entries that could not be rendered, for each export */
    public array $unrendered = [];

    /** @var list<array{imported: int, skipped: int, startedBefore: bool, stopped: bool}> the counts told for each import */
    public array $importDetails = [];

    public function importFinished(string $userUuid, bool $succeeded, int $imported = 0, int $skipped = 0, bool $startedBefore = false, bool $stopped = false): void
    {
        $this->imports[] = [$userUuid, $succeeded];
        $this->importDetails[] = ['imported' => $imported, 'skipped' => $skipped, 'startedBefore' => $startedBefore, 'stopped' => $stopped];
    }

    public function exportFinished(string $userUuid, bool $succeeded, int $unrendered = 0): void
    {
        $this->exports[] = [$userUuid, $succeeded];
        $this->unrendered[] = $unrendered;
    }
}
