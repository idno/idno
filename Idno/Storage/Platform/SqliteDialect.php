<?php

declare(strict_types=1);

namespace Idno\Storage\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;
use Idno\Storage\Attribute\IndexedField;
use Idno\Storage\MySqlSemantics;

/**
 * SQLite (3.38 or later with the JSON functions, through pdo_sqlite), over the tables of the schema
 * migrations (`idno_<collection>`), with built-in functions and collations only, so the database
 * file opens with standard SQLite tools: text compares with NOCASE, which ignores the case of ASCII
 * letters only (`ÉTÉ` is not `été`, where MySQL and the in-memory store equate them); a document
 * field is read with json_each() and `->`; search is `like` for terms of at most 5 bytes and a
 * substring match of any word (instr()) for longer ones, ignoring ASCII case only, whatever the
 * site's `boolean_search` and `bypass_fulltext_search` settings.
 *
 * Every connection the storage uses is set up by setUpConnection(), which SqliteConnectionSetup (a
 * DBAL driver middleware) runs once on every new connection.
 *
 * Built with new, not a container service (see config/services.php).
 */
final class SqliteDialect implements Dialect
{
    /** How long a write waits for another connection's write lock before it fails. */
    public const BUSY_TIMEOUT_MILLISECONDS = 5000;

    /**
     * The oldest SQLite the storage runs on: the JSON functions built in, the `->` operator and
     * `insert ... on conflict (...) do update` (the upsert).
     */
    public const MINIMUM_VERSION = '3.38.0';

    /**
     * Sets up a new connection: waits for locks (busy_timeout), uses write-ahead logging (a no-op
     * for an in-memory database) and enforces foreign keys.
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
     * to upper case, as the in-memory store orders it (MySQL's utf8mb4_unicode_520_ci sorts
     * punctuation such as `_` before the letters instead).
     */
    public function fieldCondition(string $table, string $alias, string $operator, string $operands): array
    {
        $document = $alias . '.' . $this->quoteIdentifier('data');
        // The JSON text of the value: the scalar itself, an array element by its index, an object
        // member by its key (quoted, so that any key is one label).
        $raw = '(case when v.key is null then ' . $document . ' -> ?'
            . ' when typeof(v.key) = \'integer\' then (' . $document . ' -> ?) -> v.key'
            . ' else (' . $document . ' -> ?) -> (\'$.\' || json_quote(v.key)) end)';
        $sql = 'exists (select 1 from json_each(' . $document . ', ?) as v where '
            . $this->textComparison('(' . self::scalarText('v.type', 'v.atom', $raw) . ')', $operator, $operands) . ')';

        return [$sql, 4];
    }

    /**
     * The query value (RecordIndex::queryValues()) of one JSON value, NULL for an array or object:
     * $type its JSON type as json_type() and json_each() name it, $atom its SQL value, $raw its JSON
     * text. The one definition of the scalar rule on SQLite: fieldCondition() applies it to each
     * value json_each() gives, and an indexed field's index (indexedFieldValue()) to the field's
     * value. Built-in functions only.
     */
    private static function scalarText(string $type, string $atom, string $raw): string
    {
        return 'case ' . $type
            . ' when \'text\' then (case when ' . $atom . ' = \'\' then \'0\' else ' . $atom . ' end)'
            . ' when \'true\' then \'1\' when \'false\' then \'0\' when \'null\' then \'0\''
            . ' when \'integer\' then (case when ' . $atom . ' = 0 then \'0\' else cast(' . $atom . ' as text) end)'
            . ' when \'real\' then (case when ' . $atom . ' = 0 then \'0\' else ' . $raw . ' end)'
            . ' end';
    }

    /**
     * scalarText() of the field's value in the document of the row aliased $alias: NULL when the
     * field is absent or holds an array or object. The same SQL as the index on the field
     * (indexedFieldStatements()), so that SQLite uses the index for `=` and `in`.
     */
    public function indexedFieldValue(string $alias, string $field): string
    {
        return self::indexedExpression($alias . '.' . $this->quoteIdentifier('data'), $field);
    }

