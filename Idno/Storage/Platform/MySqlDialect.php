<?php

declare(strict_types=1);

namespace Idno\Storage\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Idno\Storage\Attribute\IndexedField;

/**
 * MySQL 8.0 and MariaDB 10.6, over the tables of the schema migrations: `insert ... on duplicate
 * key update`, full-text `match ... against` for search terms of more than five bytes (in boolean
 * mode when the site's `boolean_search` is on, `like` instead when `bypass_fulltext_search` is),
 * `like '%term%'` for shorter ones, document fields read with JSON_TABLE, and backquoted identifiers.
 */
final class MySqlDialect implements Dialect
{
    /**
     * The collation of the text columns of the schema's tables (but `data` and the settings, which
     * are binary), and of every comparison of a value read from `data`: the Unicode Collation
     * Algorithm 5.2.0, ignoring case and accents, with a weight of its own for each four-byte
     * character (emoji), as the 1.x schema's document values and search text had.
     */
    public const COLLATION = 'utf8mb4_unicode_520_ci';

    /** Search terms longer than this many bytes use the full-text index. */
    private const SHORT_SEARCH_BYTES = 5;

    /** ER_PARSE_ERROR: also what the boolean-mode full-text parser raises for a term it cannot parse. */
    private const ER_PARSE_ERROR = 1064;

    /**
     * Data refusals that DBAL maps to the generic DriverException: ER_TRUNCATED_WRONG_VALUE (1292),
     * ER_TRUNCATED_WRONG_VALUE_FOR_FIELD (1366, a value the column's character set cannot hold),
     * ER_DATA_TOO_LONG (1406, e.g. an _id longer than the 36 characters of `id`).
     */
    private const REFUSED_DATA_CODES = [1292, 1366, 1406];

    /**
     * MySQL's refusals of a JSON document (for its JSON functions, e.g. those of the generated
     * column of an indexed field, or JSON_VALID()): ER_INVALID_JSON_TEXT (3140),
     * ER_INVALID_JSON_TEXT_IN_PARAM (3141, not JSON, or a number too large for a double),
     * ER_JSON_DOCUMENT_TOO_DEEP (3157, deeper than 100 levels).
     */
    private const MYSQL_JSON_REFUSALS = [3140, 3141, 3157];

    /**
     * MariaDB's: ER_JSON_BAD_CHR (4035) to ER_JSON_DEPTH (4040, deeper than 32 levels), syntax,
     * escaping and end-of-text errors among them. MySQL uses some of these numbers for other errors,
     * so they count only when the message speaks of JSON.
     */
    private const MARIADB_JSON_REFUSALS = [4035, 4036, 4037, 4038, 4039, 4040];

    /** ER_DUP_FIELDNAME and ER_DUP_KEYNAME: the column or index a DDL statement adds exists already. */
    private const ALREADY_DEFINED_CODES = [1060, 1061];

    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function searchTextCondition(string $column, string $term, bool $booleanSearch, bool $bypassFulltext): array
    {
        if (strlen($term) > self::SHORT_SEARCH_BYTES && !$bypassFulltext) {
            return ['match (' . $column . ') against (?' . ($booleanSearch ? ' in boolean mode' : '') . ')', [$term], $booleanSearch];
        }

        return [$column . ' like ?', ['%' . $term . '%'], false];
    }

    /**
     * `insert ... on duplicate key update`, where every update is guarded by `id = values(id)`:
     * MySQL updates the row of whichever unique key conflicts, so when that is ANOTHER row's uuid
     * the guard leaves that row as it is (the statement affects no row), and when the row with the
     * id gets a uuid another row holds, the update fails (a duplicate key). `values()` is used, not
     * the row alias MySQL 8.0.19 introduced, because MariaDB has no row alias.
     */
    public function upsertById(string $table, array $columns): string
    {
        $id = $this->quoteIdentifier('id');
        $updates = [];
        foreach ($columns as $column) {
            if ($column !== 'id') {
                $quoted = $this->quoteIdentifier($column);
                $updates[] = $quoted . ' = if(' . $id . ' = values(' . $id . '), values(' . $quoted . '), ' . $quoted . ')';
            }
        }

        return 'insert into ' . $this->quoteIdentifier($table)
            . ' (' . implode(', ', array_map($this->quoteIdentifier(...), $columns)) . ')'
            . ' values (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
            . ' on duplicate key update ' . implode(', ', $updates);
    }

