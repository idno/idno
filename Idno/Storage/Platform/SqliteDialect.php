<?php

declare(strict_types=1);

namespace Idno\Storage\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;
use Idno\Storage\MySqlSemantics;

/**
 * SQLite (3.38 or later with the JSON functions, through pdo_sqlite).
 *
 * For DbalRecordStore, over warmup/schemas/sqlite/sqlite.sql, behaving as
 * the storage contract pins MySQL's behaviour, with the in-memory store's answers where MySQL's
 * cannot be had without a full-text index:
 *
 *  - the upsert is `insert ... on conflict (<key>) do update set <column> = excluded.<column>`,
 *    with a NAMED conflict target, read once per table from the table's own indexes on the
 *    connection the dialect is given: `_id` when the table has a unique index on `_id` alone
 *    (entities, reader, every `_search` table), else its primary key (uuid: site, config). A
 *    conflict on another unique key is an error (the save is refused), never an update of the row
 *    that holds that key. Compared with MySQL's `on duplicate key update`, which updates the row of
 *    whichever key conflicts: the same outcome everywhere except one case, an existing `_id`
 *    re-saved with ANOTHER existing record's uuid in `entities`/`reader`, where MySQL overwrites
 *    the other record's row with this document and returns the id, and SQLite refuses (false, both
 *    rows unchanged). In site and config both overwrite (pinned in DbalSqliteRecordStoreTest);
 *  - text compares as MySQL's utf8mb3_general_ci does for ASCII: the schema's text columns use the
 *    `idno_ci` collation (MySqlSemantics::compare(), folded to upper case: 'Red' = 'red', 'a_' >
 *    'aZ'), and every value is bound as text into columns with text affinity, so numbers compare
 *    as text ('999' > '1000');
 *  - `created` holds 'Y-m-d H:i:s' text. A `created` operand is checked and normalised here
 *    (columnValue(), MySqlSemantics::datetime()): one MySQL would reject makes the query match
 *    nothing, an accepted one ('2023-6-14', '...T...', a fraction) compares as the datetime it is;
 *  - search never uses a full-text index: every term goes to the `idno_search`
 *    function (MySqlSemantics::searchMatches()), whatever the site's `boolean_search` and
 *    `bypass_fulltext_search` settings: a term of more than 5 bytes matches a record holding any
 *    of its words as a case-insensitive substring, a shorter one is a `LIKE '%term%'` phrase;
 *  - table names are resolved without regard to case, as SQLite resolves them.
 *
 * The collation and the function exist only on a connection set up by setUpConnection(), which
 * SqliteConnectionSetup (a DBAL driver middleware) runs once on every new connection; the schema
 * cannot be loaded or used without it (see the header of sqlite.sql for what that means for
 * maintenance).
 *
 * For JsonRecordStore, over the tables of the schema migrations (`idno_<collection>`), with
 * built-in functions and collations only (upsertById(), fieldCondition(), textComparison(),
 * searchTextCondition()): text compares with NOCASE, which ignores the case of ASCII letters only
 * (`ÉTÉ` is not `été`, where MySQL and the in-memory store equate them); a document field is read
 * with json_each() and `->`; search is `like` for terms of at most 5 bytes and a substring match
 * of any word (instr()) for longer ones, ignoring ASCII case only.
 *
 * Built with new, for one connection (the one the store uses), not a container service (see
 * config/services.php).
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
     * The oldest SQLite the storage runs on: the JSON functions built in, and the `->` operator
     * (JsonRecordStore), and `insert ... on conflict (...) do update` (the upserts).
     */
    public const MINIMUM_VERSION = '3.38.0';

    /** @var array<string, list<string>> the conflict target of each table, by lower-cased name */
    private array $conflictTargets = [];

    /**
     * @param Connection $connection the connection the store uses, on which the dialect reads a
     *                               table's indexes (once per table) to choose the upsert's conflict target;
     *                               it must be the same Connection object the DbalRecordStore is given
     */
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Sets up a new connection: waits for locks (busy_timeout), uses write-ahead logging (a no-op
     * for an in-memory database), enforces foreign keys, and registers the collation and the
     * search function.
     *
     * @throws \LogicException when the SQLite library is older than MINIMUM_VERSION or has no JSON functions
     */
    public static function setUpConnection(\Pdo\Sqlite $connection): void
    {
        $version = (string) $connection->query('select sqlite_version()')->fetchColumn();
        try {
            $json = (int) $connection->query("select json_valid('{}')")->fetchColumn() === 1;
        } catch (\PDOException) {
            // A library built without the JSON functions (SQLITE_OMIT_JSON).
            $json = false;
        }
        self::checkVersion($version, $json);
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

    /**
     * @param string $version the SQLite library's version
     * @param bool   $json    whether the library has the JSON functions (`json_valid()`)
     *
     * @throws \LogicException when the version is older than MINIMUM_VERSION, or the JSON functions are missing
     */
    public static function checkVersion(string $version, bool $json = true): void
    {
        if (version_compare($version, self::MINIMUM_VERSION, '<') || !$json) {
            throw new \LogicException(sprintf(
                'SQLite storage needs SQLite %s or later with the JSON functions; this PHP uses SQLite %s%s.',
                self::MINIMUM_VERSION,
                $version,
                $json ? '' : ' without them',
            ));
        }
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
            . ' on conflict (' . implode(', ', array_map($this->quoteIdentifier(...), $this->conflictTarget($table))) . ') do update set '
            . implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column) . ' = excluded.' . $this->quoteIdentifier($column), $updateColumns));

        return [$sql, $insertColumns];
    }

    /**
     * The columns of the table's unique index on `_id` alone, if it has one, else of its primary key.
     *
     * @return list<string>
     *
     * @throws \LogicException when the table has neither
     */
    private function conflictTarget(string $table): array
    {
        $key = strtolower($table);
        if (isset($this->conflictTargets[$key])) {
            return $this->conflictTargets[$key];
        }
        $primary = [];
        foreach ($this->connection->fetchAllAssociative('select name, "unique", origin, partial from pragma_index_list(?)', [$table]) as $index) {
            if ((int) $index['unique'] !== 1 || (int) $index['partial'] !== 0) {
                continue;
            }
            $columns = array_map('strval', $this->connection->fetchFirstColumn('select name from pragma_index_info(?) order by seqno', [$index['name']]));
            if ($columns === ['_id']) {
                return $this->conflictTargets[$key] = $columns;
            }
            if ($index['origin'] === 'pk') {
                $primary = $columns;
            }
        }
        if ($primary === []) {
            throw new \LogicException(sprintf('The table "%s" has no unique key on _id and no primary key to upsert on.', $table));
        }

        return $this->conflictTargets[$key] = $primary;
    }

    public function searchCondition(string $column, string $term, bool $booleanSearch, bool $bypassFulltext): array
    {
        return [self::SEARCH_FUNCTION . '(' . $column . ', ?)', [$term], false];
    }

    /**
     * `insert ... on conflict (id) do update`: a conflict on the uuid (another row's) is an error
     * (the save is refused), never an update of that row.
     */
    public function upsertById(string $table, array $columns): string
    {
        $updates = [];
        foreach ($columns as $column) {
            if ($column !== 'id') {
                $updates[] = $this->quoteIdentifier($column) . ' = excluded.' . $this->quoteIdentifier($column);
            }
        }

        return 'insert into ' . $this->quoteIdentifier($table)
            . ' (' . implode(', ', array_map($this->quoteIdentifier(...), $columns)) . ')'
            . ' values (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            . ' on conflict (' . $this->quoteIdentifier('id') . ') do update set ' . implode(', ', $updates);
    }

    /**
     * `json_each()` over the field's value: one row for a scalar, one per element of an array or
     * member of an object (its value, never its key; elements that are arrays or objects give no
     * value). Built-in functions
     * only. A real number is its text in the document (`->` gives the JSON text of a value; SQLite's
     * own conversion of a real to text keeps 15 digits). Text compares with NOCASE (ASCII letters
     * only); `<` and `>` compare upper() of both sides (ASCII only), so that text is ordered folded
     * to upper case as MySQL's utf8mb4_general_ci orders it.
     */
    public function fieldCondition(string $table, string $alias, string $operator, string $operands): array
    {
        $document = $alias . '.' . $this->quoteIdentifier('data');
        // The JSON text of the value: the scalar itself, an array element by its index, an object
        // member by its key (quoted, so that any key is one label).
        $raw = '(case when v.key is null then ' . $document . ' -> ?'
            . ' when typeof(v.key) = \'integer\' then (' . $document . ' -> ?) -> v.key'
            . ' else (' . $document . ' -> ?) -> (\'$.\' || json_quote(v.key)) end)';
        $value = 'case v.type'
            . ' when \'text\' then (case when v.atom = \'\' then \'0\' else v.atom end)'
            . ' when \'true\' then \'1\' when \'false\' then \'0\' when \'null\' then \'0\''
            . ' when \'integer\' then (case when v.atom = 0 then \'0\' else cast(v.atom as text) end)'
            . ' when \'real\' then (case when v.atom = 0 then \'0\' else ' . $raw . ' end)'
            . ' end';
        $sql = 'exists (select 1 from json_each(' . $document . ', ?) as v where '
            . $this->textComparison('(' . $value . ')', $operator, $operands) . ')';

        return [$sql, 4];
    }

    /**
     * NOCASE, except that `<` and `>` compare upper() of both sides in binary (ASCII only, as
     * NOCASE), so that '_' sorts after the letters as in MySQL's utf8mb4_general_ci; NOCASE folds to
     * lower case, where it sorts before them. (The explicit `collate binary` also overrides the
     * NOCASE a column's collation would carry through upper().)
     */
    public function textComparison(string $column, string $operator, string $operands): string
    {
        if ($operator === '<' || $operator === '>') {
            return 'upper(' . $column . ') collate binary ' . $operator . ' upper(' . $operands . ')';
        }

        return $column . ' collate nocase ' . $operator . ' ' . $operands;
    }

    /**
     * Built-in functions only, whatever the site's `boolean_search` and `bypass_fulltext_search`:
     * a term of at most 5 bytes is `like '%term%'` (with `\` escaping the next character, `%` and
     * `_` wildcards), a longer one matches a text holding ANY of its words (split on whitespace) as a
     * substring. Both ignore the case of ASCII letters only.
     */
    public function searchTextCondition(string $column, string $term, bool $booleanSearch, bool $bypassFulltext): array
    {
        if (strlen($term) <= MySqlSemantics::SHORT_SEARCH_BYTES) {
            return [$column . ' like ? escape \'\\\'', ['%' . $term . '%'], false];
        }
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return ['(1 = 0)', [], false];
        }

        return ['(' . implode(' or ', array_fill(0, count($words), 'instr(lower(' . $column . '), lower(?)) > 0')) . ')', $words, false];
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
