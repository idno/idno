<?php

declare(strict_types=1);

namespace Idno\Portability;

/**
 * Tells the administrator who asked how an import or an export ended, by mail
 * (Idno\Legacy\LegacyResultNotifier is the adapter over the mailer and its templates). It never throws.
 */
interface ResultNotifier
{
    /**
     * @param int  $imported      posts the import made
     * @param int  $skipped       items it did not import (not posts, or ones that failed)
     * @param bool $startedBefore the upload had been started by an earlier job and was not run again
     * @param bool $stopped       the import was stopped after repeated errors ($imported posts were made before that)
     */
    public function importFinished(string $userUuid, bool $succeeded, int $imported = 0, int $skipped = 0, bool $startedBefore = false, bool $stopped = false): void;

    /**
     * @param int $unrendered entries that could not be rendered and are in the archive as data only
     */
    public function exportFinished(string $userUuid, bool $succeeded, int $unrendered = 0): void;
}
