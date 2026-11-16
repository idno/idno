<?php

declare(strict_types=1);

namespace Idno\Storage\Platform;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * What JsonRecordStore (the tables `idno_<collection>`), SettingsRecordStore and IndexedFields need
 * to know about one database engine: the SQL that is not portable (identifier quoting, the upsert,
 * the search predicate, the comparison of document fields and text columns, the limit clause, the
 * DDL of an indexed field) and how to read its driver errors: which mean "this query cannot run
 * with these operands", which mean "the database refused this record", and (everything else) a
 * broken database.
 *
 * Every statement the store builds uses positional `?` placeholders; every value is bound as a
 * string. A dialect never interpolates a value, only identifiers it has quoted (and, in DDL, a
 * validated indexed field name).
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
     * The clause that pages a query (the arguments are never negative).
     */
    public function limit(int $limit, int $offset): string;

    /**
     * An insert of one row into a collection table (columns id, uuid, ... with `id` its primary
     * key and `uuid` unique) that replaces the row with the same `id` instead when there is one, and
     * never changes another row: when another row holds the record's uuid, the statement either
     * fails with a constraint violation (isRefusedData()) or affects no row (MySQL), and the store
     * then finds that the record was not stored. Its placeholders take the columns' values in the
     * order given.
     *
     * @param list<string> $columns every column of the table, `id` included
     */
    public function upsertById(string $table, array $columns): string;

    /**
     * A condition on the row aliased $alias of the collection table $table (both quoted) that holds
     * when SOME query value of a top-level field of the row's JSON document (`data`) satisfies
     * `<value> <operator> <operands>`. The field is named by its JSON path, `$.` followed by the
     * name as json_encode() writes it (`$."title"`), bound as the condition's first placeholders.
     *
     * The query values are RecordIndex::queryValues()'s, computed in SQL from the document: a
     * scalar is one value, a list or object one per element (member value, never its key) that is
     * a scalar (an element that is an array or object gives none); a string is itself
     * ('' is '0'), true is '1', false and null are '0', a number its text in the document (0 is
     * '0'). Text compares ignoring case as the dialect's text columns do; `<` and `>` compare
     * text folded to upper case.
     *
     * @param string $operator  one of =, !=, <, >, in, not in, and `is not` (with $operands `null`:
     *                          the field has a value)
     * @param string $operands  the SQL of the operands: `?`, `(?, ?)` or `null`
     *
     * @return array{string, int} the SQL, and how many placeholders for the field's path come
     *                            before the operands' own placeholders
     */
    public function fieldCondition(string $table, string $alias, string $operator, string $operands): array;

    /**
     * A comparison of a text column of a collection table (qualified and quoted) with operands:
     * `<column> <operator> <operands>`, where `<` and `>` compare text folded to upper case.
     *
     * @param string $operator one of =, !=, <, >, in, not in
     */
    public function textComparison(string $column, string $operator, string $operands): string;

    /**
     * The condition a search term puts on the `search` column of a collection table (qualified and
     * quoted), the values of its placeholders, and whether the condition hands the term to a query
     * parser that may reject it (MySQL's boolean-mode full-text parser): the store passes that on
     * to isRejectedOperand().
     *
     * @param bool $booleanSearch  the site's `boolean_search` setting
     * @param bool $bypassFulltext the site's `bypass_fulltext_search` setting
     *
     * @return array{string, list<string>, bool}
     */
    public function searchTextCondition(string $column, string $term, bool $booleanSearch, bool $bypassFulltext): array;

    // ------------------------------------------------ declared indexed fields (IndexedFields)

    /**
     * The SQL of the indexed value of a declared indexed field on the row aliased $alias of a
     * collection table: the value the field's index holds, which the store compares (with
     * textComparison(), the comparison its index serves) instead of fieldCondition()'s query
     * values. It is the query value of fieldCondition() for a SCALAR field (the same SQL
     * definition), and NULL when the field is absent or holds an array or object.
     *
     * @throws \InvalidArgumentException when $field is not a valid indexed field name (IndexedField::assertValidName())
     */
    public function indexedFieldValue(string $alias, string $field): string;

    /**
     * The DDL that creates the indexed field `$field` of the collection table $table (a name
     * already reduced to letters, digits and underscores), to run in order: on MySQL and MariaDB
     * the generated column `ix_<field>` and its index of the same name (on a prefix of $length
     * characters), on SQLite the index `<table>_ix_<field>` on the indexed value. A statement may
     * fail because a concurrent request made the same column or index first (isAlreadyDefined()).
     * The field name is checked again here and written into the DDL only as validated: a JSON
     * path in DDL cannot be a bound parameter.
     *
     * @param Connection $connection the connection the DDL runs on (it may ask which server it is)
     * @param int        $length     the prefix length, 1 to 255 (ignored where the whole value is indexed)
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException when $field is not a valid indexed field name, or the length is out of range
     */
    public function indexedFieldStatements(Connection $connection, string $table, string $field, bool $unique, int $length): array;

    /**
     * The index of the indexed field on the collection table as it exists: whether it is unique,
     * and its prefix length in characters (null when the whole value is indexed); null when the
     * field has no index (on MySQL, also when its generated column exists without one).
     *
     * @return array{unique: bool, length: ?int}|null
     */
    public function indexedFieldIndex(Connection $connection, string $table, string $field): ?array;

    /**
     * Whether the error of one of indexedFieldStatements() says that the column or index it
     * creates exists already.
     */
    public function isAlreadyDefined(DriverException $exception): bool;

    /**
     * Whether the error of a SELECT says that an operand of the query is not a value the
     * database accepts, so that the query matches nothing (as it does for the legacy backend,
     * which reports a failed query as no records). Any other error means the database or the SQL
     * is broken.
     *
     * @param bool $parsedSearchTerm whether the query holds a search condition whose term the
     *                               database parses (searchTextCondition()'s third element)
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
