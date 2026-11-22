<?php

declare(strict_types=1);

namespace Idno\Upgrade;

use Idno\Storage\IndexedFields;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use Psr\Log\LoggerInterface;

/**
 * Brings the site's database to the current schema, one bounded step at a time (the upgrade page's
 * endpoint: step()) or in one run (`idno:upgrade`: run()). It runs while the legacy application is
 * NOT booted (and also when it is: `idno:upgrade` on a site whose gate is open).
 *
 * A step, in this order:
 *
 *  1. The status (SchemaState, read afresh). UnsupportedServer, LegacyTooOld, RenamedWithoutRecord,
 *     OneXBesideCompletedUpgrade, MongoRetired and Unreachable stop here (nothing is written; status `unsupported`,
 *     `too_old` or `error`, with PublicMessage::forStatus()); Current and
 *     NotApplicable are `current`: a step does nothing for either. run() differs for Current: its
 *     first step that gets the lock re-checks the CORE declared indexes of a current database. Once
 *     a run's step reports `current`, run() goes on to the plugin phase (finishWithPlugins()).
 *  2. The UpgradeLock, without waiting: when another process holds it, the step does nothing and
 *     reports `working` (with `locked` true). It is held around everything below, so two steps never
 *     migrate or create indexes at the same time; LegacyDataCopier::step() works under a lock its
 *     caller holds and leaves it held.
 *  3. When migrations are pending: every pending migration but the newest
 *     (SchemaMigrator::migrate(exceptLast: true)), then the indexes the CORE entity classes (Idno/)
 *     declare, then the newest pending migration, recorded only once a core reconcile pass found
 *     nothing left to create (whichever migration is the newest: a new core declaration ships with an
 *     empty one, and the migration that creates the queue's table is the newest today). So a step killed anywhere
 *     before that leaves a migration pending (the gate stays closed) and the next step carries on. In
 *     a step() the reconcile is bounded: the deadline is checked after each index created, and a step
 *     that stops there reports `working`. run() is not bounded. Plugins never hold the marker: a web
 *     step never reads IdnoPlugins/; run() reconciles each ENABLED plugin on its own LAST of all,
 *     once the schema is current and any copy complete (finishWithPlugins()), skipping, with a
 *     warning, one that fails.
 *  4. For a 1.x database, once nothing is pending and the deadline has not passed (or nothing was
 *     done yet in this step): one LegacyDataCopier::step() until the deadline.
 *  5. The status again: `current`, `working`, or `error` with the copier's error (never dropped: the
 *     error the copier returns, or one progress() finds from the tables alone). A copier step whose
 *     rename waited for the 1.x tables and gave up is `working` with its notice as the message
 *     (PublicMessage::tablesInUse()): never an error, nothing stored.
 *
 * A step never throws: an exception is logged on the `upgrade` channel (the copier logs its own) and
 * reported as `error` with PublicMessage::stopped() as the message (safe to show anyone) and the
 * exception's class and message as `detail` (private: the log and `idno:upgrade` only).
 */
final class UpgradeRunner
{
    /** The time budget of one step, in seconds. */
    public const STEP_SECONDS = 5.0;

    /** run() gives up after this many steps in a row that held the lock and changed nothing. */
    public const STALLED_STEPS = 5;

    public function __construct(
        private readonly SchemaState $state,
        private readonly SchemaMigrator $migrator,
        private readonly IndexedFields $indexes,
        private readonly SchemaCatalog $catalog,
        private readonly LegacyDataCopier $copier,
        private readonly UpgradeLock $lock,
        private readonly EnabledPlugins $plugins,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
        private readonly float $lockWaitSeconds = 60.0,
        private readonly ?string $coreDirectory = null,
        private readonly ?string $pluginsDirectory = null,
    )
    {
    }

    /**
     * One bounded step, until the deadline (microtime(true)).
     *
     * @return array{status: 'current'|'working'|'too_old'|'unsupported'|'error', progress: ?UpgradeProgress, message: ?string, detail: ?string, locked: bool}
     */
    public function step(float $deadline): array
    {
        return $this->advance($deadline, true, false, static function (string $line): void {
        });
    }

