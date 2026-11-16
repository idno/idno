<?php

declare(strict_types=1);

namespace Idno\Upgrade;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Idno\Config\BootConfig;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\SchemaCatalog;

/**
 * What the upgrade of a 1.x database left behind, for the site's admin (the page
 * `/admin/legacytables` and `idno:upgrade --drop-legacy`):
 *
 *  - the legacy tables: the 1.x tables the upgrade renamed `legacy_<name>` (each name of
 *    LegacyDataCopier::LEGACY_TABLES), kept until the admin deletes them; names() reads them from
 *    the SchemaCatalog (the request's one catalog statement, already read by the schema gate),
 *    present() adds each one's row count (one COUNT(*) per table);
 *  - the rejects: the rows the upgrade could not carry (`idno_upgrade_rejects`), listed by
 *    collection, 1.x uuid and reason (never their contents), and never deleted;
 *  - the reappeared tables: 1.x tables an old Idno process created again after the upgrade completed
 *    (LegacyDataCopier::reappearedTables()). Nothing reads them; they are listed only, never dropped
 *    here.
 *
 * drop() deletes the legacy tables, and only those: exactly `legacy_` + a name of LEGACY_TABLES,
 * only once the upgrade is complete. The 1.x child tables (`<c>_metadata`, `<c>_search`, whose
 * foreign keys reference `<c>`) are dropped before their parent, one `DROP TABLE IF EXISTS` each,
 * so foreign-key checks stay on; a failure part-way leaves the rest, and drop() again finishes.
 *
 * On a site whose database is not SQL (MongoDB) there is never anything: nothing is fetched (no
 * connection, no catalog), every list is empty, drop() returns 0. Each closure returns the service
 * it names, fetched only when needed.
 */
final class LegacyTables
{
    /** The rejects of the upgrade (RowWriter writes them). */
    public const REJECTS_TABLE = RowWriter::REJECTS;

    /** The most rejects one call to rejects() returns. */
    public const MAX_LIMIT = 1000;

    /**
     * @param \Closure(): Connection       $connection
     * @param \Closure(): SchemaCatalog    $catalog
     * @param \Closure(): LegacyDataCopier $copier
     * @param \Closure(): Dialect          $dialect
     */
    public function __construct(
        private readonly BootConfig $config,
        private readonly \Closure $connection,
        private readonly \Closure $catalog,
        private readonly \Closure $copier,
        private readonly \Closure $dialect,
    )
    {
    }

    /**
     * Whether the site's database is SQL (MySQL, MariaDB or SQLite): the only kind that can have
     * legacy tables or rejects.
     */
    public function available(): bool
    {
        $family = $this->family();

        return $family === 'mysql' || $family === 'sqlite';
    }

    /**
     * The legacy tables that exist, in the order of LegacyDataCopier::LEGACY_TABLES (`legacy_entities`,
     * ...), from the schema catalog: no statement beyond the catalog's one.
     *
     * @return list<string>
     */
    public function names(): array
    {
        if (!$this->available()) {
            return [];
        }
        $tables = ($this->catalog)()->tables();
        $names = [];
        foreach (LegacyDataCopier::LEGACY_TABLES as $table) {
            if (in_array('legacy_' . $table, $tables, true)) {
                $names[] = 'legacy_' . $table;
            }
        }

        return $names;
    }

    /**
     * The legacy tables that exist, each with its row count: one COUNT(*) per table.
     *
     * @return array<string, int> table name => row count
     */
    public function present(): array
    {
        $present = [];
        foreach ($this->names() as $table) {
            $present[$table] = (int) ($this->connection)()->fetchOne('select count(*) from ' . $this->q($table));
        }

        return $present;
    }