    /**
     * `create [unique] index if not exists <table>_ix_<field> on <table> (<indexed value> collate
     * nocase)`: an index on the expression indexedFieldValue() compares, with the collation of the
     * comparison (textComparison()), over the whole value ($length is ignored). Built-in functions
     * and collations only, so the database file stays usable with standard SQLite tools.
     */
    public function indexedFieldStatements(Connection $connection, string $table, string $field, bool $unique, int $length): array
    {
        IndexedField::assertValidName($field);
        if ($length < 1 || $length > IndexedField::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('An index prefix is 1 to %d characters, not %d.', IndexedField::MAX_LENGTH, $length));
        }

        return [
            'create ' . ($unique ? 'unique ' : '') . 'index if not exists ' . $this->quoteIdentifier(self::indexName($table, $field))
            . ' on ' . $this->quoteIdentifier($table) . ' (' . self::indexedExpression($this->quoteIdentifier('data'), $field) . ' collate nocase)',
        ];
    }

    public function indexedFieldIndex(Connection $connection, string $table, string $field): ?array
    {
        $unique = $connection->fetchOne('select "unique" from pragma_index_list(?) where name = ?', [$table, self::indexName($table, $field)]);

        return $unique === false ? null : ['unique' => (int) $unique === 1, 'length' => null];
    }

    /**
     * Never: the index is created `if not exists`.
     */
    public function isAlreadyDefined(DriverException $exception): bool
    {
        return false;
    }

    /**
     * `<table>_ix_<field>`, the field validated.
     */
    private static function indexName(string $table, string $field): string
    {
        IndexedField::assertValidName($field);

        return $table . '_ix_' . $field;
    }

    /**
     * The indexed value of the field in $document (a qualified, quoted `data` column).
     */
    private static function indexedExpression(string $document, string $field): string
    {
        IndexedField::assertValidName($field);
        // The field name is validated: letters, digits and underscores only.
        $path = '\'$."' . $field . '"\'';

        return '(' . self::scalarText('json_type(' . $document . ', ' . $path . ')', 'json_extract(' . $document . ', ' . $path . ')', '(' . $document . ' -> ' . $path . ')') . ')';
    }

    /**
     * NOCASE, except that `<` and `>` compare upper() of both sides in binary (ASCII only, as
     * NOCASE), so that '_' sorts after the letters, as in the in-memory store (folded to upper case);
     * NOCASE folds to lower case, where it sorts before them. (The explicit `collate binary` also overrides the
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

    public function limit(int $limit, int $offset): string
    {
        return 'limit ' . $limit . ' offset ' . $offset;
    }

    /**
     * Never: no operand reaches SQLite that it rejects (search is built-in `like` and instr(),
     * which accept any term).
     */
    public function isRejectedOperand(DriverException $exception, bool $parsedSearchTerm): bool
    {
        return false;
    }

    /**
     * A constraint (a duplicate uuid or _id, a missing parent row, a NOT NULL) or a lock that was
     * not released within the busy timeout ("database is locked"), as MySQL's dialect treats a
     * lock wait timeout. Everything else (a missing table, a read-only or corrupt database, disk
     * full) propagates.
     */
    /**
     * SQLite's JSON functions accept 1000 levels (observed on 3.51), more than json_encode() writes (512).
     */
    public function jsonDepthLimit(Connection $connection): ?int
    {
        return null;
    }

    /**
     * A statement that takes SQLite's write lock without changing anything: run as the first statement of a transaction it makes the transaction a
     * writer from the start (the equivalent of BEGIN IMMEDIATE, which DBAL's nesting cannot ask for), so that it waits for another writer for up to
     * the busy timeout. A transaction that begins with a read and writes later fails at once, and unwaited, when another connection commits in between.
     * MySQL and MariaDB need nothing of the kind.
     */
    public const TAKE_THE_WRITE_LOCK = 'delete from idno_settings where 1 = 0';

    public function isRefusedData(DriverException $exception): bool
    {
        return $exception instanceof ConstraintViolationException
            || $exception instanceof RetryableException;
    }
}
