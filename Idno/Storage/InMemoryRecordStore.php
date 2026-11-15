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
 * against what RecordIndex derives when a record is saved (the metadata rows, the search text and
 * the columns), so the rules are SQL's, not PHP's:
 *
 *  - Operands and indexed values are compared AS STRINGS, the way MySQL compares them when
 *    every parameter is bound as a string: true is '1', false is '', an integer its decimal
 *    string. A null operand is NULL: no comparison with it is ever true.
 *  - Text comparison ignores case (MySQL's default collation), for field names as well as
 *    values; `$lt` and `$gt` compare as text ('10' is less than '5'). Text is folded to UPPER
 *    case before it is compared, as utf8mb3_general_ci does, so the ASCII characters between Z
 *    and a (`[ \ ] ^ _` and the backtick) sort after the letters ('a_' is greater than 'aZ').
 *    Not emulated: accent insensitivity ('e' = 'é'), trailing-space padding, and the collation
 *    weights of non-ASCII characters.
 *  - The fields uuid, _id, entity_subtype, owner, publish_status and created are compared
 *    against the record's columns; every other field against its metadata rows. Each criterion
 *    is evaluated independently against the rows, so on a list field two criteria may be
 *    satisfied by different elements, and a record without the field satisfies none of them
 *    ($not and $not-$in included).
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
 *  - An AnyOf with no children matches nothing (the MongoDB list form of `$or`); an AllOf with
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
 *  - Results are distinct records ordered by `created` descending (ties in insertion order),
 *    then paged; a limit of 0 or less, or a negative offset, finds nothing. count() is the number
 *    find() returns unpaged.
 *  - A record is stored as the JSON document legacy code would store, so types are exactly those
 *    json_encode()/json_decode() give back; a record that cannot be encoded (invalid UTF-8, NAN)
 *    is not saved (false). The uuid is unique per collection: saving another _id with an existing
 *    uuid fails and changes nothing.
 *
 * Deliberately different from today's MySQL backend: an array in tags, title, description or
 * handle contributes `Array` to the search text and an array body nothing (MySQL's backend
 * raises); a value is cut at 255 bytes on a character boundary (MySQL's backend loses the record);
 * the MongoDB list form of `$or` counts 0 (MySQL's backend fails the count); there are no
 * limits on the range of `created` (a MySQL timestamp ends in 2038) or on the length of a field
 * name (64 characters in MySQL).
 */
final class InMemoryRecordStore implements RecordStore
{
    /** The collections of the legacy schema, which exist from the start. */
    public const DEFAULT_COLLECTIONS = ['entities', 'reader', 'config', 'session', 'site'];

    /** The fields queried on the columns of the main table, not on the metadata rows. */
    private const COLUMN_FIELDS = ['uuid', '_id', 'entity_subtype', 'owner', 'publish_status', 'created'];

    /** Search terms longer than this many bytes are matched word by word. */
    private const SHORT_SEARCH_BYTES = 5;

    /**
     * @var array<string, array<string, array{record: array<int|string, mixed>, columns: array<string, string|null>, rows: list<array{name: string, value: string}>, search: string}>>
     */
    private array $collections = [];

    /** @var array<string, array<string, string>> uuid => _id, per collection */
    private array $uuids = [];

    private readonly \Closure $uncurl;

    /**
     * @param (callable(string): string)|null $uncurl      the quote-normalising function applied to search
     *                                                      text (Language::uncurlQuotes()); none by default
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
            'rows' => RecordIndex::metadataRows($record, $now),
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
            if ($latest === null || strcmp((string) $entry['columns']['created'], (string) $latest['columns']['created']) > 0) {
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
        // usort() is stable: records with the same `created` stay in insertion order.
        usort($matching, static fn (array $a, array $b): int => strcmp((string) $b['columns']['created'], (string) $a['columns']['created']));

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
     * @return list<array{record: array<int|string, mixed>, columns: array<string, string|null>, rows: list<array{name: string, value: string}>, search: string}>
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
            if ($text !== null && self::datetime($text) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The datetime literal in the form the stored `created` strings have, plus '.ffffff' when the
     * fraction is not zero (so a plain string comparison orders it correctly), or null when MySQL
     * would reject the literal (or it is one of the forms this store does not support).
     */
    private static function datetime(string $text): ?string
    {
        $matched = preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{1,2}):(\d{1,2})(?:\.(\d{1,6}))?)?$/', $text, $m);
        if ($matched !== 1) {
            return null;
        }
        [$year, $month, $day, $hour, $minute, $second] = [(int) $m[1], (int) $m[2], (int) $m[3], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }
        $fraction = rtrim($m[7] ?? '', '0');

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second) . ($fraction === '' ? '' : '.' . $fraction);
    }

    /**
     * @param array{record: array<int|string, mixed>, columns: array<string, string|null>, rows: list<array{name: string, value: string}>, search: string} $entry
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
            return self::searchMatches($criterion->query, $entry['search']);
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
     * The values the record has for a field: its column, or its metadata rows.
     *
     * @param array{record: array<int|string, mixed>, columns: array<string, string|null>, rows: list<array{name: string, value: string}>, search: string} $entry
     *
     * @return list<string>
     */
    private static function valuesOf(array $entry, string $field): array
    {
        if (in_array($field, self::COLUMN_FIELDS, true)) {
            return [(string) $entry['columns'][$field]];
        }
        $values = [];
        foreach ($entry['rows'] as $row) {
            if (self::equal($row['name'], $field)) {
                $values[] = $row['value'];
            }
        }

        return $values;
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
     * The operand as MySQL sees it with every parameter bound as a string.
     */
    private static function operand(string|int|float|bool|null $operand, ?string $field = null): ?string
    {
        $text = match (true) {
            $operand === null => null,
            $operand === true => '1',
            $operand === false => '',
            default => (string) $operand,
        };

        // A datetime operand is compared in its canonical form (a query with an invalid one never gets here).
        return $field === 'created' && $text !== null ? (self::datetime($text) ?? $text) : $text;
    }

    private static function fold(string $text): string
    {
        return mb_strtoupper($text, 'UTF-8');
    }

    private static function equal(string $a, string $b): bool
    {
        return self::fold($a) === self::fold($b);
    }

    private static function compare(string $a, string $b): int
    {
        return strcmp(self::fold($a), self::fold($b));
    }

    private static function searchMatches(string $term, string $searchText): bool
    {
        $text = self::fold($searchText);
        if (strlen($term) > self::SHORT_SEARCH_BYTES) {
            foreach (preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                if (str_contains($text, self::fold($word))) {
                    return true;
                }
            }

            return false;
        }

        return preg_match(self::likePattern(self::fold($term)), $text) === 1;
    }

    /**
     * The regular expression for SQL `LIKE '%term%'`.
     */
    private static function likePattern(string $term): string
    {
        $pattern = '';
        $length = strlen($term);
        for ($i = 0; $i < $length; $i++) {
            $char = $term[$i];
            if ($char === '\\' && $i + 1 < $length) {
                $char = $term[++$i];
                $pattern .= preg_quote($char, '/');
            } elseif ($char === '%') {
                $pattern .= '.*';
            } elseif ($char === '_') {
                $pattern .= '.';
            } else {
                $pattern .= preg_quote($char, '/');
            }
        }

        return '/' . $pattern . '/su';
    }
}
