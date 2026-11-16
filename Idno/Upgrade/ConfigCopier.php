<?php

declare(strict_types=1);

namespace Idno\Upgrade;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\SettingsRecordStore;

/**
 * Computes the settings (`idno_settings`) and the config rejects from a 1.x config table that is
 * there, inside the caller's transaction (LegacyDataCopier: once while copying, and again from `legacy_config` after
 * the rename when its rows are not the ones used).
 *
 * The newest row (the 1.x `created` column, then the greatest uuid) whose contents are a document
 * SettingsRecordStore::rowsOf() can hold becomes the settings, its rows written directly (no
 * defaults: the application is not running) and read back to be verified. Every other row is a
 * reject: `superseded configuration` when it is valid, else its reason (`cannot be the settings:
 * ...` for a key the settings table refuses). With none usable the settings stay empty.
 *
 * A config table holds a handful of rows; one with more than MAX_ROWS stops the upgrade with a message.
 *
 * A plain helper of LegacyDataCopier, built with new: not a container service.
 */
final class ConfigCopier
{
    public const MAX_ROWS = 1000;

    public function __construct(
        private readonly Connection $connection,
        private readonly Dialect $dialect,
        private readonly RowWriter $writer,
    )
    {
    }

    /**
     * Replaces the settings and the config rejects with what the table's rows make, inside the
     * caller's transaction. The table is read and the choice made BEFORE anything is deleted, so the
     * settings are only ever replaced by what a readable 1.x config table yields (and, the whole being
     * one transaction, a failure at any point leaves them as they were).
     *
     * @param string $table the 1.x config table (`config` or `legacy_config`)
     *
     * @return array{chosen: ?string, rejected: int, rows: int} the uuid of the row that became the
     *                                                           settings, the rejects, the rows read
     *
     * @throws \LogicException when the table is not there: a caller that has no config table must
     *                         not call this (the settings would be emptied)
     */
    public function copy(LegacySource $source, string $table): array
    {
        if (!$source->has($table)) {
            throw new \LogicException(sprintf('The 1.x config table %s is not there: the settings are not computed without it.', $table));
        }
        // Choose: every row read once, newest first; the reason of each that cannot be the settings.
        $uuids = $this->uuids($source, $table);
        $reasons = [];
        foreach ($uuids as $uuid) {
            $row = $source->row($table, $uuid);
            if ($row === null) {
                continue;
            }
            $reason = DocumentCheck::problem($row['contents']);
            if ($reason === null) {
                $reason = self::settingsProblem($row['contents']);
            }
            if ($reason !== null) {
                $reasons[$uuid] = $reason;
            }
        }

        // Then replace: the settings from the first candidate the database accepts.
        $this->connection->executeStatement('delete from ' . $this->q(SettingsRecordStore::TABLE));
        $this->writer->deleteRejects('config');
        $chosen = null;
        $rejected = 0;
        foreach ($uuids as $uuid) {
            $row = $source->row($table, $uuid);
            if ($row === null) {
                continue;
            }
            $reason = $reasons[$uuid] ?? null;
            if ($reason === null && $chosen === null) {
                $reason = $this->writeSettings($row['contents']);
                if ($reason === null) {
                    $chosen = $row['uuid'];
                }
            } elseif ($reason === null) {
                $reason = 'superseded configuration';
            }
            if ($reason !== null) {
                $this->writer->reject('config', $row['uuid'], $row['id'], $reason, $row['contents']);
                ++$rejected;
            }
        }

        return ['chosen' => $chosen, 'rejected' => $rejected, 'rows' => count($uuids)];
    }

    /**
     * Whether the table's rows are exactly the ones a copy() made the settings and rejects from:
     * every row is the chosen one or a config reject, by exact uuid, and there are as many rows as
     * were copied and rejected.
     */
    public function matches(LegacySource $source, string $table, ?string $chosen, int $copiedAndRejected): bool
    {
        if (!$source->has($table)) {
            throw new \LogicException(sprintf('The 1.x config table %s is not there.', $table));
        }
        $uuids = $this->uuids($source, $table);
        if (count($uuids) !== $copiedAndRejected) {
            return false;
        }
        $rejected = $this->writer->rejectedUuids('config', $uuids);
        foreach ($uuids as $uuid) {
            if ($uuid !== $chosen && !isset($rejected[$uuid])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Why the settings table cannot hold the document (SettingsRecordStore::rowsOf()), or null.
     */
    private static function settingsProblem(string $contents): ?string
    {
        $document = json_decode($contents, true);
        try {
            SettingsRecordStore::rowsOf(is_array($document) ? $document : []);
        } catch (\InvalidArgumentException $e) {
            return RowWriter::reason('cannot be the settings: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * @return list<string> newest first
     */
    private function uuids(LegacySource $source, string $table): array
    {
        $uuids = $source->configUuids($table, self::MAX_ROWS + 1);
        if (count($uuids) > self::MAX_ROWS) {
            throw new \RuntimeException(sprintf('The 1.x table %s holds more than %d rows; a site keeps its configuration in one. Remove the stale ones and continue the upgrade.', $table, self::MAX_ROWS));
        }

        return $uuids;
    }

    /**
     * Writes the document as the settings (under a savepoint) and verifies them: null when written,
     * else why the document cannot be the settings (nothing written).
     */
    private function writeSettings(string $contents): ?string
    {
        $document = json_decode($contents, true);
        try {
            $rows = SettingsRecordStore::rowsOf(is_array($document) ? $document : []);
        } catch (\InvalidArgumentException $e) {
            return RowWriter::reason('cannot be the settings: ' . $e->getMessage());
        }
        unset($document);
        $this->connection->createSavepoint('idno_upgrade_settings');
        try {
            $insert = 'insert into ' . $this->q(SettingsRecordStore::TABLE) . ' (' . implode(', ', array_map($this->q(...), SettingsRecordStore::COLUMNS)) . ') values (?, ?, ?)';
            $position = 0;
            $expected = [];
            foreach ($rows as $name => $json) {
                $this->connection->executeStatement($insert, [(string) $name, $json, (string) $position]);
                $expected[] = [(string) $name, hash('sha256', $json), $position++];
            }
            $stored = [];
            foreach ($this->connection->fetchAllNumeric('select ' . $this->q('name') . ', sha2(' . $this->q('value') . ', 256), ' . $this->q('position') . ' from ' . $this->q(SettingsRecordStore::TABLE) . ' order by ' . $this->q('position')) as [$name, $hash, $at]) {
                $stored[] = [(string) $name, (string) $hash, (int) $at];
            }
            if ($stored !== $expected) {
                throw new \UnexpectedValueException('The settings copied from the 1.x configuration do not match it: the copy was rolled back.');
            }
        } catch (DriverException $e) {
            if (!$this->writer->isRefusal($e)) {
                throw $e;
            }
            $this->connection->rollbackSavepoint('idno_upgrade_settings');

            return RowWriter::reason('the database refused the settings: ' . RowWriter::driverMessage($e));
        }
        $this->connection->releaseSavepoint('idno_upgrade_settings');

        return null;
    }

    private function q(string $name): string
    {
        return $this->dialect->quoteIdentifier($name);
    }
}
