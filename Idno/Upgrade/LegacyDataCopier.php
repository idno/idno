<?php

declare(strict_types=1);

namespace Idno\Upgrade;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\SettingsRecordStore;
use Idno\Storage\TypeMap;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Copies a 1.x database (per collection the tables `<c>`, `<c>_metadata`, `<c>_search`, plus
 * `versions` and `session`) into the new schema, in bounded, resumable steps. MySQL and MariaDB
 * only (no 1.x site runs on SQLite). The new schema must exist (the schema migrations and the
 * declared field indexes); the copier creates no table. It never writes to a 1.x table: it reads
 * them (LegacySource), and its only change to them is ONE RENAME TABLE.
 *
 * The phases, each resumable at any point:
 *
 *  1. Copy: per collection (`entities`, `reader`, `site`), a keyset pass by `uuid` over `<c>`, in
 *     batches of at most BATCH rows and about BATCH_BYTES bytes. Then `config` (ConfigCopier).
 *  2. Rename: when every keyset pass has reached its end, the progress rows are marked ready (the
 *     verify cursor set, in one transaction) and ONE RENAME TABLE moves every table of
 *     LEGACY_TABLES that exists to `legacy_<name>`; from then on no process of the old version can
 *     write the source. When a `legacy_<name>` exists already, nothing is renamed and the error
 *     names it.
 *  3. Verify: a second keyset pass by `uuid` over the now immutable `legacy_<c>`, VERIFY_BATCH
 *     uuids at a time, with its own cursor (`verify_cursor`): the uuids that are neither in
 *     `idno_<c>` nor among the collection's rejects, by EXACT bytes, are copied or rejected by the
 *     same machinery (RowWriter), at most BATCH of them per batch. Then the config is computed
 *     again from `legacy_config` when its rows are not the ones phase 1 used.
 *  4. Complete: `completed_at` is written for every collection.
 *
 * Every batch is ONE transaction that locks the collection's `idno_upgrade_progress` row (reading
 * its cursor from the database), checks that this connection still holds the UpgradeLock, copies,
 * verifies, writes the rejects and moves the counts and the cursor. step() checks the deadline
 * only between batches and always finishes the batch it started; with a deadline already past it
 * does exactly one unit of work (a batch, the config, the rename or the completion). Every
 * statement on a 1.x table is bounded (see LegacySource), except one COUNT(*) per collection when
 * its progress row is made (`source_rows`, for display only).
 *
 * A database in an impossible state (some 1.x tables renamed and some not; renamed while the copy
 * was not finished) stops the upgrade with a stored error that says what was found; nothing else is
 * written. Failures are logged on the logger (the Monolog channel `upgrade`), stored as the error,
 * and thrown. Nothing in the kernel calls this class yet.
 */
final class LegacyDataCopier
{
    public const COLLECTIONS = ['entities', 'reader', 'site', 'config'];

    public const BATCH = 500;

    /**
     * Uuids examined per verify batch: only uuids are read (at most 255 characters each, from the
     * primary key), and each is looked up once in the unique `uuid` index of `idno_<c>` and once in
     * the `(collection, legacy_uuid)` index of the rejects, so 2,000 cost a few hundred KB and a few
     * milliseconds; the rows that must be copied are still at most BATCH per batch.
     */
    public const VERIFY_BATCH = 2000;

    /** The tables of a 1.x database, renamed `legacy_<name>` in phase 2. */
    public const LEGACY_TABLES = [
        'entities', 'entities_metadata', 'entities_search',
        'reader', 'reader_metadata', 'reader_search',
        'site', 'site_metadata', 'site_search',
        'config', 'config_metadata', 'config_search',
        'versions', 'session',
    ];

    /** At most about this many bytes of contents and search text are read per batch (at least one row). */
    public const BATCH_BYTES = 8 * 1024 * 1024;

    /** A batch's bytes take up to this many times their size in memory (the row, the escaped statement, the driver's buffers). */
    private const MEMORY_FACTOR = 6;

    private const DOCUMENT_COLLECTIONS = ['entities', 'reader', 'site'];

    private const PROGRESS = 'idno_upgrade_progress';

