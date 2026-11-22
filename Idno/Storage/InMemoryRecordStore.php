<?php

declare(strict_types=1);

namespace Idno\Storage;

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

/**
 * A RecordStore that keeps its records in memory: the reference implementation unit tests of
 * code that uses storage run against, and the second implementation the storage contract
 * (Tests/Storage/Contract/RecordStoreContract) holds to the same behaviour as MySQL.
 *
 * It does not interpret criteria against the raw record. Like the SQL stores, it evaluates them
 * against what RecordIndex derives when a record is saved (the query values of each document
 * field, the search text and the columns), so the rules are SQL's, not PHP's: those of
 * JsonRecordStore, whose dialects compute the same query values from the stored document. The
 * rules MySQL applies by itself (the `created` literals, case folding, search) are in
 * MySqlSemantics:
 *
 *  - Operands and indexed values are compared AS STRINGS, the way MySQL compares them when
 *    every parameter is bound as a string: true is '1', false is '', an integer its decimal
 *    string. A null operand is NULL: no comparison with it is ever true.
 *  - Text comparison ignores case (as MySQL's collation does) for values; a field is found by
 *    its exact name, as the document holds it. `$lt` and `$gt` compare as text ('10' is less than
 *    '5'). Text is folded to UPPER case (mb_strtoupper(), so `ß` equals `SS`) before it is
 *    compared, so the ASCII characters between Z and a (`[ \ ] ^ _` and the backtick) sort after
 *    the letters ('a_' is greater than 'aZ'), where MySQL's utf8mb4_unicode_520_ci sorts them
 *    before the letters (the contract hook expectTextToSortFoldedToUpperCase). Not emulated:
 *    accent insensitivity ('e' = 'é', expectAccentsToBeIgnored), trailing-space padding
 *    (expectTrailingSpacesToBeIgnored), and the collation weights of non-ASCII characters.
 *  - The fields uuid, _id, entity_subtype, owner, publish_status and created are compared
 *    against the record's columns; every other field against its query values
 *    (RecordIndex::queryValues(): whole values; the scalar elements of a list or member values
 *    of an object; elements that are arrays or objects not at all; a float as json_encode()
 *    writes it, and a float operand likewise, RecordIndex::floatText()). Each criterion is evaluated
 *    independently against the values, so on a list field two criteria may be satisfied by
 *    different elements, and a record without the field satisfies none of them ($not and
 *    $not-$in included).
 *  - `created` is compared as a datetime. The operand may be written 'Y-m-d H:i:s', with
 *    unpadded fields ('2023-6-4 1:2:3'), date only (midnight), with a 'T' instead of the space,
 *    and with up to six fractional digits (compared exactly, no rounding: '..:00.5' is greater
 *    than a record created at '..:00'). An operand for it that is anything else (an integer
 *    timestamp, '', '2023-02-30 00:00:00', '24:00:00', true) makes MySQL fail the whole query,
 *    which the legacy backend reports as no records: here the query matches nothing, find()
 *    gives [] and count() 0, wherever in the criteria that operand is. Known limit: MySQL
 *    accepts more literal forms (a time without seconds, 'Ymd', a trailing space or time zone
 *    suffix, more than six fractional digits); this store treats them as failures too.
 *  - A Search anywhere but directly under the top level (inside an AnyOf or a nested AllOf) makes
 *    the whole query match nothing: MySQL joins the search table only for a top-level `$search`
 *    and fails the query otherwise.
 *  - An AnyOf with no children matches nothing (the list form of `$or`); an AllOf with
 *    none matches everything. An In or NotIn with no values (which the legacy parser never
 *    produces) is the mathematical one: In matches nothing, NotIn every record that has the field.
 *  - Search: a term of more than 5 bytes is split on whitespace and matches a record whose
 *    search text contains ANY of the words as a case-insensitive substring (MySQL: whole words,
 *    boolean mode; its operators + - * " ( ) < > ~ are NOT emulated and are plain characters
 *    here, and neither are stop words or the minimum word length). A term of at most 5 bytes is
 *    one substring, matched like SQL `LIKE '%term%'`: '%' and '_' are wildcards and a backslash
 *    escapes the next character (a trailing backslash is a literal here; in MySQL it escapes the
 *    closing '%'). MySQL's full-text parser also splits words on punctuation ('xx,hello' finds
 *    "hello world" there); here words are split on whitespace only.
 *  - Results are distinct records ordered by `created` descending, then by _id descending (as
 *    text ignoring case), then paged; a limit of 0 or less, or a negative offset, finds nothing. count() is the number
 *    find() returns unpaged.
 *  - A record is stored as the JSON document legacy code would store, so types are exactly those
 *    json_encode()/json_decode() give back; a record that cannot be encoded (invalid UTF-8, NAN)
 *    is not saved (false). The uuid is unique per collection: saving another _id with an existing
 *    uuid fails and changes nothing.
 *
 * As JsonRecordStore, and unlike the old MySQL backend (since removed): an array in tags, title,
 * description or handle contributes `Array` to the search text and an array body nothing (the old
 * backend raised); values are compared whole, records with an equal `created` are ordered by _id,
 * nested arrays and objects are not queryable, and field names are matched exactly; the list
 * form of `$or` counts 0 (the old backend failed the count); there are no limits on the range
 * of `created` or on the length of a field name.
 */
