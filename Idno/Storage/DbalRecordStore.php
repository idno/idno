<?php

declare(strict_types=1);

namespace Idno\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Idno\Storage\Criterion\AllOf;
use Idno\Storage\Criterion\AnyOf;
use Idno\Storage\Criterion\Criterion;
use Idno\Storage\Criterion\Equals;
use Idno\Storage\Criterion\GreaterThan;
use Idno\Storage\Criterion\In;
use Idno\Storage\Criterion\LessThan;
use Idno\Storage\Criterion\NotEquals;
use Idno\Storage\Criterion\NotIn;
use Idno\Storage\Criterion\Search;
use Idno\Storage\Platform\Dialect;

/**
 * A RecordStore on a Doctrine DBAL connection, over the legacy schema: per collection a main
 * table (`<collection>`: uuid, _id, siteid, entity_subtype, owner, created, publish_status and
 * the JSON document in `contents`), `<collection>_metadata` (one name/value row per indexed
 * value) and `<collection>_search` (the search text).
 *
 * It runs the statements Idno\Data\MySQL runs today, with what is engine-specific in the Dialect:
 *
 *  - save(): in one transaction, an upsert of the main row, an upsert of the search row, the
 *    deletion of the metadata rows and the insertion of the new ones. What is written is derived
 *    by RecordIndex. It returns false whenever the transaction did not commit (ruling P7: today's
 *    backend returns the id when a metadata row is rejected; values are cut at 255 bytes on a
 *    character boundary). A record that cannot be encoded as JSON is not saved (false).
 *  - find()/count(): `select distinct <table>.*` (`count(distinct <table>.uuid)`), one
 *    `left join <table>_metadata md<n>` per condition on a document field, a `left join
 *    <table>_search srch` for a top-level search, newest `created` first.
 *
 * Every value is bound as a STRING, as the legacy backend binds them: true is '1', false is '',
 * a number its decimal text, null is NULL. So `$lt` on a document field compares text, a `true`
 * operand matches the stored '1', and an operand on `created` that is not a datetime literal
 * makes MySQL reject the query.
 *
 * Queries that cannot run match nothing (find() gives [], count() 0; ruling P6):
 *  - an AnyOf with no children (the MongoDB list form of `$or`, which legacy compiles to invalid
 *    SQL) is compiled to a false condition, never sent as invalid SQL (an AllOf with none is true;
 *    an In with no values is false, a NotIn with none requires only that the field exists);
 *  - a Search anywhere but directly under the root is recognised and not run (legacy joins the
 *    search table only for a top-level `$search`, so MySQL fails the query);
 *  - an operand the database rejects is run, and the driver error the Dialect recognises
 *    (Dialect::isRejectedOperand()) is caught. On MySQL: a `created` operand that is not a
 *    datetime (error 1525), and a search term the boolean-mode full-text parser cannot parse
 *    (error 1064, only for a statement that holds a boolean-mode `match ... against`).
 * Any other driver error propagates: a lost connection or broken SQL is not "no results".
 * save(), delete() and deleteAll() return false when the database refuses the record's data
 * (Dialect::isRefusedData(): a constraint, an invalid or too long value, a deadlock or lock
 * wait); every other error propagates. save() always rolls its transaction back first.
 *
 * A collection exists when its three tables do (looked up once, and again when a name is not
 * found; matched without regard to case when the server resolves table names that way); any
 * other name makes writes fail (false) and reads find nothing, without a query on it.
 *
 * This class is not a container service yet (see config/services.php).
 */
final class DbalRecordStore implements RecordStore
{
    /** The fields queried on the columns of the main table, not on the metadata rows (exact names). */
    private const COLUMN_FIELDS = ['uuid', '_id', 'entity_subtype', 'owner', 'publish_status', 'created'];

    /** The main table's columns, in the order the legacy upsert writes them. */
    private const INSERT_COLUMNS = ['uuid', '_id', 'siteid', 'entity_subtype', 'owner', 'contents', 'publish_status', 'created'];

    /** The columns the legacy upsert sets on an existing row (never _id or siteid). */
    private const UPDATE_COLUMNS = ['uuid', 'entity_subtype', 'owner', 'contents', 'publish_status', 'created'];

    /** @var array<string, true>|null the tables of the database, as a set (lower-cased when the server ignores case) */
    private ?array $tables = null;

    private bool $tableNamesIgnoreCase = false;