    /** The tables the new schema must have before anything is copied. */
    private const REQUIRED_TABLES = ['idno_entities', 'idno_reader', 'idno_site', SettingsRecordStore::TABLE, self::PROGRESS, RowWriter::REJECTS];

    /** The longest text kept in the error column and written to the log, in bytes. */
    private const MAX_MESSAGE_BYTES = 1000;

    /** The verify cursor of a collection whose verify pass starts at its first row. */
    private const VERIFY_FROM_START = '';

    /** The verify cursor of a config that has been verified. */
    private const CONFIG_VERIFIED = '>';

    private readonly RowWriter $writer;

    private readonly ConfigCopier $config;

    public function __construct(
        private readonly Connection $connection,
        private readonly Dialect $dialect,
        TypeMap $types,
        private readonly UpgradeLock $lock,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?int $packetLimit = null,
    )
    {
        $this->writer = new RowWriter($connection, $dialect, $types, $packetLimit);
        $this->config = new ConfigCopier($connection, $dialect, $this->writer);
    }

    /**
     * The 1.x schema version (`versions`, or `legacy_versions` once renamed; label `schema`), or null
     * when there is no such table or row.
     */
    public function legacySchemaVersion(): ?int
    {
        $tables = $this->tables();
        $table = in_array('versions', $tables, true) ? 'versions' : (in_array('legacy_versions', $tables, true) ? 'legacy_versions' : null);
        if ($table === null) {
            return null;
        }
        $value = $this->connection->fetchOne('select ' . $this->q('value') . ' from ' . $this->q($table) . ' where ' . $this->q('label') . ' = ?', ['schema']);

        return is_scalar($value) && ctype_digit((string) $value) ? (int) $value : null;
    }

