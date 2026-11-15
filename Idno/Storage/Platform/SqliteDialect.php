<?php

declare(strict_types=1);

namespace Idno\Storage\Platform;

use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;
use Idno\Storage\MySqlSemantics;

/**
 * SQLite (3.35 or later, through pdo_sqlite), over warmup/schemas/sqlite/sqlite.sql, behaving as
 * the storage contract pins MySQL's behaviour, with the in-memory store's answers where MySQL's
 * cannot be had without a full-text index:
 *
 *  - the upsert is `insert ... on conflict do update set <column> = excluded.<column>`, with no
 *    conflict target, so like MySQL's `on duplicate key update` it updates the row that conflicts
 *    on any unique key;
 *  - text compares as MySQL's utf8mb3_general_ci does for ASCII: the schema's text columns use the
 *    `idno_ci` collation (MySqlSemantics::compare(), folded to upper case: 'Red' = 'red', 'a_' >
 *    'aZ'), and every value is bound as text into columns with text affinity, so numbers compare
 *    as text ('999' > '1000');
 *  - `created` holds 'Y-m-d H:i:s' text. A `created` operand is checked and normalised here
 *    (columnValue(), MySqlSemantics::datetime()): one MySQL would reject makes the query match
 *    nothing, an accepted one ('2023-6-14', '...T...', a fraction) compares as the datetime it is;
 *  - search never uses a full-text index (ruling P8): every term goes to the `idno_search`
 *    function (MySqlSemantics::searchMatches()), whatever the site's `boolean_search` and
 *    `bypass_fulltext_search` settings: a term of more than 5 bytes matches a record holding any
 *    of its words as a case-insensitive substring, a shorter one is a `LIKE '%term%'` phrase;
 *  - table names are resolved without regard to case, as SQLite resolves them.
 *
 * The collation and the function exist only on a connection set up by setUpConnection(), which
 * SqliteConnectionSetup (a DBAL driver middleware) runs once on every new connection; the schema
 * cannot be loaded or used without it.
 *
 * A value object, not a container service (see config/services.php).
 */
final class SqliteDialect implements Dialect
{
    /** The collation of the schema's text columns (see MySqlSemantics::compare()). */
    public const COLLATION = 'idno_ci';

    /** The search predicate: idno_search(search text, term) is 1 or 0 (see MySqlSemantics::searchMatches()). */
    public const SEARCH_FUNCTION = 'idno_search';

    /** How long a write waits for another connection's write lock before it fails. */
    public const BUSY_TIMEOUT_MILLISECONDS = 5000;

    /**
     * Sets up a new connection: waits for locks (busy_timeout), uses write-ahead logging (a no-op
     * for an in-memory database), enforces foreign keys, and registers the collation and the
     * search function.
     */
    public static function setUpConnection(\Pdo\Sqlite $connection): void
    {
        $connection->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MILLISECONDS);
        $connection->exec('PRAGMA journal_mode = WAL');
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->createCollation(self::COLLATION, static fn (string $a, string $b): int => MySqlSemantics::compare($a, $b));
        $connection->createFunction(
            self::SEARCH_FUNCTION,
            static fn (mixed $text, mixed $term): int => is_scalar($text) && is_scalar($term) && MySqlSemantics::searchMatches((string) $term, (string) $text) ? 1 : 0,
            2,
            \Pdo\Sqlite::DETERMINISTIC,
        );
    }

    public function quoteIdentifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function upsert(string $table, array $insertColumns, array $updateColumns): array
    {
        $sql = 'insert into ' . $this->quoteIdentifier($table)
            . ' (' . implode(', ', array_map($this->quoteIdentifier(...), $insertColumns)) . ')'
            . ' values (' . implode(', ', array_fill(0, count($insertColumns), '?')) . ')'
            . ' on conflict do update set '
            . implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column) . ' = excluded.' . $this->quoteIdentifier($column), $updateColumns));

        return [$sql, $insertColumns];
    }

    public function searchCondition(string $column, string $term, bool $booleanSearch, bool $bypassFulltext): array
    {
        return [self::SEARCH_FUNCTION . '(' . $column . ', ?)', [$term], false];
    }

    /**
     * A `created` operand in the form of the stored values, or null when MySQL would reject it.
     */
    public function columnValue(string $column, string $value): ?string
    {
        return $column === 'created' ? MySqlSemantics::datetime($value) : $value;
    }

    public function tableNamesQuery(): string
    {
        return "select name from sqlite_master where type = 'table'";
    }

    public function tableNamesIgnoreCaseQuery(): string
    {
        return 'select 1';
    }

    public function limit(int $limit, int $offset): string
    {
        return 'limit ' . $limit . ' offset ' . $offset;
    }

    /**
     * Never: no operand reaches SQLite that it rejects (columnValue() keeps them out, and the
     * search function accepts any term).
     */
    public function isRejectedOperand(DriverException $exception, bool $parsedSearchTerm): bool
    {
        return false;
    }

    /**
     * A constraint (a duplicate uuid or _id, a missing parent row, a NOT NULL) or a lock that was
     * not released within the busy timeout ("database is locked"), as MySQL's dialect treats a
     * lock wait timeout. Everything else (a missing table, an unregistered collation, a read-only
     * or corrupt database, disk full) propagates.
     */
    public function isRefusedData(DriverException $exception): bool
    {
        return $exception instanceof ConstraintViolationException
            || $exception instanceof RetryableException;
    }
}
