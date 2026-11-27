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
 * A RecordStore on a Doctrine DBAL connection, over the tables the schema migrations create
 * (Idno/Storage/Migrations/): one table `idno_<collection>` per collection, a row per record with
 * the document as JSON text in `data`, the search text in `search`, and the columns queries use
 * (id, uuid, type, owner, created as Unix seconds, publish_status). What is engine-specific is in
 * the Dialect (MySqlDialect for MySQL and MariaDB, SqliteDialect).
 *
 *  - Collections: `c` is the table `idno_<c>` (the name reduced to letters, digits and
 *    underscores, and lower-cased) when the SchemaCatalog lists it and `c` is not one of
 *    SYSTEM_TABLES. Any other name is no collection: writes return false, reads find nothing, and
 *    no statement is sent for it. The catalog is read once; whoever creates a table later calls
 *    SchemaCatalog::forget().
 *  - save() is one upsert on `id` (Dialect::upsertById()): `data` is the record json_encode()d as
 *    the old store encoded it, `type` is TypeMap::typeFor() of its entity_subtype (default
 *    Idno\Common\Entity), `created` its timestamp (default now), `search` RecordIndex::searchText().
 *    A record read back is json_decode($data, true): the document as given, never rebuilt from the
 *    columns. Another record holding the uuid makes the save return false, and both records stay.
 *  - Queries: a field of RecordIndex::COLUMN_FIELDS is compared with its column (_id is `id`,
 *    entity_subtype is `type`, with each operand expanded through TypeMap::typesFor(), except one
 *    with a leading backslash, which is compared as it is and so matches nothing), `created`
 *    with operands read by MySqlSemantics::epoch() in the PHP default time zone of the query (an
 *    operand it rejects makes the query match nothing); any other field with its query values
 *    (RecordIndex::queryValues(): the scalar values of a list or object), computed from `data` by
 *    Dialect::fieldCondition(), the field named by its exact name. Values are bound as strings
 *    (true is '1', false ''), as the old store bound them, except that a float operand of a document field is
 *    RecordIndex::floatText(), the text its query value has, so that a float finds itself.
 *    A field the SchemaCatalog lists as indexed for the collection (#[IndexedField], made by
 *    IndexedFields) is compared with its indexed value instead (Dialect::indexedFieldValue(), a
 *    plain comparison its index serves), which is its query value when the field holds a scalar and
 *    NULL otherwise: such a field matches scalar values only (a list or object in it is not
 *    found); for every other record the result is the same with or without the index.
 *    Results are ordered by `created`, newest first, then `id` descending.
 *  - Search: the old store's rules, against the `search` column (Dialect::searchTextCondition()); a search
 *    anywhere but at the top level makes the query match nothing, as do an AnyOf with no children
 *    and an In with no values.
 *  - Errors: as the RecordStore interface states. A query the database rejects for its operand
 *    (Dialect::isRejectedOperand(): MySQL's boolean-mode search parser) matches nothing; a write it
 *    refuses (Dialect::isRefusedData()) returns false; anything else is thrown.
 *
 * Not registered by the container's directory scan (see config/services.php); built with new.
 */
final class JsonRecordStore implements RecordStore
{
    /** The tables `idno_<name>` that are no collections. */
    public const SYSTEM_TABLES = ['settings', 'sessions', 'migrations', 'messages', 'upgrade_progress', 'upgrade_rejects'];

    private const TABLE_PREFIX = 'idno_';

    /** The columns of a collection table, in the order save() writes them (an indexed field adds a generated column). */
    public const COLUMNS = ['id', 'uuid', 'type', 'owner', 'created', 'publish_status', 'data', 'search'];

    /** The column each field of RecordIndex::COLUMN_FIELDS is queried on. */
    private const FIELD_COLUMNS = ['uuid' => 'uuid', '_id' => 'id', 'entity_subtype' => 'type', 'owner' => 'owner', 'publish_status' => 'publish_status', 'created' => 'created'];

    /**
     * @param \Closure(string): string $uncurlQuotes   the quote normaliser applied to the search text (QuoteNormaliser::normalise())
     * @param bool|\Closure(): bool     $booleanSearch  the site's `boolean_search` setting, or a closure that
     *                                                   reads it: called each time a search condition is built
     * @param bool|\Closure(): bool     $bypassFulltext the site's `bypass_fulltext_search` setting, or a closure, likewise
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Dialect $dialect,
        private readonly RecordDefaults $defaults,
        private readonly TypeMap $types,
        private readonly SchemaCatalog $catalog,
        private readonly \Closure $uncurlQuotes,
        private readonly bool|\Closure $booleanSearch,
        private readonly bool|\Closure $bypassFulltext,
    )
    {
    }

    public function save(string $collection, array $record): string|false
    {
        $table = $this->table($collection);
        if ($table === null) {
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

        $data = json_encode($record);
        if ($data === false) {
            return false;
        }
        $depthLimit = $this->dialect->jsonDepthLimit($this->connection);
        if ($depthLimit !== null && self::levels(json_decode($data, true)) > $depthLimit) {
            // Deeper than the server's JSON functions accept: refused data, before it is written. A
            // table without generated columns would store it, and every query on a document field
            // of the collection would then fail.
            return false;
        }

        $now = time();
        $columns = RecordIndex::columns($record, $now);
        $values = [
            'id' => $columns['_id'],
            'uuid' => $columns['uuid'],
            'type' => $this->types->typeFor($columns['entity_subtype']),
            'owner' => $columns['owner'],
            'created' => (string) RecordIndex::created($record, $now),
            'publish_status' => $columns['publish_status'],
            'data' => $data,
            'search' => RecordIndex::searchText($record, $this->uncurlQuotes),
        ];

        try {
            $affected = (int) $this->connection->executeStatement(
                $this->dialect->upsertById($table, self::COLUMNS),
                array_map(static fn (string $column): string => $values[$column], self::COLUMNS),
            );
        } catch (DriverException $e) {
            if (!$this->dialect->isRefusedData($e)) {
                throw $e;
            }

            return false;
        }

        // No row changed: the record was there already as it is, or (MySQL) another record holds its uuid.
        if ($affected === 0 && !$this->holds($table, $values['id'], $values['uuid'])) {
            return false;
        }

        return $values['id'];
    }

    public function findById(string $collection, string $id): ?array
    {
        return $this->findFirst($collection, ' where ' . $this->dialect->quoteIdentifier('id') . ' = ?', [$id]);
    }

    public function findByUuid(string $collection, string $uuid): ?array
    {
        return $this->findFirst($collection, ' where ' . $this->dialect->quoteIdentifier('uuid') . ' = ?', [$uuid]);
    }

    public function findLatest(string $collection): ?array
    {
        return $this->findFirst($collection, $this->order() . ' ' . $this->dialect->limit(1, 0), []);
    }

    public function find(string $collection, Criteria $criteria, int $limit, int $offset): array
    {
        if ($limit <= 0 || $offset < 0) {
            return [];
        }
        $table = $this->table($collection);
        $query = $table === null ? null : $this->compile($table, $criteria);
        if ($table === null || $query === null) {
            return [];
        }
        $sql = 'select t.' . $this->dialect->quoteIdentifier('data') . ' from ' . $this->dialect->quoteIdentifier($table) . ' t'
            . $query['where'] . $this->order() . ' ' . $this->dialect->limit($limit, $offset);

        try {
            $documents = $this->connection->fetchFirstColumn($sql, $query['params']);
        } catch (DriverException $e) {
            if ($this->dialect->isRejectedOperand($e, $query['parsedSearchTerm'])) {
                return [];
            }
            throw $e;
        }

        $records = [];
        foreach ($documents as $data) {
            $record = self::decode($data);
            if ($record !== null) {
                $records[] = $record;
            }
        }

        return $records;
    }

    public function count(string $collection, Criteria $criteria): int
    {
        $table = $this->table($collection);
        $query = $table === null ? null : $this->compile($table, $criteria);
        if ($table === null || $query === null) {
            return 0;
        }
        $sql = 'select count(*) from ' . $this->dialect->quoteIdentifier($table) . ' t' . $query['where'];

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
        return $this->write($collection, ' where ' . $this->dialect->quoteIdentifier('id') . ' = ?', [$id]);
    }

    public function claim(string $collection, string $id): bool
    {
        $table = $this->table($collection);
        if ($table === null) {
            return false;
        }
        try {
            // One statement: the rows it removed are the claim, whatever else is deleting the record now
            return (int) $this->connection->executeStatement('delete from ' . $this->dialect->quoteIdentifier($table) . ' where ' . $this->dialect->quoteIdentifier('id') . ' = ?', [$id]) > 0;
        } catch (DriverException $e) {
            if ($e instanceof \Doctrine\DBAL\Exception\RetryableException || !$this->dialect->isRefusedData($e)) {
                throw $e; // a busy database is not "somebody else has it"
            }

            return false;
        }
    }

    public function deleteAll(string $collection): bool
    {
        return $this->write($collection, '', []);
    }

    /**
     * The table a collection is kept in, whether or not it exists: `idno_` and the name reduced to
     * letters, digits and underscores and lower-cased; null when the name can be no collection (it
     * reduces to nothing, or names one of SYSTEM_TABLES). The one place this reduction is made
     * (IndexedFields uses it too).
     */
    public static function tableName(string $collection): ?string
    {
        $name = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $collection) ?? '');
        if ($name === '' || in_array($name, self::SYSTEM_TABLES, true)) {
            return null;
        }

        return self::TABLE_PREFIX . $name;
    }

    /**
     * The table of the collection, or null when the name is no collection.
     */
    private function table(string $collection): ?string
    {
        $table = self::tableName($collection);

        return $table !== null && $this->catalog->hasTable($table) ? $table : null;
    }

    /**
     * Whether the table holds the record with this id and uuid.
     */
    private function holds(string $table, string $id, string $uuid): bool
    {
        $sql = 'select count(*) from ' . $this->dialect->quoteIdentifier($table)
            . ' where ' . $this->dialect->quoteIdentifier('id') . ' = ? and ' . $this->dialect->quoteIdentifier('uuid') . ' = ?';

        return (int) $this->connection->fetchOne($sql, [$id, $uuid]) > 0;
    }

    /**
     * ` order by created desc, id desc` on the table aliased `t` or not aliased.
     */
    private function order(): string
    {
        return ' order by ' . $this->dialect->quoteIdentifier('created') . ' desc, ' . $this->dialect->quoteIdentifier('id') . ' desc';
    }

    /**
     * `delete from <table>` with the condition: true, or false when the collection does not exist
     * or the database refuses the statement.
     *
     * @param list<string> $params
     */
    private function write(string $collection, string $where, array $params): bool
    {
        $table = $this->table($collection);
        if ($table === null) {
            return false;
        }
        try {
            $this->connection->executeStatement('delete from ' . $this->dialect->quoteIdentifier($table) . $where, $params);
        } catch (DriverException $e) {
            if (!$this->dialect->isRefusedData($e)) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * The document of the first row of `select data from <table><clauses>`, or null.
     *
     * @param list<string> $params
     *
     * @return array<string, mixed>|null
     */
    private function findFirst(string $collection, string $clauses, array $params): ?array
    {
        $table = $this->table($collection);
        if ($table === null) {
            return null;
        }
        $data = $this->connection->fetchOne('select ' . $this->dialect->quoteIdentifier('data') . ' from ' . $this->dialect->quoteIdentifier($table) . $clauses, $params);

        return $data === false ? null : self::decode($data);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(mixed $data): ?array
    {
        if (!is_string($data)) {
            return null;
        }
        $record = json_decode($data, true);

        return is_array($record) ? $record : null;
    }

    /**
     * A search setting: the bool, or what the closure returns now.
     */
    private static function setting(bool|\Closure $setting): bool
    {
        return is_bool($setting) ? $setting : (bool) $setting();
    }

    /**
     * The where clause and the parameters of the criteria (on the table aliased `t`), or null when
     * the query matches nothing whatever the records (a Search below the top level, a `created`
     * operand that is not a datetime).
     *
     * @return array{where: string, params: list<?string>, parsedSearchTerm: bool}|null
     */
    private function compile(string $table, Criteria $criteria): ?array
    {
        $context = new class ($table) {
            public bool $parsedSearchTerm = false;

            /** @var list<?string> */
            public array $params = [];

            public function __construct(public readonly string $table)
            {
            }
        };

        $conditions = [];
        foreach ($criteria->root()->children as $child) {
            $condition = $this->condition($child, $context, true);
            if ($condition === null) {
                return null;
            }
            $conditions[] = $condition;
        }

        return [
            'where' => $conditions === [] ? '' : ' where (' . implode(' and ', $conditions) . ')',
            'params' => $context->params,
            'parsedSearchTerm' => $context->parsedSearchTerm,
        ];
    }

    /**
     * The SQL condition for one criterion (appending its parameters to the context), or null when
     * the query matches nothing.
     *
     * @param object{table: string, parsedSearchTerm: bool, params: list<?string>} $context
     */
    private function condition(Criterion $criterion, object $context, bool $topLevel): ?string
    {
        if ($criterion instanceof AllOf || $criterion instanceof AnyOf) {
            $parts = [];
            foreach ($criterion->children as $child) {
                $part = $this->condition($child, $context, false);
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
            [$sql, $params, $parsed] = $this->dialect->searchTextCondition('t.' . $this->dialect->quoteIdentifier('search'), $criterion->query, self::setting($this->booleanSearch), self::setting($this->bypassFulltext));
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
        if (isset(self::FIELD_COLUMNS[$criterion->field])) {
            return $this->columnCondition($criterion->field, $operator, array_map(self::bind(...), $operands), $context);
        }

        if ($operator === 'in' && $operands === []) {
            return '(1 = 0)';
        }
        // A float is compared as the text of its query value (RecordIndex::floatText()), so it finds itself.
        $values = array_map(static fn (string|int|float|bool|null $operand): ?string => is_float($operand) ? RecordIndex::floatText($operand) : self::bind($operand), $operands);
        if (in_array($criterion->field, $this->catalog->indexedFields(substr($context->table, strlen(self::TABLE_PREFIX))), true)) {
            return $this->indexedCondition($criterion->field, $operator, $values, $context);
        }
        $path = self::path($criterion->field);
        if ($path === null) {
            // No document holds a field of that name: no record has a value to compare.
            return '(1 = 0)';
        }
        // Not in nothing: every record whose field has a value.
        [$operator, $placeholders] = $operator === 'not in' && $values === [] ? ['is not', 'null'] : [$operator, self::placeholders($operator, $values)];
        [$sql, $pathPlaceholders] = $this->dialect->fieldCondition($this->dialect->quoteIdentifier($context->table), 't', $operator, $placeholders);
        array_push($context->params, ...array_fill(0, $pathPlaceholders, $path));
        if ($placeholders !== 'null') {
            array_push($context->params, ...$values);
        }

        return '(' . $sql . ')';
    }

    /**
     * The condition on a declared indexed field: a comparison of its indexed value
     * (Dialect::indexedFieldValue(), NULL unless the field holds a scalar), which its index serves.
     * The same values and operators as the condition on query values, so the result is the same
     * for every record whose field is absent or a scalar.
     *
     * @param list<?string>                                                       $values not empty for `in`
     * @param object{table: string, parsedSearchTerm: bool, params: list<?string>} $context
     */
    private function indexedCondition(string $field, string $operator, array $values, object $context): string
    {
        $value = $this->dialect->indexedFieldValue('t', $field);
        if ($operator === 'not in' && $values === []) {
            // Not in nothing: every record whose field has a value.
            return '(' . $value . ' is not null)';
        }
        array_push($context->params, ...$values);

        return '(' . $this->dialect->textComparison($value, $operator, self::placeholders($operator, $values)) . ')';
    }

    /**
     * The condition on a column of the table, or null when an operand of `created` is not a datetime.
     *
     * @param list<?string>                                         $values
     * @param object{table: string, parsedSearchTerm: bool, params: list<?string>} $context
     */
    private function columnCondition(string $field, string $operator, array $values, object $context): ?string
    {
        if ($field === 'created') {
            return $this->createdCondition($operator, $values, $context);
        }
        $isList = $operator === 'in' || $operator === 'not in';
        if ($field === 'entity_subtype') {
            if ($operator === '=' || $operator === '!=') {
                $operator = $operator === '=' ? 'in' : 'not in';
                $isList = true;
            }
            $types = [];
            foreach ($values as $value) {
                if ($value === null) {
                    $types[] = null;
                } elseif (str_starts_with($value, '\\')) {
                    // No class is stored under a name with a leading backslash: compared as it is
                    // (it matches nothing), as the old store compared it, not resolved to its class.
                    $types[] = $value;
                } elseif ($isList) {
                    array_push($types, ...$this->types->typesFor($value));
                } else {
                    $types[] = $this->types->typeFor($value);
                }
            }
            if ($isList) {
                // Each type once (a NULL operand stays: it makes `not in` unknown, as before).
                $strings = array_values(array_unique(array_filter($types, 'is_string')));
                $types = in_array(null, $types, true) ? [...$strings, null] : $strings;
            }
            $values = $types;
        }
        if ($isList && $values === []) {
            // In nothing is false; not in nothing is true for the one value a column always has.
            return $operator === 'in' ? '(1 = 0)' : '(1 = 1)';
        }

        $column = 't.' . $this->dialect->quoteIdentifier(self::FIELD_COLUMNS[$field]);
        array_push($context->params, ...$values);

        return '(' . $this->dialect->textComparison($column, $operator, self::placeholders($operator, $values)) . ')';
    }

    /**
     * The condition on `created` (whole Unix seconds), or null when an operand is not a datetime.
     * Each operand is read by MySqlSemantics::epoch(); one with a fraction is never sent to the
     * database (MySQL and MariaDB compare an integer column with a fractional operand inexactly):
     * it equals no timestamp, differs from every one, `< 10.5` is `<= 10` and `> 10.5` is `> 10`.
     *
     * @param list<?string>                                                       $values
     * @param object{table: string, parsedSearchTerm: bool, params: list<?string>} $context
     */
    private function createdCondition(string $operator, array $values, object $context): ?string
    {
        $operands = [];
        foreach ($values as $value) {
            $epoch = $value === null ? null : MySqlSemantics::epoch($value);
            if ($value !== null && $epoch === null) {
                return null;
            }
            $operands[] = $epoch;
        }
        $whole = array_values(array_filter($operands, static fn (?string $epoch): bool => $epoch === null || !str_contains($epoch, '.')));
        if ($operator === 'in' || $operator === 'not in') {
            if ($whole === []) {
                return $operator === 'in' ? '(1 = 0)' : '(1 = 1)';
            }
            $operands = $whole;
        } elseif ($operands[0] !== null && str_contains($operands[0], '.')) {
            [$integer] = explode('.', $operands[0]);
            // The whole second at or below the instant (-99.75 is in second -100).
            $floor = (string) (str_starts_with($integer, '-') ? (int) $integer - 1 : (int) $integer);
            if ($operator === '=' || $operator === '!=') {
                return $operator === '=' ? '(1 = 0)' : '(1 = 1)';
            }
            [$operator, $operands] = [$operator === '<' ? '<=' : $operator, [$floor]];
        }
        array_push($context->params, ...$operands);

        return '(t.' . $this->dialect->quoteIdentifier('created') . ' ' . $operator . ' ' . self::placeholders($operator, $operands) . ')';
    }

    /**
     * The JSON path of a top-level field (`$.` and the name as json_encode() writes it, as the
     * document's own keys are written), or null when the name is not valid UTF-8 (no document
     * holds such a field).
     */
    private static function path(string $field): ?string
    {
        $name = json_encode($field);

        return $name === false ? null : '$.' . $name;
    }

    /**
     * `?`, or a parenthesised list of them for in and not in.
     *
     * @param list<?string> $values
     */
    private static function placeholders(string $operator, array $values): string
    {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return $operator === 'in' || $operator === 'not in' ? '(' . $placeholders . ')' : $placeholders;
    }

    /**
     * The operand as the old store bound it: as a string (PDO's conversion), null as NULL.
     */
    /**
     * The nesting of a decoded document in objects and arrays (the document itself is 1; a scalar 0).
     */
    private static function levels(mixed $value): int
    {
        if (!is_array($value)) {
            return 0;
        }
        $deepest = 0;
        foreach ($value as $item) {
            if (is_array($item)) {
                $deepest = max($deepest, self::levels($item));
            }
        }

        return 1 + $deepest;
    }

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
