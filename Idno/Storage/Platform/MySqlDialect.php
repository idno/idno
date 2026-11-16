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