    /**
     * Steps until the schema is current or the upgrade stops, reporting what it does to
     * $onProgress, one line at a time: each migration run (`Ran the migration <version>.`), each
     * index created (`Created the index <collection>.<field>.`), each declaration that differs from
     * its index, and after each step of a copy one line per collection whose count moved
     * (UpgradeProgress::line()). The core declarations are re-checked in its first step that gets the
     * lock (a current database too); the enabled plugins' last of all, once the schema is current and
     * any copy complete (the settings then hold the site's plugin list, and a plugin's unique index
     * never makes a 1.x row a reject). The plugin phase never changes the result: when it cannot get
     * the lock within the wait the run is still `current`, with a warning.
     * While another process holds the lock it says so once and tries again every half second, and gives up after the lock wait (60 seconds) with an
     * error; it also gives up after STALLED_STEPS steps in a row that changed nothing. While the
     * rename waits for the 1.x tables (a step's notice, PublicMessage::tablesInUse()) it says so once
     * (`Waiting: ...`) and steps again, and gives up after the same wait with that message.
     *
     * @param callable(string): void $onProgress
     *
     * @return array{status: 'current'|'too_old'|'unsupported'|'error', progress: ?UpgradeProgress, message: ?string, detail: ?string, locked: bool}
     */
    public function run(callable $onProgress): array
    {
        $reconciled = false;
        $waitingSince = null;
        $tablesBusySince = null;
        $stalled = 0;
        $previous = null;
        while (true) {
            $lines = 0;
            $count = static function (string $line) use ($onProgress, &$lines): void {
                ++$lines;
                $onProgress($line);
            };
            $result = $this->advance(microtime(true) + self::STEP_SECONDS, false, !$reconciled, $count);
            if ($result['locked']) {
                if ($waitingSince === null) {
                    $waitingSince = microtime(true);
                    $onProgress('Waiting: another process is upgrading the database (the upgrade page open in a browser, or another idno:upgrade).');
                }
                if (microtime(true) - $waitingSince >= $this->lockWaitSeconds) {
                    return self::result('error', $result['progress'], PublicMessage::lockHeld((int) round($this->lockWaitSeconds))->text);
                }
                usleep(500_000);
                continue;
            }
            $waitingSince = null;
            $reconciled = true;
            if ($result['status'] === 'current') {
                // Last of all, once the schema is current and any copy complete: the settings now
                // hold the site's plugin list, and a plugin's unique index can never make a 1.x row a
                // reject.
                return $this->finishWithPlugins($result, $onProgress);
            }
            if ($result['status'] !== 'working') {
                return $result;
            }
            if ($result['progress']?->notice !== null) {
                // The rename waited for the 1.x tables (a backup, an old process): wait as for the
                // lock, then give up with the reason.
                if ($tablesBusySince === null) {
                    $tablesBusySince = microtime(true);
                    $onProgress('Waiting: ' . $result['progress']->notice);
                }
                if (microtime(true) - $tablesBusySince >= $this->lockWaitSeconds) {
                    return self::result('error', $result['progress'], $result['progress']->notice);
                }
                $stalled = 0;
                $previous = null;
                continue;
            }
            $tablesBusySince = null;
            // Whatever the status says: steps that move nothing (no count, cursor or phase, no
            // migration or index) would loop for ever.
            $state = serialize([$result['progress']?->collections, $result['progress']?->cursors, $result['progress']?->phase]);
            $stalled = $lines === 0 && $state === $previous ? $stalled + 1 : 0;
            $previous = $state;
            if ($stalled >= self::STALLED_STEPS) {
                return self::result('error', $result['progress'], PublicMessage::noProgress(self::STALLED_STEPS)->text);
            }
        }
    }

    /**
     * run()'s last part: under the lock (waiting for it as run() does), the declared indexes of each
     * enabled plugin (reconcilePlugins()); the result stays `current` whatever a plugin does, and
     * when the lock is not got within the wait (one warning line, one log line).
     *
     * @param array{status: 'current', progress: ?UpgradeProgress, message: ?string, detail: ?string, locked: bool} $result
     * @param callable(string): void                                                                                $report
     *
     * @return array{status: 'current', progress: ?UpgradeProgress, message: ?string, detail: ?string, locked: bool}
     */
    private function finishWithPlugins(array $result, callable $report): array
    {
        $waitingSince = null;
        while (!$this->lock->acquire()) {
            if ($waitingSince === null) {
                $waitingSince = microtime(true);
                $report('Waiting: another process is upgrading the database (the upgrade page open in a browser, or another idno:upgrade).');
            }
            if (microtime(true) - $waitingSince >= $this->lockWaitSeconds) {
                // The schema is current: the plugin phase never turns that into a failure.
                $this->logger->warning('The indexes of the enabled plugins were not checked: another upgrade process held the lock for ' . (int) round($this->lockWaitSeconds) . ' seconds.');
                $report('Warning: the indexes of the enabled plugins were not checked, because another upgrade process holds the lock (the site works without them). Run php bin/console idno:upgrade again when it has finished.');

                return $result;
            }
            usleep(500_000);
        }
        try {
            $this->reconcilePlugins($report);
        } finally {
            try {
                $this->lock->release();
            } catch (\Throwable) {
                // Nothing to add: the plugin phase never fails the run.
            }
        }

        return $result;
    }

