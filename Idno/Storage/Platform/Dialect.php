<?php

declare(strict_types=1);

namespace Idno\Storage\Platform;

use Doctrine\DBAL\Exception\DriverException;

/**
 * What DbalRecordStore needs to know about one database engine: the SQL that is not portable
 * (identifier quoting, the upsert, the search predicate, the limit clause, the table listing)
 * and how to read its driver errors: which mean "this query cannot run with these operands",
 * which mean "the database refused this record", and (everything else) a broken database.
 *
 * Every statement the store builds uses positional `?` placeholders; every value is bound as a
 * string. A dialect never interpolates a value, only identifiers it has quoted.
 *
 * Implementations are value objects, not container services (see config/services.php).
 */
interface Dialect
{
    /**
     * The identifier (a table or column name, already reduced to letters, digits and underscores) quoted.
     */
    public function quoteIdentifier(string $name): string;

    /**
     * An insert of one row that updates the existing row instead when the row conflicts with one
     * already in the table on a unique key (MySQL: any unique key; SQLite: the one it names, see
     * SqliteDialect). It may query the connection (once per table): the
     * store calls it before it begins its transaction.
     *
     * @param list<string> $insertColumns the columns given values
     * @param list<string> $updateColumns the columns set again when the row exists
     *
     * @return array{string, list<string>} the SQL, and the column whose value each `?` placeholder takes, in order
     */
    public function upsert(string $table, array $insertColumns, array $updateColumns): array;

    /**
     * The condition a search term puts on the search text column (an already quoted, qualified
     * column), the values of its placeholders, and whether the condition hands the term to a
     * query parser that may reject it (MySQL's boolean-mode full-text parser): the store passes
     * that on to isRejectedOperand().
     *
     * @param bool $booleanSearch  the site's `boolean_search` setting
     * @param bool $bypassFulltext the site's `bypass_fulltext_search` setting
     *
     * @return array{string, list<string>, bool}
     */
    public function searchCondition(string $column, string $term, bool $booleanSearch, bool $bypassFulltext): array;

    /**
     * The value to bind for an operand compared with a column of the main table (uuid, _id,
     * entity_subtype, owner, publish_status, created; the operand already as the string it is
     * bound as), or null when the database would reject it as an operand for that column: the
     * query then matches nothing (find() gives [], count() 0) and is not sent. A database that
     * rejects such operands itself (MySQL) returns the value unchanged and reports the error
     * through isRejectedOperand().
     */
    public function columnValue(string $column, string $value): ?string;

    /**
     * A query whose first column lists the names of the tables in the connection's database.
     */
    public function tableNamesQuery(): string;

    /**
     * A query whose one value is true (non-zero) when the server resolves table names without
     * regard to letter case.
     */
    public function tableNamesIgnoreCaseQuery(): string;

    /**
     * The clause that pages a query (the arguments are never negative).
     */
    public function limit(int $limit, int $offset): string;

    /**
     * Whether the error of a SELECT says that an operand of the query is not a value the
     * database accepts, so that the query matches nothing (as it does for the legacy backend,
     * which reports a failed query as no records). Any other error means the database or the SQL
     * is broken.
     *
     * @param bool $parsedSearchTerm whether the query holds a search condition whose term the
     *                               database parses (searchCondition()'s third element)
     */
    public function isRejectedOperand(DriverException $exception, bool $parsedSearchTerm): bool;

    /**
     * Whether the error of a write says that the database refused the data of this record (a
     * constraint, an invalid or too long value, a deadlock or lock wait): the write did not happen
     * and the store reports false. Any other error (a lost connection, a server shutting down, a
     * full table, a missing table, broken SQL) is not about the record and propagates.
     */
    public function isRefusedData(DriverException $exception): bool;
}