    /**
     * A page of the rejects, oldest first: what identifies each row (its collection and 1.x uuid) and
     * why it was not carried, never its contents (they stay in the table).
     *
     * @return list<array{n: int, collection: string, legacy_uuid: string, reason: string}>
     */
    public function rejects(int $limit = 100, int $offset = 0): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT || $offset < 0) {
            throw new \InvalidArgumentException(sprintf('A page of rejects has a limit of 1 to %d and an offset of 0 or more.', self::MAX_LIMIT));
        }
        if (!$this->hasRejectsTable()) {
            return [];
        }
        $rows = ($this->connection)()->fetchAllAssociative(
            'select ' . $this->q('n') . ', ' . $this->q('collection') . ', ' . $this->q('legacy_uuid') . ', ' . $this->q('reason')
            . ' from ' . $this->q(self::REJECTS_TABLE) . ' order by ' . $this->q('n') . ' limit ? offset ?',
            [$limit, $offset],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return array_map(static fn (array $row): array => [
            'n' => (int) $row['n'],
            'collection' => (string) $row['collection'],
            'legacy_uuid' => (string) $row['legacy_uuid'],
            'reason' => (string) $row['reason'],
        ], $rows);
    }

    public function rejectCount(): int
    {
        if (!$this->hasRejectsTable()) {
            return 0;
        }

        return (int) ($this->connection)()->fetchOne('select count(*) from ' . $this->q(self::REJECTS_TABLE));
    }

    /**
     * The 1.x tables an old Idno process created again after the upgrade completed
     * (LegacyDataCopier::reappearedTables()): MySQL and MariaDB only; asked only when the catalog
     * lists a table of a 1.x name.
     *
     * @return list<string>
     */
    public function reappeared(): array
    {
        if ($this->family() !== 'mysql' || array_intersect(LegacyDataCopier::LEGACY_TABLES, ($this->catalog)()->tables()) === []) {
            return [];
        }

        return ($this->copier)()->reappearedTables();
    }

    /**
     * Whether the upgrade of a 1.x database is complete (LegacyDataCopier::completion()): one
     * statement; false when none is on record.
     */
    public function isComplete(): bool
    {
        if (!$this->available() || !($this->catalog)()->hasTable(LegacyDataCopier::PROGRESS_TABLE)) {
            return false;
        }

        return ($this->copier)()->completion() === true;
    }

    /**
     * Whether there is anything for the admin to see: legacy tables, rejects or reappeared tables.
     * For the admin menu: no statement when legacy tables exist (the catalog says so); otherwise one
     * (`select 1 from idno_upgrade_rejects limit 1`), and only when the catalog lists a table of a 1.x
     * name, also what reappeared() costs. Nothing on a site whose database is not SQL.
     */
    public function worthShowing(): bool
    {
        if ($this->names() !== []) {
            return true;
        }
        if ($this->hasRejectsTable() && ($this->connection)()->fetchOne('select 1 from ' . $this->q(self::REJECTS_TABLE) . ' limit 1') !== false) {
            return true;
        }

        return $this->reappeared() !== [];
    }

    /**
     * Deletes the legacy tables (exactly `legacy_` + a name of LegacyDataCopier::LEGACY_TABLES) and
     * returns how many it deleted. The catalog is read afresh first, and forgotten after. The rejects
     * and the upgrade's progress rows are never deleted. With no legacy table there is nothing to do
     * (0). A failure part-way is thrown; the tables not yet dropped stay, and drop() again finishes.
     *
     * @throws \LogicException when there are legacy tables and the upgrade is not complete (nothing is dropped)
     */
    public function drop(): int
    {
        if (!$this->available()) {
            return 0;
        }
        $catalog = ($this->catalog)();
        $catalog->forget();
        $names = $this->names();
        if ($names === []) {
            return 0;
        }
        if (!$this->isComplete()) {
            throw new \LogicException('The upgrade of the 1.x database is not complete: its legacy tables are still in use, and none was deleted.');
        }
        $dropped = 0;
        try {
            foreach (self::dropOrder($names) as $table) {
                ($this->connection)()->executeStatement('drop table if exists ' . $this->q($table));
                ++$dropped;
            }
        } finally {
            $catalog->forget();
        }

        return $dropped;
    }

    /**
     * The tables in an order foreign keys allow: each 1.x collection's `_metadata` and `_search`
     * tables (which reference the collection's table) before every other.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function dropOrder(array $names): array
    {
        $children = array_values(array_filter($names, static fn (string $name): bool => str_ends_with($name, '_metadata') || str_ends_with($name, '_search')));

        return [...$children, ...array_values(array_diff($names, $children))];
    }

    private function hasRejectsTable(): bool
    {
        return $this->available() && ($this->catalog)()->hasTable(self::REJECTS_TABLE);
    }

    private function family(): string
    {
        return ConnectionParameters::driverFamily($this->config->all());
    }

    private function q(string $name): string
    {
        return ($this->dialect)()->quoteIdentifier($name);
    }
}