    /**
     * `<alias>.id in (select id from <table>, JSON_TABLE(...) where ...)`: a semi-join of the
     * table with itself, because MySQL 8.0 evaluates a JSON_TABLE in an EXISTS subquery as if it did
     * not refer to the outer row (once, with an empty document: error 3141 on a NOT NULL column).
     * One JSON_TABLE over the field's value (JSON_EXTRACT of the bound path): its row is the value
     * itself (`s`, `j`); when the value is an array, one row per element (`n`, `es`, `ej`, NESTED
     * PATH '$[*]'), and when it is an object, one row per member value (`m`, `os`, `oj`, the sibling
     * NESTED PATH '$.*'; its keys are never values); an empty array or object gives a row with `n`
     * or `m` null, which has no value. A JSON_TABLE column of type LONGTEXT
     * CHARACTER SET utf8mb4 gives a string unquoted in full UTF-8 on MySQL and MariaDB alike
     * (MariaDB's JSON_UNQUOTE() returns utf8mb3 and loses four-byte characters); the JSON column
     * gives the type (MySQL makes a JSON null an SQL NULL there, read as null). The value is
     * compared as COLLATION, the collation of the table's text columns.
     *
     * A number is its text as the database prints it: MariaDB prints the document's own text,
     * MySQL parses the number and prints it its own way, which differs from PHP's for numbers in
     * exponent form (1.0e+25 is `9.999999999999999e24`, 1.0e-5 `0.00001`).
     */
    public function fieldCondition(string $table, string $alias, string $operator, string $operands): array
    {
        $id = $this->quoteIdentifier('id');
        $value = 'case json_type(v.j)'
            . ' when \'ARRAY\' then (case when v.n is null then null else ' . self::text('v.es', 'v.ej') . ' end)'
            . ' when \'OBJECT\' then (case when v.m is null then null else ' . self::text('v.os', 'v.oj') . ' end)'
            . ' else ' . self::text('v.s', 'v.j') . ' end';
        $sql = $alias . '.' . $id . ' in (select f.' . $id . ' from ' . $table . ' f, json_table(json_extract(f.' . $this->quoteIdentifier('data') . ', ?), \'$\' columns ('
            . 's longtext character set utf8mb4 path \'$\', j json path \'$\', '
            . 'nested path \'$[*]\' columns (n for ordinality, es longtext character set utf8mb4 path \'$\', ej json path \'$\'), '
            . 'nested path \'$.*\' columns (m for ordinality, os longtext character set utf8mb4 path \'$\', oj json path \'$\'))) as v'
            . ' where (' . $value . ') collate ' . self::COLLATION . ' ' . $operator . ' ' . $operands . ')';

        return [$sql, 1];
    }

    /**
     * The query value (RecordIndex::queryValues()) of one JSON value: $text its unquoted text, $json
     * the value as JSON (NULL for a JSON null on MySQL). The one definition of the scalar rule on
     * MySQL and MariaDB: fieldCondition() applies it to each value JSON_TABLE gives, and the
     * generated column of an indexed field (indexedFieldStatements()) to the field's value.
     */
    private static function text(string $text, string $json): string
    {
        return 'case coalesce(json_type(' . $json . '), \'NULL\')'
            . ' when \'STRING\' then if(char_length(' . $text . ') = 0, \'0\', ' . $text . ')'
            . ' when \'BOOLEAN\' then if(' . $text . ' = \'true\', \'1\', \'0\')'
            . ' when \'NULL\' then \'0\''
            . ' when \'ARRAY\' then null when \'OBJECT\' then null'
            . ' else if(' . $text . ' + 0 = 0, \'0\', ' . $text . ') end';
    }

    /**
     * The generated column `<alias>.ix_<field>`.
     */
    public function indexedFieldValue(string $alias, string $field): string
    {
        return $alias . '.' . $this->quoteIdentifier(self::indexedColumn($field));
    }