final class InMemoryRecordStore implements RecordStore
{
    /** The collections that exist from the start (those of the old schema). */
    public const DEFAULT_COLLECTIONS = ['entities', 'reader', 'config', 'session', 'site'];

    /**
     * @var array<string, array<string, array{record: array<int|string, mixed>, columns: array<string, string|null>, values: array<string, list<string>>, search: string}>>
     */
    private array $collections = [];

    /** @var array<string, array<string, string>> uuid => _id, per collection */
    private array $uuids = [];

    private readonly \Closure $uncurl;

    /**
     * @param (callable(string): string)|null $uncurl      the quote-normalising function applied to search
     *                                                      text (QuoteNormaliser::normalise()); none by default
     * @param list<string>                    $existing    the names of the collections that exist
     */
    public function __construct(
        private readonly RecordDefaults $defaults,
        ?callable $uncurl = null,
        private readonly array $existing = self::DEFAULT_COLLECTIONS,
    )
    {
        $this->uncurl = $uncurl === null ? static fn (string $text): string => $text : $uncurl(...);
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
        $document = json_decode($contents, true);
        if (!is_array($document)) {
            return false;
        }

        $now = time();
        $columns = RecordIndex::columns($record, $now);
        $id = $columns['_id'];
        $uuid = $columns['uuid'];
        // The uuid is the primary key: another record holding it makes the save fail.
        if (($this->uuids[$name][$uuid] ?? $id) !== $id) {
            return false;
        }

        $previous = $this->collections[$name][$id]['columns']['uuid'] ?? null;
        if ($previous !== null && $previous !== $uuid) {
            unset($this->uuids[$name][$previous]);
        }
        $this->uuids[$name][$uuid] = $id;
        $this->collections[$name][$id] = [
            'record' => $document,
            'columns' => $columns,
            'values' => RecordIndex::queryValues($record, $now),
            'search' => RecordIndex::searchText($record, $this->uncurl),
        ];

        return $id;
    }

    public function findById(string $collection, string $id): ?array
    {
        $name = $this->resolve($collection);

        return $name === null ? null : ($this->collections[$name][$id]['record'] ?? null);
    }

    public function findByUuid(string $collection, string $uuid): ?array
    {
        $name = $this->resolve($collection);
        if ($name === null || !isset($this->uuids[$name][$uuid])) {
            return null;
        }

        return $this->collections[$name][$this->uuids[$name][$uuid]]['record'];
    }

