<?php

declare(strict_types=1);

namespace Idno\Upgrade;

/**
 * Where the copy of a 1.x database stands (LegacyDataCopier::progress()).
 *
 *  - $collections: per 1.x collection (entities, reader, site, config), `source` the rows its
 *    table held when the upgrade began (0 before), `copied` the rows copied into the new schema (for `config`, 1
 *    when a document became the settings), `rejected` the rows kept in `idno_upgrade_rejects`
 *    instead, and `done` whether every row counted in `source` has been copied or rejected. A
 *    source row deleted after it was copied stays counted in `copied`, so `copied + rejected` can
 *    exceed `source`.
 *  - $complete: the 1.x tables were renamed `legacy_<name>` and the copy verified against them:
 *    `completed_at` is set for every collection.
 *  - $error: what stopped the last step, or null (a step that works clears it).
 *  - $phase: NOT_STARTED (no step has run), COPYING (copying the 1.x tables, or about to rename
 *    them), VERIFYING (renamed `legacy_<name>`; checking every source row against the copy) or
 *    COMPLETE. "Stopped with an error" is $error !== null in any phase but COMPLETE.
 *  - $noLegacyDatabase: there is nothing to upgrade here: no table of a 1.x database (under its own
 *    name or renamed `legacy_<name>`) and no upgrade begun. step() then does nothing at all.
 *
 * A value object, not a container service.
 */
final class UpgradeProgress
{
    public const NOT_STARTED = 'not started';

    public const COPYING = 'copying';

    public const VERIFYING = 'verifying';

    public const COMPLETE = 'complete';

    /**
     * @param array<string, array{source: int, copied: int, rejected: int, done: bool}> $collections
     * @param self::NOT_STARTED|self::COPYING|self::VERIFYING|self::COMPLETE             $phase
     */
    public function __construct(
        public readonly array $collections,
        public readonly bool $complete,
        public readonly ?string $error,
        public readonly string $phase = self::NOT_STARTED,
        public readonly bool $noLegacyDatabase = false,
    )
    {
    }
}
