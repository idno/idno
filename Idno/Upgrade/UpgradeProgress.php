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
 *  - $error: what stopped the last step, as a PublicMessage's text (safe to show to anyone), or
 *    null (a step that works clears it). Also an impossible state found from the tables alone, when
 *    no step could store it (no progress row).
 *  - $errorDetail: the private detail of that error (an exception's class and message, which may
 *    hold a uuid or a driver message), for the log and `idno:upgrade` only; null when there is none.
 *  - $cursors: per collection its copy and verify cursors (`position`, `verify_cursor`): they hold
 *    uuids, so they are for telling whether a step moved, never for display.
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
     * @param array<string, array{position: ?string, verify_cursor: ?string}>           $cursors
     */
    public function __construct(
        public readonly array $collections,
        public readonly bool $complete,
        public readonly ?string $error,
        public readonly string $phase = self::NOT_STARTED,
        public readonly bool $noLegacyDatabase = false,
        public readonly ?string $errorDetail = null,
        public readonly array $cursors = [],
    )
    {
    }

    /**
     * How far one collection's copy is, in the one wording the page, the runner and the command
     * share (the page's script repeats it): `1500 of 12000 copied`, `, 3 not carried over` when
     * there are rejects.
     *
     * @param array{source: int, copied: int, rejected: int} $counts
     */
    public static function countText(array $counts): string
    {
        return ($counts['copied'] + $counts['rejected']) . ' of ' . $counts['source'] . ' copied'
            . ($counts['rejected'] > 0 ? ', ' . $counts['rejected'] . ' not carried over' : '');
    }

    /**
     * The progress line of one collection: `entities: 1500 of 12000 copied`.
     *
     * @param array{source: int, copied: int, rejected: int} $counts
     */
    public static function line(string $collection, array $counts): string
    {
        return $collection . ': ' . self::countText($counts);
    }
}