    public function findLatest(string $collection): ?array
    {
        $name = $this->resolve($collection);
        $entries = $name === null ? [] : ($this->collections[$name] ?? []);
        $latest = null;
        foreach ($entries as $entry) {
            if ($latest === null || self::newestFirst($entry, $latest) < 0) {
                $latest = $entry;
            }
        }

        return $latest['record'] ?? null;
    }

    public function find(string $collection, Criteria $criteria, int $limit, int $offset): array
    {
        if ($limit <= 0 || $offset < 0) {
            return [];
        }
        $matching = $this->matching($collection, $criteria);
        usort($matching, self::newestFirst(...));

        return array_values(array_map(
            static fn (array $entry): array => $entry['record'],
            array_slice($matching, $offset, $limit),
        ));
    }

    public function count(string $collection, Criteria $criteria): int
    {
        return count($this->matching($collection, $criteria));
    }

    public function delete(string $collection, string $id): bool
    {
        $name = $this->resolve($collection);
        if ($name === null) {
            return false;
        }
        if (isset($this->collections[$name][$id])) {
            unset($this->uuids[$name][$this->collections[$name][$id]['columns']['uuid']]);
            unset($this->collections[$name][$id]);
        }

        return true;
    }

    public function deleteAll(string $collection): bool
    {
        $name = $this->resolve($collection);
        if ($name === null) {
            return false;
        }
        $this->collections[$name] = [];
        $this->uuids[$name] = [];

        return true;
    }