    /**
     * @param \Closure(string): string $uncurlQuotes   the quote normaliser applied to the search text (Language::uncurlQuotes())
     * @param bool                     $booleanSearch  the site's `boolean_search` setting
     * @param bool                     $bypassFulltext the site's `bypass_fulltext_search` setting
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Dialect $dialect,
        private readonly RecordDefaults $defaults,
        private readonly \Closure $uncurlQuotes,
        private readonly bool $booleanSearch,
        private readonly bool $bypassFulltext,
    )
    {
    }

    public function save(string $collection, array $record): string|false
    {
        $name = $this->resolve($collection);
        if ($name === null) {
            return false;
        }

        if (empty($record['_id'])) {
            $record['_id'] = $this->defaults->generateId();
        }
        if (empty($record['siteid'])) {
            $record['siteid'] = $this->defaults->currentSiteId();
        }
        if (empty($record['uuid'])) {
            $record['uuid'] = $this->defaults->siteUrl() . 'view/' . (is_scalar($record['_id']) ? (string) $record['_id'] : '');
        }
        if (empty($record['owner'])) {
            $record['owner'] = '';
        }

        $contents = json_encode($record);
        if ($contents === false) {
            return false;
        }

        $now = time();
        $values = RecordIndex::columns($record, $now) + ['contents' => $contents];
        $rows = RecordIndex::metadataRows($record, $now);
        $search = RecordIndex::searchText($record, $this->uncurlQuotes);

        try {
            $this->connection->beginTransaction();
            try {
                [$sql, $placeholders] = $this->dialect->upsert($name, self::INSERT_COLUMNS, self::UPDATE_COLUMNS);
                $this->connection->executeStatement($sql, array_map(static fn (string $column): ?string => $values[$column], $placeholders));

                $searchValues = ['_id' => $values['_id'], 'search' => $search];
                [$sql, $placeholders] = $this->dialect->upsert($name . '_search', ['_id', 'search'], ['search']);
                $this->connection->executeStatement($sql, array_map(static fn (string $column): string => $searchValues[$column], $placeholders));

                $metadata = $this->table($name . '_metadata');
                $this->connection->executeStatement('delete from ' . $metadata . ' where ' . $this->dialect->quoteIdentifier('_id') . ' = ?', [$values['_id']]);
                $insert = 'insert into ' . $metadata . ' (' . implode(', ', array_map($this->dialect->quoteIdentifier(...), ['_id', 'name', 'value'])) . ') values (?, ?, ?)';
                foreach ($rows as $row) {
                    $this->connection->executeStatement($insert, [$values['_id'], $row['name'], $row['value']]);
                }

                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->rollBack();
                throw $e;
            }
        } catch (DriverException $e) {
            if (!$this->dialect->isRefusedData($e)) {
                throw $e;
            }

            return false;
        }

        return $values['_id'];
    }

    /**
     * Legacy's statement exactly, deliberately without an order: in `site` and `config`, `_id` is
     * not unique (the key is the uuid), and which of several rows comes first is what MySQL gives.
     */
    public function findById(string $collection, string $id): ?array
    {
        return $this->findFirst($collection, 'select %1$s.* from %1$s where _id = ?', [$id]);
    }

    /**
     * Legacy's statement exactly, deliberately without an order (the uuid is the primary key).
     */
    public function findByUuid(string $collection, string $uuid): ?array
    {
        return $this->findFirst($collection, 'select distinct %1$s.* from %1$s where uuid = ?', [$uuid]);
    }

    public function findLatest(string $collection): ?array
    {
        return $this->findFirst($collection, 'select %1$s.* from %1$s order by created desc ' . $this->dialect->limit(1, 0), []);
    }