    /**
     * @param bool                   $bounded   reconcile only until the deadline (a web step)
     * @param bool                   $reconcile reconcile the core declarations even when nothing is pending
     * @param callable(string): void $report
     *
     * @return array{status: 'current'|'working'|'too_old'|'unsupported'|'error', progress: ?UpgradeProgress, message: ?string, detail: ?string, locked: bool}
     */
    private function advance(float $deadline, bool $bounded, bool $reconcile, callable $report): array
    {
        $copying = false;
        try {
            $status = $this->state->status();
            // A current database is re-checked (the core indexes) when run() asks for it.
            $answer = $status === SchemaStatus::Current && $reconcile ? null : $this->answer($status);
            if ($answer !== null) {
                return $answer;
            }

            if (!$this->lock->acquire()) {
                return self::result('working', $this->progressOrNull($status), null, null, true);
            }
            try {
                $worked = false;
                if ($this->migrator->pending() !== []) {
                    $worked = true;
                    $this->migrated($this->migrator->migrate(exceptLast: true), $report);
                    if (!$this->reconcileCore($bounded ? $deadline : null, $report)) {
                        return self::result('working', $this->progressOrNull($status));
                    }
                    $this->migrated($this->migrator->migrate(), $report);
                } elseif ($reconcile) {
                    $this->reconcileCore(null, $report);
                }
                if ($status === SchemaStatus::Legacy && (!$worked || microtime(true) < $deadline)) {
                    $before = $this->copier->progress();
                    if (!$before->complete) {
                        $copying = true;
                        $after = $this->copier->step($deadline);
                        $copying = false;
                        $this->catalog->forget();
                        $this->reportCopy($before, $after, $report);
                        if ($after->error !== null) {
                            return self::result('error', $after, $after->error, $after->errorDetail);
                        }
                        if ($after->notice !== null) {
                            // Not an error: the next step tries again (the rename waited for the 1.x tables).
                            return self::result('working', $after, $after->notice);
                        }
                    }
                }
            } finally {
                try {
                    $this->lock->release();
                } catch (\Throwable) {
                    // The failure that ended the step is what matters (a lost connection releases the lock).
                }
            }

            $now = $this->state->status();

            return $this->answer($now) ?? ($now === SchemaStatus::Legacy ? $this->legacyResult() : self::result('working'));
        } catch (\Throwable $e) {
            $detail = LegacyDataCopier::clean($e::class . ': ' . $e->getMessage());
            if (!$copying) {
                // The copier logs its own failures (and stores them as its error).
                $this->logger->error('The upgrade step failed: ' . $detail);
            }
            $progress = null;
            if ($copying) {
                try {
                    $progress = $this->copier->progress();
                } catch (\Throwable) {
                    // No progress to show: the database failed again.
                }
            }

            return self::result('error', $progress, PublicMessage::stopped()->text, $progress?->errorDetail ?? $detail);
        }
    }

    /**
     * What a step answers for a status without doing anything, or null when the upgrade advances by
     * steps in it (SchemaStatus::advancesBySteps()). The message is PublicMessage::forStatus().
     *
     * @return array{status: 'current'|'too_old'|'unsupported'|'error', progress: null, message: ?string, detail: null, locked: false}|null
     */
    private function answer(SchemaStatus $status): ?array
    {
        $message = PublicMessage::forStatus($status, $status === SchemaStatus::UnsupportedServer ? $this->state->refusal() : null)?->text;

        return match ($status) {
            SchemaStatus::Current, SchemaStatus::NotApplicable => self::result('current'),
            SchemaStatus::UnsupportedServer => self::result('unsupported', null, $message),
            SchemaStatus::LegacyTooOld => self::result('too_old', null, $message),
            SchemaStatus::RenamedWithoutRecord, SchemaStatus::OneXBesideCompletedUpgrade, SchemaStatus::MongoRetired, SchemaStatus::Unreachable => self::result('error', null, $message),
            SchemaStatus::Empty, SchemaStatus::PendingMigrations, SchemaStatus::Legacy => null,
        };
    }

    /**
     * @return array{status: 'working'|'error', progress: ?UpgradeProgress, message: ?string, detail: ?string, locked: bool}
     */
    private function legacyResult(): array
    {
        $progress = $this->copier->progress();
        if ($progress->error !== null) {
            return self::result('error', $progress, $progress->error, $progress->errorDetail);
        }

        return self::result('working', $progress);
    }

