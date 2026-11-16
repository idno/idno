<?php

declare(strict_types=1);

namespace Idno\Storage\Platform;

use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\RetryableException;

/**
 * MySQL, as the old backend, Idno\Data\MySQL (since removed), used it: `insert ... on duplicate key update`, full-text
 * `match ... against` for search terms of more than five bytes (in boolean mode when the site's
 * `boolean_search` is on, `like` instead when `bypass_fulltext_search` is), `like '%term%'` for
 * shorter ones, and backquoted identifiers.
 */
final class MySqlDialect implements Dialect
{
    /** Search terms longer than this many bytes use the full-text index. */
    private const SHORT_SEARCH_BYTES = 5;

    /** ER_WRONG_VALUE: "Incorrect TIMESTAMP value", a `created` operand that is not a datetime. */
    private const ER_WRONG_VALUE = 1525;

    /** ER_PARSE_ERROR: also what the boolean-mode full-text parser raises for a term it cannot parse. */
    private const ER_PARSE_ERROR = 1064;

    /**
     * Data refusals that DBAL maps to the generic DriverException: ER_TRUNCATED_WRONG_VALUE (1292),
     * ER_TRUNCATED_WRONG_VALUE_FOR_FIELD (1366, e.g. a four-byte character in a utf8mb3 column),
     * ER_DATA_TOO_LONG (1406, e.g. a field name longer than the 64 characters of `name`).
     */
    private const REFUSED_DATA_CODES = [1292, 1366, 1406];

    public function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function upsert(string $table, array $insertColumns, array $updateColumns): array
    {
        $sql = 'insert into ' . $this->quoteIdentifier($table)
            . ' (' . implode(', ', array_map($this->quoteIdentifier(...), $insertColumns)) . ')'
            . ' values (' . implode(', ', array_fill(0, count($insertColumns), '?')) . ')'
            . ' on duplicate key update '
            . implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column) . ' = ?', $updateColumns));

        return [$sql, [...$insertColumns, ...$updateColumns]];
    }

    public function searchCondition(string $column, string $term, bool $booleanSearch, bool $bypassFulltext): array
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
     * compared as utf8mb4_general_ci, the collation of the table's text columns.
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
            . ' where (' . $value . ') collate utf8mb4_general_ci ' . $operator . ' ' . $operands . ')';

        return [$sql, 1];
    }

    /**
     * The query value (RecordIndex::queryValues()) of one JSON value: $text its unquoted text, $json
     * the value as JSON (NULL for a JSON null on MySQL).
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
     * The column's own collation (utf8mb4_general_ci) orders text folded to upper case.
     */
    public function textComparison(string $column, string $operator, string $operands): string
    {
        return $column . ' ' . $operator . ' ' . $operands;
    }

    public function searchTextCondition(string $column, string $term, bool $booleanSearch, bool $bypassFulltext): array
    {
        return $this->searchCondition($column, $term, $booleanSearch, $bypassFulltext);
    }

    /**
     * Unchanged: MySQL itself rejects a `created` operand that is not a datetime (error 1525).
     */
    public function columnValue(string $column, string $value): ?string
    {
        return $value;
    }

    public function tableNamesQuery(): string
    {
        return 'show tables';
    }

    public function tableNamesIgnoreCaseQuery(): string
    {
        return 'select @@lower_case_table_names <> 0';
    }

    public function limit(int $limit, int $offset): string
    {
        return 'limit ' . $offset . ',' . $limit;
    }

    public function isRejectedOperand(DriverException $exception, bool $parsedSearchTerm): bool
    {
        return $exception->getCode() === self::ER_WRONG_VALUE
            || ($parsedSearchTerm && $exception->getCode() === self::ER_PARSE_ERROR);
    }

    public function isRefusedData(DriverException $exception): bool
    {
        return $exception instanceof ConstraintViolationException
            || $exception instanceof RetryableException
            || in_array($exception->getCode(), self::REFUSED_DATA_CODES, true);
    }
}