    /**
     * A VIRTUAL generated column `ix_<field>` (LONGTEXT, COLLATION, the collation of the
     * table's text columns) whose value is text() of the field's JSON value, NULL when the field is
     * absent, then an index `ix_<field>` on its first $length characters. The whole value is in the
     * column, so a comparison is on the whole value; a unique index is unique on the prefix.
     *
     * The text of a string is unquoted in full UTF-8: JSON_UNQUOTE() on MySQL; on MariaDB, whose
     * JSON_UNQUOTE() returns utf8mb3 (a four-byte character becomes '?'), JSON_VALUE(), which
     * MySQL cannot use instead (its result is a VARCHAR(512): a longer string gives NULL). Other
     * values are JSON_UNQUOTE()d on both: their text is ASCII.
     */
    public function indexedFieldStatements(Connection $connection, string $table, string $field, bool $unique, int $length): array
    {
        $column = $this->quoteIdentifier(self::indexedColumn($field));
        if ($length < 1 || $length > IndexedField::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('An index prefix is 1 to %d characters, not %d.', IndexedField::MAX_LENGTH, $length));
        }
        $document = $this->quoteIdentifier('data');
        // The field name is validated: letters, digits and underscores only.
        $path = '\'$."' . $field . '"\'';
        $json = 'json_extract(' . $document . ', ' . $path . ')';
        $text = $connection->getDatabasePlatform() instanceof MariaDBPlatform
            ? 'if(json_type(' . $json . ') = \'STRING\', json_value(' . $document . ', ' . $path . '), json_unquote(' . $json . '))'
            : 'json_unquote(' . $json . ')';
        $value = 'case when ' . $json . ' is null then null else ' . self::text($text, $json) . ' end';
        $quotedTable = $this->quoteIdentifier($table);

        return [
            'alter table ' . $quotedTable . ' add column ' . $column . ' longtext character set utf8mb4 collate ' . self::COLLATION . ' generated always as (' . $value . ') virtual',
            'create ' . ($unique ? 'unique ' : '') . 'index ' . $column . ' on ' . $quotedTable . ' (' . $column . '(' . $length . '))',
        ];
    }

    public function indexedFieldIndex(Connection $connection, string $table, string $field): ?array
    {
        $row = $connection->fetchNumeric(
            'select non_unique, sub_part from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ? order by seq_in_index limit 1',
            [$table, self::indexedColumn($field)],
        );
        if ($row === false) {
            return null;
        }

        return ['unique' => (int) $row[0] === 0, 'length' => $row[1] === null ? null : (int) $row[1]];
    }

    /**
     * ER_DUP_FIELDNAME (the column exists) or ER_DUP_KEYNAME (the index exists).
     */
    public function isAlreadyDefined(DriverException $exception): bool
    {
        return in_array($exception->getCode(), self::ALREADY_DEFINED_CODES, true);
    }

    /**
     * `ix_<field>`, the field validated.
     */
    private static function indexedColumn(string $field): string
    {
        IndexedField::assertValidName($field);

        return 'ix_' . $field;
    }

    /**
     * The column's own collation (COLLATION) orders text: punctuation such as `_` before the letters.
     */
    public function textComparison(string $column, string $operator, string $operands): string
    {
        return $column . ' ' . $operator . ' ' . $operands;
    }

    public function limit(int $limit, int $offset): string
    {
        return 'limit ' . $offset . ',' . $limit;
    }

    /**
     * A term the boolean-mode full-text parser cannot parse (error 1064 in a query that holds such
     * a search). The store never sends a `created` operand that is not a timestamp.
     */
    public function isRejectedOperand(DriverException $exception, bool $parsedSearchTerm): bool
    {
        return $parsedSearchTerm && $exception->getCode() === self::ER_PARSE_ERROR;
    }

    /**
     * A constraint, a lock wait or deadlock, a value the column cannot hold (1292, 1366, 1406), or a
     * document the server's JSON functions refuse (MYSQL_JSON_REFUSALS, MARIADB_JSON_REFUSALS).
     */
    public function isRefusedData(DriverException $exception): bool
    {
        $code = $exception->getCode();

        return $exception instanceof ConstraintViolationException
            || $exception instanceof RetryableException
            || in_array($code, self::REFUSED_DATA_CODES, true)
            || in_array($code, self::MYSQL_JSON_REFUSALS, true)
            || (in_array($code, self::MARIADB_JSON_REFUSALS, true) && str_contains($exception->getMessage(), 'JSON'));
    }
}