    /**
     * Works until the deadline (microtime(true)) or completion; see the class comment.
     *
     * @throws \Throwable when the database fails, a copy does not match its source, or a row is too
     *                    large for max_allowed_packet or memory_limit; the error is stored and logged first
     */
    public function step(float $deadline): UpgradeProgress
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            throw new \LogicException('A 1.x database is on MySQL or MariaDB: the copier supports nothing else.');
        }
        // A caller that holds the lock already keeps it: the step releases only what it took.
        $heldBefore = $this->lock->holds();
        if (!$heldBefore && !$this->lock->acquire()) {
            return $this->progress();
        }
        try {
            return $this->work($deadline);
        } catch (\Throwable $e) {
            $this->fail($e);

            throw $e;
        } finally {
            if (!$heldBefore) {
                try {
                    $this->lock->release();
                } catch (\Throwable) {
                    // The failure that ended the step is what matters (a lost connection releases the lock).
                }
            }
        }
    }

    /**
     * Where the upgrade stands, read from the database. It writes nothing and reads no 1.x table.
     */
    public function progress(): UpgradeProgress
    {
        $tables = $this->tables();
        $rows = in_array(self::PROGRESS, $tables, true) ? $this->progressRows() : [];
        $collections = [];
        $error = null;
        foreach (self::COLLECTIONS as $collection) {
            $row = $rows[$collection] ?? null;
            if ($row === null) {
                $collections[$collection] = ['source' => 0, 'copied' => 0, 'rejected' => 0, 'done' => false];
                continue;
            }
            $source = (int) $row['source_rows'];
            $copied = (int) $row['copied'];
            $rejected = (int) $row['rejected'];
            $collections[$collection] = [
                'source' => $source,
                'copied' => $copied,
                'rejected' => $rejected,
                'done' => $row['completed_at'] !== null || ($copied + $rejected >= $source && ($collection !== 'config' || $row['position'] !== null)),
            ];
            $error ??= $row['error'] === null ? null : (string) $row['error'];
        }
        $complete = self::completed($rows);
        $noLegacyDatabase = $rows === [] && !self::hasLegacyTables($tables);
        $phase = match (true) {
            $complete => UpgradeProgress::COMPLETE,
            $rows === [] => UpgradeProgress::NOT_STARTED,
            self::ready($rows) && array_intersect(self::LEGACY_TABLES, $tables) === [] => UpgradeProgress::VERIFYING,
            default => UpgradeProgress::COPYING,
        };

        return new UpgradeProgress($collections, $complete, $error, $phase, $noLegacyDatabase);
    }

    /**
     * Text for the log and the error column: control characters replaced, valid UTF-8, bounded.
     */
    public static function clean(string $message): string
    {
        $message = (string) preg_replace('/[\x00-\x1f\x7f]/', ' ', $message);
        if (!mb_check_encoding($message, 'UTF-8')) {
            $message = mb_convert_encoding($message, 'UTF-8', 'UTF-8');
        }

        return mb_strcut($message, 0, self::MAX_MESSAGE_BYTES, 'UTF-8');
    }

    // ------------------------------------------------------------------ the step

    private function work(float $deadline): UpgradeProgress
    {
        $source = LegacySource::read($this->connection, $this->dialect);
        $missing = array_diff(self::REQUIRED_TABLES, $source->tables());
        if ($missing !== []) {
            throw new \LogicException(sprintf('The new schema is not there (%s missing): run the schema migrations before copying the 1.x tables.', implode(', ', $missing)));
        }
        $rows = $this->progressRows();
        if (self::completed($rows)) {
            return $this->progress();
        }
        if ($rows === [] && !self::hasLegacyTables($source->tables())) {
            // No 1.x database here and no upgrade begun: nothing is written at all.
            return $this->progress();
        }
        $phase = $this->phase($source, $rows);
        if (!in_array($phase, ['copy', 'rename', 'verify'], true)) {
            // An impossible state: say what was found, touch nothing else.
            $this->logger->error('The upgrade of the 1.x database stopped: ' . $phase);
            $this->storeError($phase);
            $progress = $this->progress();

            return new UpgradeProgress($progress->collections, $progress->complete, $progress->error ?? $phase, $progress->phase, $progress->noLegacyDatabase);
        }
        if ($phase === 'copy') {
            $this->initialise($source, $rows);
        }
        $this->connection->executeStatement('update ' . $this->q(self::PROGRESS) . ' set ' . $this->q('error') . ' = null where ' . $this->q('error') . ' is not null');
        $this->writer->startStep();

        $worked = false;
        $more = static function () use (&$worked, $deadline): bool {
            return !$worked || microtime(true) < $deadline;
        };

        if ($phase === 'copy') {
            foreach (self::DOCUMENT_COLLECTIONS as $collection) {
                while (true) {
                    if (!$more()) {
                        return $this->progress();
                    }
                    $handled = $this->copyBatch($collection, $source);
                    if ($handled === null) {
                        return $this->progress();
                    }
                    if ($handled === 0) {
                        break;
                    }
                    $worked = true;
                }
            }
            if ($this->progressRows()['config']['position'] === null) {
                if (!$more()) {
                    return $this->progress();
                }
                if (!$this->copyConfig($source)) {
                    return $this->progress();
                }
                $worked = true;
            }
            if (!$more()) {
                return $this->progress();
            }
            if (!$this->renameIsPossible($source) || !$this->markReady()) {
                return $this->progress();
            }
            $phase = 'rename';
        }

        if ($phase === 'rename') {
            if (!$this->renameIsPossible($source) || !$this->lock->holds()) {
                return $this->progress();
            }
            $names = [];
            foreach (self::LEGACY_TABLES as $table) {
                if ($source->has($table)) {
                    $names[$table] = 'legacy_' . $table;
                }
            }
            $source->rename($names);
            $worked = true;
            $source = LegacySource::read($this->connection, $this->dialect);
        }

        foreach (self::DOCUMENT_COLLECTIONS as $collection) {
            while (true) {
                if (!$more()) {
                    return $this->progress();
                }
                $handled = $this->verifyBatch($collection, $source);
                if ($handled === null) {
                    return $this->progress();
                }
                if ($handled === 0) {
                    break;
                }
                $worked = true;
            }
        }
        if ($this->progressRows()['config']['verify_cursor'] !== self::CONFIG_VERIFIED) {
            if (!$more()) {
                return $this->progress();
            }
            if (!$this->verifyConfig($source)) {
                return $this->progress();
            }
            $worked = true;
        }
        if (!$more()) {
            return $this->progress();
        }
        $this->finish();

        return $this->progress();
    }

    /**
     * What the tables and progress rows say: 'copy' (phase 1), 'rename' (ready, the 1.x tables still
     * under their names), 'verify' (renamed, or no 1.x table at all), or, for an impossible state,
     * the error that describes it.
     *
     * @param array<string, array<string, mixed>> $rows
     */
    private function phase(LegacySource $source, array $rows): string
    {
        $old = array_values(array_filter(self::LEGACY_TABLES, $source->has(...)));
        $renamed = array_values(array_filter(self::LEGACY_TABLES, static fn (string $table): bool => $source->has('legacy_' . $table)));
        $marked = count(array_filter($rows, static fn (array $row): bool => $row['verify_cursor'] !== null));
        if ($marked > 0 && !self::ready($rows)) {
            return 'The upgrade\'s progress rows are inconsistent: some collections are marked ready for the rename and some are not. Nothing was changed.';
        }
        $partly = array_values(array_diff($renamed, $old));
        if ($old !== [] && $partly !== []) {
            return sprintf(
                'Some 1.x tables are renamed (%s) and some are not (%s): the upgrade renames all of them at once, so this database was changed by something else. Nothing was changed.',
                implode(', ', array_map(static fn (string $table): string => 'legacy_' . $table, $partly)),
                implode(', ', $old),
            );
        }
        if ($old === [] && $renamed !== [] && !self::ready($rows)) {
            return sprintf(
                'The 1.x tables are renamed (%s) but the copy had not finished: they were renamed by something else. Nothing was changed.',
                implode(', ', array_map(static fn (string $table): string => 'legacy_' . $table, $renamed)),
            );
        }
        $phase = !self::ready($rows) ? 'copy' : ($old === [] ? 'verify' : 'rename');
        // A collection whose table existed when its progress row was made (`source_existed`) must
        // still have it, under the name this phase reads; one that never existed is no source.
        $missing = [];
        foreach ($rows as $collection => $row) {
            if ((int) $row['source_existed'] === 1) {
                $table = $phase === 'verify' ? 'legacy_' . $collection : (string) $collection;
                if (!$source->has($table)) {
                    $missing[] = $table;
                }
            }
        }
        if ($missing !== []) {
            return sprintf(
                'The upgrade began with 1.x tables that are no longer there: %s. They must be restored before the upgrade can go on. Nothing was changed.',
                implode(', ', $missing),
            );
        }

        return $phase;
    }

    /**
     * Makes the progress row of each collection that has none (`source_rows` counted now: the one
     * full count of a 1.x table; `source_existed` records whether the collection's 1.x table exists,
     * which tells a collection this site never had from one whose table vanished later).
     *
     * @param array<string, array<string, mixed>> $rows
     */
    private function initialise(LegacySource $source, array $rows): void
    {
        foreach (self::COLLECTIONS as $collection) {
            if (isset($rows[$collection])) {
                continue;
            }
            $this->connection->executeStatement(
                'insert into ' . $this->q(self::PROGRESS) . ' (' . $this->columns(['collection', 'source_rows', 'source_existed', 'copied', 'rejected']) . ') values (?, ?, ?, ?, ?)',
                [$collection, (string) $source->count($collection), $source->has($collection) ? '1' : '0', '0', '0'],
            );
        }
    }

    // ------------------------------------------------------------------ phase 1

    /**
     * One batch of the keyset pass over `<c>`: the number of rows it handled (0: the pass reached
     * the end), or null when this connection no longer holds the lock (nothing written).
     */
    private function copyBatch(string $collection, LegacySource $source): ?int
    {
        return $this->transaction($collection, function (array $progress) use ($collection, $source): int {
            if (!$progress['source_existed']) {
                return 0;
            }
            $next = $source->nextRows($collection, $progress['position'], self::BATCH);
            if ($next === []) {
                return 0;
            }
            $picked = $this->pick($collection, $source, $collection, $next);
            [$copied, $rejected] = $this->copyRows($collection, $source, $collection, $picked);
            $this->update($collection, [
                'copied = copied + ?' => (string) $copied,
                'rejected = rejected + ?' => (string) $rejected,
                'position = ?' => end($picked)[0],
            ]);

            return count($picked);
        });
    }

    private function copyConfig(LegacySource $source): bool
    {
        return $this->transaction('config', function (array $progress) use ($source): int {
            if (!$progress['source_existed']) {
                // This site has no config table: the settings are left as they are.
                $this->update('config', ['position = ?' => '']);

                return 1;
            }
            $result = $this->config->copy($source, 'config');
            $this->update('config', [
                'copied = ?' => $result['chosen'] === null ? '0' : '1',
                'rejected = ?' => (string) $result['rejected'],
                'position = ?' => $result['chosen'] ?? '',
            ]);

            return 1;
        }) !== null;
    }

    // ------------------------------------------------------------------ phase 2

    /**
     * Whether the rename can be made: no `legacy_<name>` exists for a table still under its 1.x
     * name. When one does, the error names it.
     */
    private function renameIsPossible(LegacySource $source): bool
    {
        $blocking = [];
        foreach (self::LEGACY_TABLES as $table) {
            if ($source->has($table) && $source->has('legacy_' . $table)) {
                $blocking[] = $table . ' and legacy_' . $table;
            }
        }
        if ($blocking === []) {
            return true;
        }
        $this->storeError(sprintf(
            'The 1.x tables cannot be renamed: %s both exist. Nothing was changed. Someone who knows what the legacy_ %s must check %s and move or remove %s; the upgrade then goes on.',
            implode(', ', $blocking),
            count($blocking) === 1 ? 'table holds' : 'tables hold',
            count($blocking) === 1 ? 'it' : 'them',
            count($blocking) === 1 ? 'it' : 'them',
        ));

        return false;
    }

    /**
     * Marks every collection ready for the rename (its verify cursor at the start), in one
     * transaction: false when this connection no longer holds the lock.
     */
    private function markReady(): bool
    {
        $this->connection->beginTransaction();
        try {
            foreach (self::COLLECTIONS as $collection) {
                if ($this->lockProgressRow($collection) === null) {
                    $this->connection->rollBack();

                    return false;
                }
                $this->update($collection, ['verify_cursor = ?' => self::VERIFY_FROM_START]);
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }

        return true;
    }

    // ------------------------------------------------------------------ phase 3

    /**
     * One batch of the verify pass over `legacy_<c>`: the number of uuids it examined (0: the pass
     * reached the end), or null when this connection no longer holds the lock.
     */
    private function verifyBatch(string $collection, LegacySource $source): ?int
    {
        $table = 'legacy_' . $collection;

        return $this->transaction($collection, function (array $progress) use ($collection, $source, $table): int {
            if (!$progress['source_existed']) {
                return 0;
            }
            $cursor = $progress['verify_cursor'] ?? throw new \LogicException('The ' . $collection . ' collection has no verify cursor.');
            $uuids = $source->uuidsAfter($table, $cursor === self::VERIFY_FROM_START ? null : substr($cursor, 1), self::VERIFY_BATCH);
            if ($uuids === []) {
                return 0;
            }
            $accounted = $this->writer->copiedUuids($collection, $uuids) + $this->writer->rejectedUuids($collection, $uuids);
            $missing = [];
            $last = null;
            foreach ($uuids as $uuid) {
                if (!isset($accounted[$uuid])) {
                    if (count($missing) === self::BATCH) {
                        break;
                    }
                    $missing[] = $uuid;
                }
                $last = $uuid;
            }
            $copied = 0;
            $rejected = 0;
            if ($missing !== []) {
                $picked = $this->pick($collection, $source, $table, $source->sizes($table, $missing));
                if ($picked !== [] && count($picked) < count($missing)) {
                    // Not past a row that is not copied yet.
                    $last = end($picked)[0];
                }
                [$copied, $rejected] = $this->copyRows($collection, $source, $table, $picked);
            }
            $this->update($collection, [
                'copied = copied + ?' => (string) $copied,
                'rejected = rejected + ?' => (string) $rejected,
                'verify_cursor = ?' => '>' . $last,
            ]);

            return count($uuids);
        });
    }

    /**
     * Computes the config again from `legacy_config` when its rows are not the ones phase 1 used,
     * and marks it verified, in one transaction: false when the lock is no longer held.
     */
    private function verifyConfig(LegacySource $source): bool
    {
        return $this->transaction('config', function (array $progress) use ($source): int {
            $updates = ['verify_cursor = ?' => self::CONFIG_VERIFIED];
            $chosen = $progress['copied'] === 1 ? $progress['position'] : null;
            if ($progress['source_existed'] && !$this->config->matches($source, 'legacy_config', $chosen, $progress['copied'] + $progress['rejected'])) {
                $result = $this->config->copy($source, 'legacy_config');
                $updates += [
                    'copied = ?' => $result['chosen'] === null ? '0' : '1',
                    'rejected = ?' => (string) $result['rejected'],
                    'position = ?' => $result['chosen'] ?? '',
                ];
            }
            $this->update('config', $updates);

            return 1;
        }) !== null;
    }

    // ------------------------------------------------------------------ phase 4

    private function finish(): void
    {
        $this->connection->beginTransaction();
        try {
            foreach (self::COLLECTIONS as $collection) {
                if ($this->lockProgressRow($collection) === null) {
                    $this->connection->rollBack();

                    return;
                }
                $this->update($collection, ['completed_at = ?' => (string) time()]);
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    // ------------------------------------------------------------------ batches

    /**
     * Runs $work in one transaction that has locked the collection's progress row and checked the
     * lock: what $work returns (it commits; 0 rolls back, nothing having been written), or null when
     * the lock is not held.
     *
     * @param \Closure(array{source_existed: bool, copied: int, rejected: int, position: ?string, verify_cursor: ?string}): int $work
     */
    private function transaction(string $collection, \Closure $work): ?int
    {
        $this->connection->beginTransaction();
        try {
            $progress = $this->lockProgressRow($collection);
            if ($progress === null) {
                $this->connection->rollBack();

                return null;
            }
            $result = $work($progress);
            if ($result === 0) {
                $this->connection->rollBack();
            } else {
                $this->connection->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    /**
     * Locks the collection's progress row for this transaction and checks that this connection
     * holds the upgrade lock: the row as it is in the database, or null when the lock is not held.
     *
     * @return array{source_existed: bool, copied: int, rejected: int, position: ?string, verify_cursor: ?string}|null
     */
    private function lockProgressRow(string $collection): ?array
    {
        $row = $this->connection->fetchAssociative(
            'select ' . $this->columns(['source_existed', 'copied', 'rejected', 'position', 'verify_cursor']) . ' from ' . $this->q(self::PROGRESS) . ' where ' . $this->q('collection') . ' = ? for update',
            [$collection],
        );
        if ($row === false) {
            throw new \LogicException('The upgrade has no progress row for ' . $collection . '.');
        }
        if (!$this->lock->holds()) {
            return null;
        }

        return [
            'source_existed' => (int) $row['source_existed'] === 1,
            'copied' => (int) $row['copied'],
            'rejected' => (int) $row['rejected'],
            'position' => $row['position'] === null ? null : (string) $row['position'],
            'verify_cursor' => $row['verify_cursor'] === null ? null : (string) $row['verify_cursor'],
        ];
    }

    /**
     * The leading rows of $candidates (uuid, _id, contents size, in uuid order) that make one batch:
     * their contents and search text together at most the byte cap (batchBytes()), and at least
     * one row.
     *
     * @param list<array{0: string, 1: string, 2: int}> $candidates
     *
     * @return list<array{0: string, 1: string, 2: int}>
     *
     * @throws \RuntimeException when the first row alone is larger than the memory left allows
     */
    private function pick(string $collection, LegacySource $source, string $table, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }
        $searchBytes = $source->searchSizes($table . '_search', array_column($candidates, 1));
        $cap = $this->batchBytes();
        $picked = [];
        $bytes = 0;
        foreach ($candidates as $candidate) {
            $size = $candidate[2] + ($searchBytes[$candidate[1]] ?? 0);
            if ($picked === []) {
                $available = self::memoryAvailable();
                if ($available !== null && $size * self::MEMORY_FACTOR > $available) {
                    throw new \RuntimeException(sprintf(
                        'The %s record %s is %d bytes, more than the memory left to PHP allows to copy (%d bytes free): raise memory_limit and continue the upgrade.',
                        $collection,
                        self::clean($candidate[0]),
                        $size,
                        $available,
                    ));
                }
            } elseif ($bytes + $size > $cap) {
                break;
            }
            $picked[] = $candidate;
            $bytes += $size;
        }

        return $picked;
    }

    /**
     * Copies the picked rows of $table (`<c>` or `legacy_<c>`) with their search text: [copied, rejected].
     *
     * @param list<array{0: string, 1: string, 2: int}> $picked
     *
     * @return array{int, int}
     */
    private function copyRows(string $collection, LegacySource $source, string $table, array $picked): array
    {
        $rows = $source->rows($table, array_column($picked, 0));
        $searches = $source->searchTexts($table . '_search', array_column($rows, 'id'));

        return $this->writer->copy($collection, $rows, $searches);
    }

    /**
     * BATCH_BYTES, or less when memory_limit leaves less than MEMORY_FACTOR times that free.
     */
    private function batchBytes(): int
    {
        $available = self::memoryAvailable();

        return $available === null ? self::BATCH_BYTES : max(1, min(self::BATCH_BYTES, intdiv($available, self::MEMORY_FACTOR)));
    }

    /**
     * The bytes PHP may still allocate, or null when memory_limit is unlimited.
     */
    private static function memoryAvailable(): ?int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return null;
        }
        $bytes = (int) $limit;
        $bytes *= match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return max(0, $bytes - memory_get_usage(true));
    }

    /**
     * Sets columns of the collection's progress row ("column = ?" => value; a column name is
     * written as it is: only the literal names above).
     *
     * @param array<string, string> $assignments
     */
    private function update(string $collection, array $assignments): void
    {
        $this->connection->executeStatement(
            'update ' . $this->q(self::PROGRESS) . ' set ' . implode(', ', array_keys($assignments)) . ' where ' . $this->q('collection') . ' = ?',
            [...array_values($assignments), $collection],
        );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn('select table_name from information_schema.tables where table_schema = database()'));
    }

    /**
     * @return array<string, array<string, mixed>> by collection
     */
    private function progressRows(): array
    {
        $rows = [];
        foreach ($this->connection->fetchAllAssociative('select * from ' . $this->q(self::PROGRESS)) as $row) {
            $rows[(string) $row['collection']] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     */
    private static function completed(array $rows): bool
    {
        foreach (self::COLLECTIONS as $collection) {
            if (($rows[$collection]['completed_at'] ?? null) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether any table of a 1.x database is there, under its own name or renamed `legacy_<name>`.
     *
     * @param list<string> $tables
     */
    private static function hasLegacyTables(array $tables): bool
    {
        foreach (self::LEGACY_TABLES as $table) {
            if (in_array($table, $tables, true) || in_array('legacy_' . $table, $tables, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every collection is marked ready for the rename (its verify cursor is set).
     *
     * @param array<string, array<string, mixed>> $rows
     */
    private static function ready(array $rows): bool
    {
        foreach (self::COLLECTIONS as $collection) {
            if (!isset($rows[$collection]) || $rows[$collection]['verify_cursor'] === null) {
                return false;
            }
        }

        return true;
    }

    private function fail(\Throwable $exception): void
    {
        $message = self::clean($exception->getMessage());
        $this->logger->error('The upgrade of the 1.x database stopped: ' . $exception::class . ': ' . $message);
        $this->storeError($message);
    }

    private function storeError(string $message): void
    {
        try {
            $this->connection->executeStatement('update ' . $this->q(self::PROGRESS) . ' set ' . $this->q('error') . ' = ?', [self::clean($message)]);
        } catch (\Throwable $e) {
            $this->logger->error('The upgrade could not store its error: ' . self::clean($e->getMessage()));
        }
    }

    private function rollBack(): void
    {
        if (!$this->connection->isTransactionActive()) {
            return;
        }
        try {
            $this->connection->rollBack();
        } catch (\Throwable) {
            // The original error is what the caller needs (a lost connection fails both).
        }
    }

    private function q(string $name): string
    {
        return $this->dialect->quoteIdentifier($name);
    }

    /**
     * @param list<string> $columns
     */
    private function columns(array $columns): string
    {
        return implode(', ', array_map($this->q(...), $columns));
    }
}