    public function find(string $collection, Criteria $criteria, int $limit, int $offset): array
    {
        if ($limit <= 0 || $offset < 0) {
            return [];
        }
        $name = $this->resolve($collection);
        $query = $name === null ? null : $this->compile($name, $criteria);
        if ($name === null || $query === null) {
            return [];
        }
        $table = $this->table($name);
        $sql = 'select distinct ' . $table . '.* from ' . $table . $query['from'] . $query['where']
            . ' order by ' . $table . '.' . $this->dialect->quoteIdentifier('created') . ' desc '
            . $this->dialect->limit($limit, $offset);

        try {
            $rows = $this->connection->fetchAllAssociative($sql, $query['params']);
        } catch (DriverException $e) {
            if ($this->dialect->isRejectedOperand($e, $query['parsedSearchTerm'])) {
                return [];
            }
            throw $e;
        }

        $records = [];
        foreach ($rows as $row) {
            $record = self::decode($row['contents'] ?? null);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public function count(string $collection, Criteria $criteria): int
    {
        $name = $this->resolve($collection);
        $query = $name === null ? null : $this->compile($name, $criteria);
        if ($name === null || $query === null) {
            return 0;
        }
        $table = $this->table($name);
        $sql = 'select count(distinct ' . $table . '.' . $this->dialect->quoteIdentifier('uuid') . ') as total from ' . $table . $query['from'] . $query['where'];

        try {
            return (int) $this->connection->fetchOne($sql, $query['params']);
        } catch (DriverException $e) {
            if ($this->dialect->isRejectedOperand($e, $query['parsedSearchTerm'])) {
                return 0;
            }
            throw $e;
        }
    }

    public function delete(string $collection, string $id): bool
    {
        // The metadata and search rows go with the main row (on delete cascade).
        return $this->write($collection, ' where ' . $this->dialect->quoteIdentifier('_id') . ' = ?', [$id]);
    }

    public function deleteAll(string $collection): bool
    {
        return $this->write($collection, '', []);
    }

    /**
     * `delete from <collection>` with the condition: true, or false when the collection does not
     * exist or the database refuses the statement.
     *
     * @param list<string> $params
     */
    private function write(string $collection, string $where, array $params): bool
    {
        $name = $this->resolve($collection);
        if ($name === null) {
            return false;
        }
        try {
            $this->connection->executeStatement('delete from ' . $this->table($name) . $where, $params);
        } catch (DriverException $e) {
            if (!$this->dialect->isRefusedData($e)) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * The document of the first row the statement returns, or null. The statement's `%1$s` is the
     * quoted table name.
     *
     * @param list<string> $params
     *
     * @return array<string, mixed>|null
     */
    private function findFirst(string $collection, string $statement, array $params): ?array
    {
        $name = $this->resolve($collection);
        if ($name === null) {
            return null;
        }
        $row = $this->connection->fetchAssociative(sprintf($statement, $this->table($name)), $params);

        return $row === false ? null : self::decode($row['contents'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(mixed $contents): ?array
    {
        if (!is_string($contents)) {
            return null;
        }
        $record = json_decode($contents, true);

        return is_array($record) ? $record : null;
    }

    private function rollBack(): void
    {
        if (!$this->connection->isTransactionActive()) {
            return;
        }
        try {
            $this->connection->rollBack();
        } catch (\Throwable) {
            // The original error is what the caller needs to see (a lost connection fails both).
        }
    }

    /**
     * The collection name reduced to letters, digits and underscores, as the legacy backend
     * reduces it, or null when that is not a collection (its three tables do not all exist).
     */
    private function resolve(string $collection): ?string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $collection) ?? '';
        if ($name === '') {
            return null;
        }
        if ($this->tables !== null && $this->isCollection($name)) {
            return $name;
        }
        // Look the tables up (again: one may have been created since they were last listed).
        $this->tableNamesIgnoreCase = (bool) $this->connection->fetchOne($this->dialect->tableNamesIgnoreCaseQuery());
        $names = array_map('strval', $this->connection->fetchFirstColumn($this->dialect->tableNamesQuery()));
        $this->tables = array_fill_keys($this->tableNamesIgnoreCase ? array_map('strtolower', $names) : $names, true);

        return $this->isCollection($name) ? $name : null;
    }

    /**
     * Whether the three tables of the collection exist. The name is used in SQL as given: a
     * server that ignores the case of table names resolves it (as it does for the legacy backend).
     */
    private function isCollection(string $name): bool
    {
        if ($this->tableNamesIgnoreCase) {
            $name = strtolower($name);
        }

        return isset($this->tables[$name], $this->tables[$name . '_metadata'], $this->tables[$name . '_search']);
    }

    private function table(string $name): string
    {
        return $this->dialect->quoteIdentifier($name);
    }

    /**
     * The joins, the where clause and the parameters of the criteria, or null when the query
     * cannot run (a Search below the top level).
     *
     * @return array{from: string, where: string, params: list<?string>, parsedSearchTerm: bool}|null
     */
    private function compile(string $name, Criteria $criteria): ?array
    {
        $context = new class () {
            public int $joins = 0;

            public bool $search = false;

            public bool $parsedSearchTerm = false;

            /** @var list<?string> */
            public array $params = [];
        };

        $conditions = [];
        foreach ($criteria->root()->children as $child) {
            $condition = $this->condition($child, $name, $context, true);
            if ($condition === null) {
                return null;
            }
            $conditions[] = $condition;
        }

        $table = $this->table($name);
        $metadata = $this->table($name . '_metadata');
        $id = $this->dialect->quoteIdentifier('_id');
        $from = '';
        for ($i = 1; $i <= $context->joins; $i++) {
            $from .= ' left join ' . $metadata . ' md' . $i . ' on md' . $i . '.' . $id . ' = ' . $table . '.' . $id;
        }
        if ($context->search) {
            $from .= ' left join ' . $this->table($name . '_search') . ' srch on srch.' . $id . ' = ' . $table . '.' . $id;
        }

        return [
            'from' => $from,
            'where' => $conditions === [] ? '' : ' where (' . implode(' and ', $conditions) . ')',
            'params' => $context->params,
            'parsedSearchTerm' => $context->parsedSearchTerm,
        ];
    }

    /**
     * The SQL condition for one criterion (appending its parameters and joins to the context),
     * or null when the query cannot run.
     *
     * @param object{joins: int, search: bool, parsedSearchTerm: bool, params: list<?string>} $context
     */
    private function condition(Criterion $criterion, string $name, object $context, bool $topLevel): ?string
    {
        if ($criterion instanceof AllOf || $criterion instanceof AnyOf) {
            $parts = [];
            foreach ($criterion->children as $child) {
                $part = $this->condition($child, $name, $context, false);
                if ($part === null) {
                    return null;
                }
                $parts[] = $part;
            }
            if ($parts === []) {
                return $criterion instanceof AllOf ? '(1 = 1)' : '(1 = 0)';
            }

            return '(' . implode($criterion instanceof AllOf ? ' and ' : ' or ', $parts) . ')';
        }

        if ($criterion instanceof Search) {
            if (!$topLevel) {
                return null;
            }
            $context->search = true;
            [$sql, $params, $parsed] = $this->dialect->searchCondition('srch.' . $this->dialect->quoteIdentifier('search'), $criterion->query, $this->booleanSearch, $this->bypassFulltext);
            array_push($context->params, ...$params);
            $context->parsedSearchTerm = $context->parsedSearchTerm || $parsed;

            return $sql;
        }

        [$operator, $operands] = match (true) {
            $criterion instanceof Equals => ['=', [$criterion->value]],
            $criterion instanceof NotEquals => ['!=', [$criterion->value]],
            $criterion instanceof LessThan => ['<', [$criterion->value]],
            $criterion instanceof GreaterThan => ['>', [$criterion->value]],
            $criterion instanceof In => ['in', $criterion->values],
            $criterion instanceof NotIn => ['not in', $criterion->values],
            default => throw new \LogicException('Unknown criterion ' . get_debug_type($criterion) . '.'),
        };
        $field = $criterion->field;
        $isList = $criterion instanceof In || $criterion instanceof NotIn;

        if (in_array($field, self::COLUMN_FIELDS, true)) {
            if ($isList && $operands === []) {
                // In nothing is false; not in nothing is true for the one value a column always has.
                return $criterion instanceof In ? '(1 = 0)' : '(1 = 1)';
            }
            $column = $this->table($name) . '.' . $this->dialect->quoteIdentifier($field);

            return '(' . $column . ' ' . $operator . ' ' . $this->operands($operands, $isList, $context) . ')';
        }

        if ($criterion instanceof In && $operands === []) {
            return '(1 = 0)';
        }
        $alias = 'md' . (++$context->joins);
        $context->params[] = $field;
        $nameMatches = $alias . '.' . $this->dialect->quoteIdentifier('name') . ' = ?';
        if ($criterion instanceof NotIn && $operands === []) {
            // Not in nothing: every record that has the field.
            return '(' . $nameMatches . ')';
        }

        return '(' . $nameMatches . ' and ' . $alias . '.' . $this->dialect->quoteIdentifier('value') . ' ' . $operator . ' ' . $this->operands($operands, $isList, $context) . ')';
    }

    /**
     * The placeholders for the operands (a parenthesised list for in / not in), their values
     * appended to the context's parameters as strings.
     *
     * @param list<string|int|float|bool|null>                        $operands
     * @param object{joins: int, search: bool, parsedSearchTerm: bool, params: list<?string>} $context
     */
    private function operands(array $operands, bool $isList, object $context): string
    {
        foreach ($operands as $operand) {
            $context->params[] = self::bind($operand);
        }
        $placeholders = implode(', ', array_fill(0, count($operands), '?'));

        return $isList ? '(' . $placeholders . ')' : $placeholders;
    }

    /**
     * The operand as the legacy backend binds it: as a string (PDO's conversion), null as NULL.
     */
    private static function bind(string|int|float|bool|null $operand): ?string
    {
        return match (true) {
            $operand === null => null,
            $operand === true => '1',
            $operand === false => '',
            default => (string) $operand,
        };
    }
}
