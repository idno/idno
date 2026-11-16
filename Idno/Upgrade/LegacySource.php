<?php

declare(strict_types=1);

namespace Idno\Upgrade;

use Doctrine\DBAL\Connection;
use Idno\Storage\Platform\Dialect;

/**
 * The 1.x tables of a database as one step of the upgrade sees them, and every statement that
 * reads them. Nothing here writes to a 1.x table.
 *
 *  - Built by read(): the database's tables, and the character set and collation of every `uuid`
 *    and `_id` column (one statement each on information_schema).
 *  - Every comparison of a 1.x column with a bound value converts the value to the column's own
 *    character set and collation (`convert(? using <charset>) collate <collation>`), so it is legal
 *    whatever the 1.x table was converted to (utf8mb3 general_ci as shipped; utf8mb4 in any
 *    collation), and a comparison with the `uuid` primary key is a range of that key. No 1.x
 *    column is ever compared with a column of another table: whether a row is accounted for is
 *    decided in PHP, by the exact bytes of its uuid.
 *  - Every read is bounded: a LIMIT on a range of the `uuid` primary key, or a list of primary key
 *    values (`uuid` of a collection table, `_id` of a `_search` table), except count(), which the
 *    copier calls once per collection when it makes its progress row, and configUuids(), whose
 *    LIMIT bounds what it returns but not what the database sorts (the config table holds a handful of
 *    rows).
 *
 * A plain helper of LegacyDataCopier, built with new: not a container service.
 */
final class LegacySource
{
    private const NAME = '/^[a-z0-9_]{1,64}$/';

    /**
     * @param list<string>                                              $tables
     * @param array<string, array<string, array{0: ?string, 1: ?string}>> $columns table => column => [charset, collation]
     */
    private function __construct(
        private readonly Connection $connection,
        private readonly Dialect $dialect,
        private readonly array $tables,
        private readonly array $columns,
    )
    {
    }

    public static function read(Connection $connection, Dialect $dialect): self
    {
        $tables = array_map('strval', $connection->fetchFirstColumn('select table_name from information_schema.tables where table_schema = database()'));
        $columns = [];
        foreach ($connection->fetchAllNumeric(
            "select table_name, column_name, character_set_name, collation_name from information_schema.columns where table_schema = database() and column_name in ('uuid', '_id')",
        ) as [$table, $column, $charset, $collation]) {
            $columns[(string) $table][strtolower((string) $column)] = [$charset === null ? null : strtolower((string) $charset), $collation === null ? null : strtolower((string) $collation)];
        }

        return new self($connection, $dialect, $tables, $columns);
    }

    /**
     * @return list<string>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    public function has(string $table): bool
    {
        return in_array($table, $this->tables, true);
    }

    /**
     * The placeholder of a value compared with $table.$column: converted to the column's character
     * set and collation (a plain `?` for a column without one, a binary string).
     */
    public function param(string $table, string $column): string
    {
        [$charset, $collation] = $this->columns[$table][$column] ?? [null, null];
        if ($charset === null || $collation === null) {
            return '?';
        }
        if (preg_match(self::NAME, $charset) !== 1 || preg_match(self::NAME, $collation) !== 1) {
            throw new \UnexpectedValueException(sprintf('The column %s.%s has a character set or collation this upgrade does not know how to name.', $table, $column));
        }

        return 'convert(? using ' . $charset . ') collate ' . $collation;
    }