    /**
     * The collection name reduced to letters, digits and underscores, as the SQL stores do,
     * or null when no such collection exists.
     */
    private function resolve(string $collection): ?string
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '', $collection) ?? '';

        return in_array($name, $this->existing, true) ? $name : null;
    }

    /**
     * @return list<array{record: array<int|string, mixed>, columns: array<string, string|null>, values: array<string, list<string>>, search: string}>
     */
    private function matching(string $collection, Criteria $criteria): array
    {
        $name = $this->resolve($collection);
        if ($name === null || !self::isExecutable($criteria->root())) {
            return [];
        }

        return array_values(array_filter(
            $this->collections[$name] ?? [],
            fn (array $entry): bool => $this->matches($criteria->root(), $entry),
        ));
    }

    /**
     * MySQL fails a query that compares `created` with something that is not a datetime, or that
     * has a `$search` anywhere but at the top level; the legacy backend then reports no records.
     */
    private static function isExecutable(Criterion $criterion, bool $isRoot = true, bool $topLevel = false): bool
    {
        if ($criterion instanceof AllOf || $criterion instanceof AnyOf) {
            foreach ($criterion->children as $child) {
                // Only the children of the root are at the top level.
                if (!self::isExecutable($child, false, $isRoot)) {
                    return false;
                }
            }

            return true;
        }
        if ($criterion instanceof Search) {
            return $topLevel;
        }
        if ($criterion->field !== 'created') {
            return true;
        }
        $operands = $criterion instanceof In || $criterion instanceof NotIn ? $criterion->values : [$criterion->value];
        foreach ($operands as $operand) {
            $text = self::operand($operand);
            if ($text !== null && MySqlSemantics::datetime($text) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{record: array<int|string, mixed>, columns: array<string, string|null>, values: array<string, list<string>>, search: string} $entry
     */
    private function matches(Criterion $criterion, array $entry): bool
    {
        if ($criterion instanceof AllOf) {
            foreach ($criterion->children as $child) {
                if (!$this->matches($child, $entry)) {
                    return false;
                }
            }

            return true;
        }
        if ($criterion instanceof AnyOf) {
            foreach ($criterion->children as $child) {
                if ($this->matches($child, $entry)) {
                    return true;
                }
            }

            return false;
        }
        if ($criterion instanceof Search) {
            return MySqlSemantics::searchMatches($criterion->query, $entry['search']);
        }

        $values = self::valuesOf($entry, $criterion->field);
        $field = $criterion->field;

        return match (true) {
            $criterion instanceof Equals => self::any($values, self::operand($criterion->value, $field), static fn (string $v, string $o): bool => self::equal($v, $o)),
            $criterion instanceof NotEquals => self::any($values, self::operand($criterion->value, $field), static fn (string $v, string $o): bool => !self::equal($v, $o)),
            $criterion instanceof LessThan => self::any($values, self::operand($criterion->value, $field), static fn (string $v, string $o): bool => self::compare($v, $o) < 0),
            $criterion instanceof GreaterThan => self::any($values, self::operand($criterion->value, $field), static fn (string $v, string $o): bool => self::compare($v, $o) > 0),
            $criterion instanceof In => self::matchesIn($values, array_map(static fn (string|int|float|bool|null $o): ?string => self::operand($o, $field), $criterion->values)),
            $criterion instanceof NotIn => self::matchesNotIn($values, array_map(static fn (string|int|float|bool|null $o): ?string => self::operand($o, $field), $criterion->values)),
            default => throw new \LogicException('Unknown criterion ' . get_debug_type($criterion) . '.'),
        };
    }

    /**
     * The order of find(): newest `created` first, then the greater _id first (compared as text
     * ignoring case).
     *
     * @param array{columns: array<string, string|null>} $a
     * @param array{columns: array<string, string|null>} $b
     */
    private static function newestFirst(array $a, array $b): int
    {
        return strcmp((string) $b['columns']['created'], (string) $a['columns']['created'])
            ?: MySqlSemantics::compare((string) $b['columns']['_id'], (string) $a['columns']['_id']);
    }

    /**
     * The values the record has for a field: its column, or its query values (by the field's exact name).
     *
     * @param array{record: array<int|string, mixed>, columns: array<string, string|null>, values: array<string, list<string>>, search: string} $entry
     *
     * @return list<string>
     */
    private static function valuesOf(array $entry, string $field): array
    {
        if (in_array($field, RecordIndex::COLUMN_FIELDS, true)) {
            return [(string) $entry['columns'][$field]];
        }
        return $entry['values'][$field] ?? [];
    }

    /**
     * Whether some value satisfies the test against the operand. A null operand is SQL NULL:
     * nothing is ever equal, unequal, less or greater.
     *
     * @param list<string>                      $values
     * @param callable(string, string): bool    $test
     */
    private static function any(array $values, ?string $text, callable $test): bool
    {
        if ($text === null) {
            return false;
        }
        foreach ($values as $value) {
            if ($test($value, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string>     $values
     * @param list<?string>    $texts
     */
    private static function matchesIn(array $values, array $texts): bool
    {
        foreach ($values as $value) {
            foreach ($texts as $text) {
                if ($text !== null && self::equal($value, $text)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * SQL's `not in`: a NULL in the list makes it unknown for every value that is not in the list.
     *
     * @param list<string>     $values
     * @param list<?string>    $texts
     */
    private static function matchesNotIn(array $values, array $texts): bool
    {
        if (in_array(null, $texts, true)) {
            return false;
        }
        foreach ($values as $value) {
            foreach ($texts as $text) {
                if (self::equal($value, (string) $text)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * The operand as MySQL sees it with every parameter bound as a string, except a float compared
     * with a document field, which is RecordIndex::floatText(), as the field's query value is.
     */
    private static function operand(string|int|float|bool|null $operand, ?string $field = null): ?string
    {
        $text = match (true) {
            $operand === null => null,
            $operand === true => '1',
            $operand === false => '',
            // A float compared with a document field is the text of its query value, so it finds itself.
            is_float($operand) && $field !== null && !in_array($field, RecordIndex::COLUMN_FIELDS, true) => RecordIndex::floatText($operand),
            default => (string) $operand,
        };

        // A datetime operand is compared in its canonical form (a query with an invalid one never gets here).
        return $field === 'created' && $text !== null ? (MySqlSemantics::datetime($text) ?? $text) : $text;
    }

    private static function equal(string $a, string $b): bool
    {
        return MySqlSemantics::compare($a, $b) === 0;
    }

    private static function compare(string $a, string $b): int
    {
        return MySqlSemantics::compare($a, $b);
    }
}