    /**
     * The copy's progress for a 1.x database, or null (none, or it cannot be read just now: another
     * process holds the lock and may be renaming the tables).
     */
    private function progressOrNull(SchemaStatus $status): ?UpgradeProgress
    {
        if ($status !== SchemaStatus::Legacy) {
            return null;
        }
        try {
            return $this->copier->progress();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<string>           $versions
     * @param callable(string): void $report
     */
    private function migrated(array $versions, callable $report): void
    {
        $this->catalog->forget();
        foreach ($versions as $version) {
            $report('Ran the migration ' . $version . '.');
        }
    }

    /**
     * Creates the declared field indexes of the core classes (Idno/), until the deadline (null: all
     * of them): true when nothing is left to create. A failure is thrown (the marker migration then
     * stays pending, and the gate closed).
     *
     * @param callable(string): void $report
     */
    private function reconcileCore(?float $deadline, callable $report): bool
    {
        $result = $this->indexes->reconcileDirectoryUntil($deadline, $this->coreDirectory ?? $this->projectDir . '/Idno');
        $this->catalog->forget();
        $this->reportIndexes($result['created'], $report);

        return $result['complete'];
    }

    /**
     * Creates the declared field indexes of each ENABLED plugin (EnabledPlugins) whose directory is
     * there, one plugin at a time, unbounded (idno:upgrade only; a web step never reads IdnoPlugins/).
     * A plugin whose reconcile throws (an invalid declaration, a unique index over duplicate values,
     * any DDL error) is skipped with one warning line and one log line (with the detail): it never
     * stops the upgrade or keeps the site closed. A plugin directory that is there but not enabled is
     * never read.
     *
     * @param callable(string): void $report
     */
    private function reconcilePlugins(callable $report): void
    {
        $root = $this->pluginsDirectory ?? $this->projectDir . '/IdnoPlugins';
        try {
            $names = $this->plugins->names();
        } catch (\Throwable $e) {
            $this->logger->warning('The enabled plugins could not be read: ' . LegacyDataCopier::clean($e::class . ': ' . $e->getMessage()));
            $report('Warning: the enabled plugins could not be read from the settings, so no plugin\'s indexes were created (the site works without them). The server\'s error log has the details.');

            return;
        }
        foreach ($names as $plugin) {
            $directory = $root . '/' . $plugin;
            if (!is_dir($directory)) {
                continue;
            }
            try {
                $created = $this->indexes->reconcileDirectory($directory);
                $this->reportIndexes($created, $report);
            } catch (\Throwable $e) {
                $this->logger->warning('The indexes of the plugin ' . $plugin . ' could not be created: ' . LegacyDataCopier::clean($e::class . ': ' . $e->getMessage()));
                $report('Warning: the indexes the plugin ' . $plugin . ' declares could not be created, so it was skipped (the site works without them). The server\'s error log has the details.');
            } finally {
                $this->catalog->forget();
            }
        }
    }

    /**
     * @param list<string>           $created
     * @param callable(string): void $report
     */
    private function reportIndexes(array $created, callable $report): void
    {
        foreach ($created as $index) {
            $report('Created the index ' . $index . '.');
        }
        foreach ($this->indexes->conflicts() as $conflict) {
            $report($conflict);
        }
    }

    /**
     * @param callable(string): void $report
     */
    private function reportCopy(UpgradeProgress $before, UpgradeProgress $after, callable $report): void
    {
        foreach ($after->collections as $collection => $counts) {
            $was = $before->collections[$collection] ?? ['copied' => 0, 'rejected' => 0, 'source' => 0];
            if ($counts['copied'] + $counts['rejected'] !== $was['copied'] + $was['rejected'] || $counts['source'] !== $was['source']) {
                $report(UpgradeProgress::line($collection, $counts));
            }
        }
        if ($after->phase !== $before->phase && $after->phase === UpgradeProgress::VERIFYING) {
            $report('Renamed the 1.x tables legacy_<name>; checking every 1.x row against the copy.');
        }
        if ($after->complete) {
            $report('The 1.x data is copied.');
        }
    }

    /**
     * @param 'current'|'working'|'too_old'|'unsupported'|'error' $status
     *
     * @return array{status: 'current'|'working'|'too_old'|'unsupported'|'error', progress: ?UpgradeProgress, message: ?string, detail: ?string, locked: bool}
     */
    private static function result(string $status, ?UpgradeProgress $progress = null, ?string $message = null, ?string $detail = null, bool $locked = false): array
    {
        return ['status' => $status, 'progress' => $progress, 'message' => $message, 'detail' => $detail, 'locked' => $locked];
    }
}