    /**
     * The uuid, _id and contents size of the next rows of a collection table after $after (from
     * the first when null), in uuid order.
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public function nextRows(string $table, ?string $after, int $limit): array
    {
        $rows = [];
        foreach ($this->connection->fetchAllNumeric(
            'select ' . $this->q('uuid') . ', ' . $this->q('_id') . ', length(' . $this->q('contents') . ') from ' . $this->q($table)
            . $this->after($table, $after) . ' order by ' . $this->q('uuid') . ' limit ' . $limit,
            $after === null ? [] : [$after],
        ) as [$uuid, $id, $bytes]) {
            $rows[] = [(string) $uuid, (string) $id, (int) $bytes];
        }

        return $rows;
    }

    /**
     * The uuids of the next rows after $after (from the first when null), in uuid order.
     *
     * @return list<string>
     */
    public function uuidsAfter(string $table, ?string $after, int $limit): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            'select ' . $this->q('uuid') . ' from ' . $this->q($table) . $this->after($table, $after) . ' order by ' . $this->q('uuid') . ' limit ' . $limit,
            $after === null ? [] : [$after],
        ));
    }

    /**
     * The uuid, _id and contents size of the rows with these uuids.
     *
     * @param list<string> $uuids
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public function sizes(string $table, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }
        $rows = [];
        foreach ($this->connection->fetchAllNumeric(
            'select ' . $this->q('uuid') . ', ' . $this->q('_id') . ', length(' . $this->q('contents') . ') from ' . $this->q($table)
            . ' where ' . $this->q('uuid') . ' in (' . $this->params($table, 'uuid', count($uuids)) . ') order by ' . $this->q('uuid'),
            $uuids,
        ) as [$uuid, $id, $bytes]) {
            $rows[] = [(string) $uuid, (string) $id, (int) $bytes];
        }

        return $rows;
    }

    /**
     * The rows with these uuids, in uuid order: uuid, id (the `_id`), entity_subtype, owner,
     * created (the column's Unix time), publish_status, contents.
     *
     * @param list<string> $uuids
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $table, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            'select ' . $this->q('uuid') . ' as uuid, ' . $this->q('_id') . ' as id, ' . $this->q('entity_subtype') . ' as entity_subtype, '
            . $this->q('owner') . ' as owner, unix_timestamp(' . $this->q('created') . ') as created, ' . $this->q('publish_status') . ' as publish_status, '
            . $this->q('contents') . ' as contents from ' . $this->q($table)
            . ' where ' . $this->q('uuid') . ' in (' . $this->params($table, 'uuid', count($uuids)) . ') order by ' . $this->q('uuid'),
            $uuids,
        );
    }

    /**
     * The size in bytes of the search text of each _id (from the `_search` table; ids without one
     * are left out).
     *
     * @param list<string> $ids
     *
     * @return array<string, int>
     */
    public function searchSizes(string $searchTable, array $ids): array
    {
        return array_map('intval', $this->bySearchId($searchTable, $ids, 'length(' . $this->q('search') . ')'));
    }

    /**
     * The search text of each _id (ids without one are left out).
     *
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    public function searchTexts(string $searchTable, array $ids): array
    {
        return array_map('strval', $this->bySearchId($searchTable, $ids, $this->q('search')));
    }

    /**
     * Rows of the config table, newest first (the `created` column, then the greatest uuid), at
     * most $limit.
     *
     * @return list<string>
     */
    public function configUuids(string $table, int $limit): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            'select ' . $this->q('uuid') . ' from ' . $this->q($table) . ' order by ' . $this->q('created') . ' desc, ' . $this->q('uuid') . ' desc limit ' . $limit,
        ));
    }

    /**
     * One row of a collection table by its uuid: uuid, id, contents; null when it is gone.
     *
     * @return array{uuid: string, id: string, contents: string}|null
     */
    public function row(string $table, string $uuid): ?array
    {
        $row = $this->connection->fetchAssociative(
            'select ' . $this->q('uuid') . ' as uuid, ' . $this->q('_id') . ' as id, ' . $this->q('contents') . ' as contents from ' . $this->q($table)
            . ' where ' . $this->q('uuid') . ' = ' . $this->param($table, 'uuid'),
            [$uuid],
        );

        return $row === false ? null : ['uuid' => (string) $row['uuid'], 'id' => (string) $row['id'], 'contents' => (string) $row['contents']];
    }

    /**
     * Whether the table has a row after $after (from the first when null).
     */
    public function hasRowAfter(string $table, ?string $after): bool
    {
        return $this->uuidsAfter($table, $after, 1) !== [];
    }

    /**
     * The rows of a table (0 when it does not exist): only when the copier makes a progress row.
     */
    public function count(string $table): int
    {
        return $this->has($table) ? (int) $this->connection->fetchOne('select count(*) from ' . $this->q($table)) : 0;
    }

    /**
     * Renames every table to its new name in ONE statement (atomic on MySQL 8 and MariaDB 10.6).
     *
     * @param array<string, string> $names old name => new name
     */
    public function rename(array $names): void
    {
        if ($names === []) {
            return;
        }
        $pairs = [];
        foreach ($names as $from => $to) {
            $pairs[] = $this->q($from) . ' to ' . $this->q($to);
        }
        $this->connection->executeStatement('rename table ' . implode(', ', $pairs));
    }

    private function after(string $table, ?string $after): string
    {
        return $after === null ? '' : ' where ' . $this->q('uuid') . ' > ' . $this->param($table, 'uuid');
    }

    private function params(string $table, string $column, int $count): string
    {
        return implode(', ', array_fill(0, $count, $this->param($table, $column)));
    }

    /**
     * `<expression>` of the `_search` rows of these ids, keyed by the id asked for: the row whose
     * `_id` is the same bytes, else one equal to it ignoring case (what 1.x's join matched).
     *
     * @param list<string> $ids
     *
     * @return array<string, mixed>
     */
    private function bySearchId(string $searchTable, array $ids, string $expression): array
    {
        if ($ids === [] || !$this->has($searchTable)) {
            return [];
        }
        $ids = array_values(array_unique($ids));
        $found = [];
        foreach ($this->connection->fetchAllNumeric(
            'select ' . $this->q('_id') . ', ' . $expression . ' from ' . $this->q($searchTable) . ' where ' . $this->q('_id') . ' in (' . $this->params($searchTable, '_id', count($ids)) . ')',
            $ids,
        ) as [$id, $value]) {
            $found[(string) $id] = $value;
        }
        $result = [];
        $folded = [];
        foreach ($found as $id => $value) {
            $folded[mb_strtolower((string) $id)] ??= $value;
        }
        foreach ($ids as $id) {
            if (array_key_exists($id, $found)) {
                $result[$id] = $found[$id];
            } elseif (array_key_exists(mb_strtolower($id), $folded)) {
                $result[$id] = $folded[mb_strtolower($id)];
            }
        }

        return $result;
    }

    private function q(string $name): string
    {
        return $this->dialect->quoteIdentifier($name);
    }
}
