<?php

declare(strict_types=1);

namespace Tests\Storage\Contract;

use Idno\Storage\Criteria;
use Idno\Storage\RecordStore;
use PHPUnit\Framework\TestCase;

/**
 * Backend-independent contract for record storage.
 *
 * Every expectation here describes what the MySQL store (DbalRecordStore with MySqlDialect)
 * does, odd corners included: the behaviour of the legacy MySQL backend this contract was first
 * proven against (since removed), except for its deliberate differences, whose new behaviour is
 * pinned here: an array in a search text field is stored (`Array` is searchable) rather than
 * raising, a query MySQL cannot run counts 0, a save the database refuses returns false and stores
 * nothing, and indexed values are cut on a character boundary. Other bindings must reproduce it. Where a backend may legitimately
 * differ, the expectation is routed through a protected method whose default is the MySQL
 * store's value, so a binding overrides the expectation rather than weakening the test.
 *
 * The queries in the tests are written as the legacy search arrays (readable, and the form
 * today's callers use); criteria() turns them into the Criteria the RecordStore takes.
 *
 * This class must not depend on the booted legacy application.
 *
 * Isolation: tests share the `entities` table with everything else, so every
 * record carries a unique marker field (contract_marker) that is added to
 * every query, and tearDown() deletes what the test saved.
 */
abstract class RecordStoreContract extends TestCase
{
    /** Field name holding the per-test marker. */
    private const MARKER_FIELD = 'contract_marker';

    /** A fixed, unambiguous base time (mid-June 2023, away from DST changes). */
    private const BASE_TIME = 1686700000;

    protected string $marker = '';

    /** @var list<array{string, string}> collection and _id of every record this test saved */
    private array $saved = [];

    private int $counter = 0;

    /**
     * The store under test. Must return the same instance every time within one test.
     */
    abstract protected function store(): RecordStore;

    /**
     * Generates a record id.
     */
    protected function newId(): string
    {
        return \Ramsey\Uuid\Uuid::uuid4()->toString();
    }

    /**
     * Whether the binding owns a private database, so deleteAll() on a collection is safe.
     */
    protected function ownsPrivateDatabase(): bool
    {
        return false;
    }

    /**
     * Whether a long (more than 5 characters) search term matches inside a longer word.
     * MySQL full-text search matches whole words only.
     */
    protected function expectLongSearchTermToMatchSubstring(): bool
    {
        return false;
    }

    /**
     * Whether comparing text values ignores letter case ("Red" equals "red").
     * MySQL's default collation does.
     */
    protected function expectTextComparisonToIgnoreCase(): bool
    {
        return true;
    }

    /**
     * Whether a four-byte UTF-8 character (an emoji) makes a save fail. No store today: on MySQL
     * the connection is utf8mb4 and so are the columns that hold the search text and the indexed
     * values (schema 2026111401), so the character is stored and indexed, as in memory and on
     * SQLite. A MySQL database whose search and metadata columns are still `utf8` (utf8mb3, before
     * that upgrade) refuses it in strict mode, the transaction is rolled back and nothing is stored:
     * a binding on such a database overrides this to true.
     */
    protected function expectFourByteCharactersToBeRejected(): bool
    {
        return false;
    }

    /**
     * Whether records with an equal `created` come back in a defined order: the one with the
     * greater `_id` first (so newest first, then `_id` descending, for find() and paging alike).
     * Today's stores leave the order of such records undefined.
     */
    protected function expectEqualCreatedToBeOrderedById(): bool
    {
        return false;
    }

    /**
     * Whether a value is queryable only up to its first 255 bytes (cut on a character boundary),
     * so a longer value never equals a query for the whole of it. A store that compares whole
     * values overrides this to false.
     */
    protected function expectValuesToBeCutAt255Bytes(): bool
    {
        return true;
    }

    /**
     * Whether an array nested in a list (`['meta' => [['k' => 'v']]]`) is queryable as its JSON
     * encoding (`{"k":"v"}`), and an associative array as the list of its values. A store on
     * which nested arrays and objects are not queryable overrides this to false.
     */
    protected function expectNestedArraysToMatchTheirJson(): bool
    {
        return true;
    }

    /**
     * Whether a save is refused (false, nothing stored) when a document field name is longer
     * than 64 characters. MySQL's metadata `name` column is a varchar(64); in memory and on
     * SQLite lengths are not enforced. The MySQL binding overrides this to true.
     */
    protected function expectLongFieldNamesToBeRefused(): bool
    {
        return false;
    }

    /**
     * Whether re-saving an existing record with ANOTHER existing record's uuid overwrites that
     * other record (MySQL's `on duplicate key update` takes the key that conflicts) rather than
     * being refused with both records intact. The MySQL binding overrides this to true.
     */
    protected function expectUuidClashOnResaveToOverwrite(): bool
    {
        return false;
    }

    /**
     * Whether text compares equal across the case of non-ASCII letters (`ÉTÉ` equals `été`).
     * MySQL, the in-memory store and SQLite today do; a store that folds ASCII case only
     * overrides this to false. ASCII case is ignored on every binding.
     */
    protected function expectNonAsciiCaseToBeIgnored(): bool
    {
        return true;
    }

    /**
     * Whether a float with no fractional part (1.0, -0.0) comes back as a float. Every store
     * today keeps the document as PHP's json_encode() writes it, which drops the ".0": the value
     * comes back as an integer (1, 0).
     */
    protected function expectWholeFloatsToKeepTheirType(): bool
    {
        return false;
    }

    /**
     * The collections the binding may write to freely. A binding on a database the running site
     * reads from must not list one the site would pick records up from.
     *
     * @return list<string>
     */
    protected function collections(): array
    {
        return ['entities'];
    }

    /**
     * Whether text values are ordered as the in-memory store and SQLite order them: folded to upper
     * case and compared code point by code point (MySqlSemantics::compare()), so `_` (between `Z`
     * and `a` in ASCII) sorts after every letter. MySQL orders the indexed values by their column's
     * collation, utf8mb4_unicode_520_ci (the Unicode Collation Algorithm), where punctuation sorts
     * before every letter. The in-memory and SQLite bindings override this to true.
     */
    protected function expectTextToSortFoldedToUpperCase(): bool
    {
        return false;
    }

    /**
     * Whether a search term of more than 5 bytes is a MySQL boolean-mode full-text query: its
     * operators apply (`+hello -world` excludes records with "world"), and a term the boolean
     * parser cannot parse (`abc@def.com`, `hello-`, ...) fails the query (error 1064), which the
     * store reports as no records (count 0). Backends
     * without full-text search (in memory, SQLite) match the words as substrings,
     * where operators are plain characters and every term can run: they override this to false.
     */
    protected function expectBooleanModeSearch(): bool
    {
        return true;
    }

    /**
     * The site id a record gets when it is saved without one: what the binding's RecordDefaults
     * give (the bindings build FixedRecordDefaults with this value; on a site, LegacyRecordDefaults
     * give the uuid of Idno::site()->site_details(), null when the site has no Site entity).
     */
    protected function currentSiteId(): ?string
    {
        return 'contract-site';
    }

    protected function setUp(): void
    {
        $this->marker = 'contract-' . bin2hex(random_bytes(8));
        $this->saved = [];
        $this->counter = 0;
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as [$collection, $id]) {
            $this->store()->delete($collection, $id);
        }
        $this->saved = [];
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Builds a complete record carrying this test's marker. Every column field is
     * set explicitly, so what is saved is what is read back.
     */
    protected function r(array $fields = []): array
    {
        $id = $this->newId();
        $this->counter++;

        return $fields + [
            '_id' => $id,
            'uuid' => 'http://contract.test/view/' . $id,
            'siteid' => 'contract-site',
            'owner' => 'http://contract.test/profile/owner',
            'entity_subtype' => 'Tests\\Storage\\Contract\\Record',
            'publish_status' => 'published',
            'created' => self::BASE_TIME + $this->counter * 60,
            self::MARKER_FIELD => $this->marker,
        ];
    }

    /**
     * Recursively sorts array keys, so records compare equal regardless of key
     * order but still differ in value types.
     */
    protected function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            $value = array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
            ksort($value);
        }

        return $value;
    }

    /**
     * Strict comparison of two records: key order is ignored, types are not.
     */
    protected function assertSameRecord(?array $expected, ?array $actual, string $message = ''): void
    {
        $this->assertSame($this->canonical($expected), $this->canonical($actual), $message);
    }

    /**
     * Remembers a record for tearDown() without saving it.
     */
    protected function track(string $id, string $collection = 'entities'): void
    {
        $this->saved[] = [$collection, $id];
    }

    /**
     * Saves a record, remembers it for tearDown() and returns it.
     */
    protected function put(array $record, string $collection = 'entities'): array
    {
        $this->saved[] = [$collection, (string)$record['_id']];
        $this->assertSame($record['_id'], $this->store()->save($collection, $record));

        return $record;
    }

    /**
     * The Criteria for a legacy search array.
     */
    protected function criteria(array $legacySearch): Criteria
    {
        return Criteria::fromLegacyArray($legacySearch);
    }

    /**
     * @return list<string> ids of this test's records matching the criteria, newest first
     */
    protected function ids(array $criteria, string $collection = 'entities', int $limit = 100, int $offset = 0): array
    {
        $found = $this->store()->find($collection, $this->criteria($criteria + [self::MARKER_FIELD => $this->marker]), $limit, $offset);

        return array_map(static fn (array $record): string => $record['_id'], $found);
    }

    /**
     * Asserts the criteria match exactly the given records (any order), and that
     * count() agrees.
     */
    protected function assertMatches(array $criteria, array $expectedRecords, string $message = ''): void
    {
        $expected = array_map(static fn (array $record): string => $record['_id'], $expectedRecords);
        $actual = $this->ids($criteria);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual, $message . ' ' . json_encode($criteria));
        $this->assertSame(
            count($expected),
            $this->store()->count('entities', $this->criteria($criteria + [self::MARKER_FIELD => $this->marker])),
            'count() ' . $message . ' ' . json_encode($criteria)
        );
    }

    // ------------------------------------------------------- basic round trips

    public function testSaveReturnsIdAndRecordRoundTrips(): void
    {
        $record = $this->r([
            'str' => 'text',
            'int' => 42,
            'float' => 1.5,
            'yes' => true,
            'no' => false,
            'nothing' => null,
            'list' => ['a', 'b'],
            'map' => ['k' => ['x' => 1, 'y' => 'z']],
        ]);
        $this->track($record['_id']);

        $this->assertSame($record['_id'], $this->store()->save('entities', $record));

        // Types are pinned (no "3" for 3, no 1 for true); key order is not.
        $this->assertSameRecord($record, $this->store()->findById('entities', $record['_id']));
    }

    public function testSaveFillsInIdUuidAndOwnerWhenMissing(): void
    {
        $record = ['title' => 'bare', self::MARKER_FIELD => $this->marker];

        $id = $this->store()->save('entities', $record);
        if (is_string($id)) {
            $this->track($id);
        }
        $this->assertIsString($id);
        $this->assertNotSame('', $id);

        $stored = $this->store()->findById('entities', $id);
        $this->assertSame($id, $stored['_id']);
        $this->assertSame('', $stored['owner']);
        $this->assertStringEndsWith('view/' . $id, $stored['uuid']);
        $this->assertSame('bare', $stored['title']);
    }

    public function testSaveTwiceUpdatesInPlace(): void
    {
        $record = $this->put($this->r(['title' => 'first']));
        $record['title'] = 'second';
        $this->put($record);

        $this->assertMatches([], [$record]);
        $this->assertSame('second', $this->store()->findById('entities', $record['_id'])['title']);
        $this->assertMatches(['title' => 'first'], []);
        $this->assertMatches(['title' => 'second'], [$record]);
    }

    public function testFindByUuid(): void
    {
        $record = $this->put($this->r(['title' => 'by uuid']));

        $this->assertSameRecord($record, $this->store()->findByUuid('entities', $record['uuid']));
    }

    public function testFindByUuidUnknownIsNull(): void
    {
        $this->assertNull($this->store()->findByUuid('entities', 'http://contract.test/view/' . $this->marker));
    }

    public function testFindByIdUnknownIsNull(): void
    {
        $this->assertNull($this->store()->findById('entities', $this->newId()));
    }

    public function testFindLatestReturnsNewestByCreated(): void
    {
        // The far future (but inside MySQL's timestamp range, which ends in 2038)
        // makes these the newest records in a shared table.
        // A per-marker offset (up to about 11 days) keeps leftover or parallel rows from tying.
        $future = strtotime('2037-06-01 12:00:00 UTC') + (int)(hexdec(substr($this->marker, -6)) % 1000000);
        $older = $this->put($this->r(['title' => 'older', 'created' => $future]));
        $newer = $this->put($this->r(['title' => 'newer', 'created' => $future + 3600]));

        $this->assertSame($newer['_id'], $this->store()->findLatest('entities')['_id']);

        $this->assertTrue($this->store()->delete('entities', $newer['_id']));
        $this->assertSame($older['_id'], $this->store()->findLatest('entities')['_id']);
    }

    // ---------------------------------------------------------------- equality

    public function testEqualsOnColumnField(): void
    {
        $a = $this->put($this->r(['owner' => 'owner-a', 'entity_subtype' => 'Tests\\A', 'publish_status' => 'draft']));
        $b = $this->put($this->r(['owner' => 'owner-b', 'entity_subtype' => 'Tests\\B']));

        $this->assertMatches(['owner' => 'owner-a'], [$a]);
        $this->assertMatches(['owner' => 'owner-b'], [$b]);
        $this->assertMatches(['owner' => 'owner-c'], []);
        $this->assertMatches(['entity_subtype' => 'Tests\\A'], [$a]);
        $this->assertMatches(['publish_status' => 'draft'], [$a]);
        $this->assertMatches(['publish_status' => 'published'], [$b]);
        $this->assertMatches(['uuid' => $a['uuid']], [$a]);
        $this->assertMatches(['_id' => $b['_id']], [$b]);
    }

    public function testEqualsOnDocumentField(): void
    {
        $x = $this->put($this->r(['slug' => 'x']));
        $y = $this->put($this->r(['slug' => 'y']));
        $this->put($this->r(['title' => 'no slug at all']));

        $this->assertMatches(['slug' => 'x'], [$x]);
        $this->assertMatches(['slug' => 'y'], [$y]);
        $this->assertMatches(['slug' => 'z'], []);
    }

    public function testDocumentFieldEqualityCaseSensitivity(): void
    {
        $record = $this->put($this->r(['slug' => 'Red']));

        $this->assertMatches(['slug' => 'Red'], [$record]);
        $this->assertMatches(['slug' => 'red'], $this->expectTextComparisonToIgnoreCase() ? [$record] : []);
    }

    public function testListFieldMatchesAnyElement(): void
    {
        // The field is called labels, not tags: saveRecord() concatenates `tags`, `title`,
        // `description` and `handle` into the search text, so an ARRAY in any of those
        // raises an "Array to string conversion" warning (an ErrorException under the
        // test error handler). Real entities keep `tags` as a string.
        $record = $this->put($this->r(['labels' => ['red', 'blue']]));

        $this->assertMatches(['labels' => 'blue'], [$record]);
        $this->assertMatches(['labels' => 'red'], [$record]);
        $this->assertMatches(['labels' => 'green'], []);
    }

    public function testAValueLongerThan255BytesIsMatchedInFull(): void
    {
        $long = str_repeat('abcdefghij', 30); // 300 characters
        $record = $this->put($this->r(['note' => $long]));

        if ($this->expectValuesToBeCutAt255Bytes()) {
            // Only the first 255 characters are indexed, so the whole value is never equal.
            $this->assertMatches(['note' => $long], []);
            $this->assertMatches(['note' => substr($long, 0, 255)], [$record]);
        } else {
            // The whole value is compared: only the whole value is equal.
            $this->assertMatches(['note' => $long], [$record]);
            $this->assertMatches(['note' => substr($long, 0, 255)], []);
        }
        // ...and the record itself still carries the full value.
        $this->assertSame($long, $this->store()->findById('entities', $record['_id'])['note']);
    }

    public function testEmptyValuesAreStoredAsZero(): void
    {
        $emptyString = $this->put($this->r(['f' => '']));
        $null = $this->put($this->r(['f' => null]));
        $false = $this->put($this->r(['f' => false]));
        $zero = $this->put($this->r(['f' => 0]));
        $zeroString = $this->put($this->r(['f' => '0']));
        $missing = $this->put($this->r(['other' => 'no f']));
        $emptyList = $this->put($this->r(['f' => []]));
        $one = $this->put($this->r(['f' => 1]));

        // Every empty value is indexed as 0 and so matches a query for 0...
        $this->assertMatches(['f' => 0], [$emptyString, $null, $false, $zero, $zeroString]);
        $this->assertMatches(['f' => '0'], [$emptyString, $null, $false, $zero, $zeroString]);
        // ...and none is findable under its original value.
        $this->assertMatches(['f' => ''], []);
        // A missing field and an empty list are not indexed at all.
        $this->assertNotContains($missing['_id'], $this->ids(['f' => 0]));
        $this->assertNotContains($emptyList['_id'], $this->ids(['f' => 0]));
        $this->assertMatches(['f' => 1], [$one]);
        // The records themselves keep their original values.
        $this->assertSame('', $this->store()->findById('entities', $emptyString['_id'])['f']);
        $this->assertNull($this->store()->findById('entities', $null['_id'])['f']);
        $this->assertFalse($this->store()->findById('entities', $false['_id'])['f']);
    }

    public function testNestedArrayValueIsMatchedAsJson(): void
    {
        $listOfObjects = $this->put($this->r(['meta' => [['k' => 'v']]]));

        if ($this->expectNestedArraysToMatchTheirJson()) {
            // An element that is itself an array is indexed as its JSON encoding.
            $this->assertMatches(['meta' => '{"k":"v"}'], [$listOfObjects]);

            // An associative array is NOT indexed as JSON: it is treated like a list of its values.
            $assoc = $this->put($this->r(['pair' => ['a' => 'alpha', 'b' => 'beta']]));
            $this->assertMatches(['pair' => 'alpha'], [$assoc]);
            $this->assertMatches(['pair' => 'beta'], [$assoc]);
            $this->assertMatches(['pair' => '{"a":"alpha","b":"beta"}'], []);
            // Keys are not indexed.
            $this->assertMatches(['pair' => 'a'], []);
        } else {
            // A nested array is not queryable, whatever it is queried for; the record keeps it.
            $this->assertMatches(['meta' => '{"k":"v"}'], []);
            $this->assertMatches(['meta' => 'v'], []);
            $this->assertSameRecord($listOfObjects, $this->store()->findById('entities', $listOfObjects['_id']));
        }
    }

    // ------------------------------------------------- $in, $not, $not => $in

    public function testIn(): void
    {
        $a = $this->put($this->r(['owner' => 'owner-a', 'slug' => 'sa']));
        $b = $this->put($this->r(['owner' => 'owner-b', 'slug' => 'sb']));
        $c = $this->put($this->r(['owner' => 'owner-c', 'slug' => 'sc']));

        $this->assertMatches(['owner' => ['$in' => ['owner-a', 'owner-c']]], [$a, $c]);
        $this->assertMatches(['slug' => ['$in' => ['sa', 'sb']]], [$a, $b]);
        $this->assertMatches(['slug' => ['$in' => ['nope']]], []);

        $tagged = $this->put($this->r(['labels' => ['red', 'blue']]));
        // A record matching on several elements is returned once.
        $this->assertMatches(['labels' => ['$in' => ['red', 'blue']]], [$tagged]);
    }

    public function testNotEquals(): void
    {
        $a = $this->put($this->r(['owner' => 'owner-a', 'slug' => 'sa']));
        $b = $this->put($this->r(['owner' => 'owner-b', 'slug' => 'sb']));
        $noSlug = $this->put($this->r(['owner' => 'owner-c']));

        $this->assertMatches(['owner' => ['$not' => 'owner-a']], [$b, $noSlug]);
        $this->assertMatches(['slug' => ['$not' => 'sa']], [$b]);
        // A record without the field has no metadata row to compare, so it never matches $not.
        $this->assertNotContains($noSlug['_id'], $this->ids(['slug' => ['$not' => 'sa']]));
    }

    public function testNotIn(): void
    {
        $a = $this->put($this->r(['owner' => 'owner-a', 'slug' => 'sa']));
        $b = $this->put($this->r(['owner' => 'owner-b', 'slug' => 'sb']));
        $c = $this->put($this->r(['owner' => 'owner-c', 'slug' => 'sc']));
        $noSlug = $this->put($this->r(['owner' => 'owner-d']));

        $this->assertMatches(['owner' => ['$not' => ['$in' => ['owner-a', 'owner-b']]]], [$c, $noSlug]);
        $this->assertMatches(['slug' => ['$not' => ['$in' => ['sa', 'sb']]]], [$c]);
    }

    public function testNotEqualsOnListField(): void
    {
        $both = $this->put($this->r(['f' => ['a', 'b']]));
        $onlyA = $this->put($this->r(['f' => ['a']]));

        // Surprising: "f is not a" matches the record whose list is [a, b],
        // because the other element, b, differs.
        $this->assertMatches(['f' => ['$not' => 'a']], [$both]);
        $this->assertNotContains($onlyA['_id'], $this->ids(['f' => ['$not' => 'a']]));
        // Likewise for not-in: the [a, b] record matches "not in [a]".
        $this->assertMatches(['f' => ['$not' => ['$in' => ['a']]]], [$both]);
    }

    // ------------------------------------------------------------- comparisons

    public function testLessThanAndGreaterThanOnCreated(): void
    {
        $t1 = $this->put($this->r(['created' => self::BASE_TIME]));
        $t2 = $this->put($this->r(['created' => self::BASE_TIME + 1000]));
        $t3 = $this->put($this->r(['created' => self::BASE_TIME + 2000]));

        $at = static fn (int $seconds): string => date('Y-m-d H:i:s', self::BASE_TIME + $seconds);

        $this->assertMatches(['created' => ['$lt' => $at(1000)]], [$t1]);
        $this->assertMatches(['created' => ['$gt' => $at(1000)]], [$t3]);
        $this->assertMatches(['created' => ['$gt' => $at(0), '$lt' => $at(2000)]], [$t2]);
        $this->assertMatches(['created' => ['$lt' => $at(3000)]], [$t1, $t2, $t3]);
        $this->assertMatches(['created' => ['$gt' => $at(3000)]], []);
    }

    public function testLessThanOnDocumentFieldComparesAsText(): void
    {
        $nine = $this->put($this->r(['n' => 9]));
        $ten = $this->put($this->r(['n' => 10]));

        // As text, '10' sorts before '5' and '9' after it.
        $this->assertMatches(['n' => ['$lt' => '5']], [$ten]);
        $this->assertMatches(['n' => ['$gt' => '5']], [$nine]);
        $this->assertMatches(['n' => ['$gt' => '5', '$lt' => '95']], [$nine]);
    }

    public function testZeroOperandIsIgnored(): void
    {
        $five = $this->put($this->r(['n' => 5]));
        $minus = $this->put($this->r(['n' => -3]));
        $zero = $this->put($this->r(['n' => 0])); // indexed as 0: applying "$not 0" would exclude it

        // An empty operand (0, '0', '', null, []) adds no condition: everything matches.
        $all = [$five, $minus, $zero];
        $this->assertMatches(['n' => ['$lt' => 0]], $all);
        $this->assertMatches(['n' => ['$gt' => 0]], $all);
        $this->assertMatches(['n' => ['$gt' => '0']], $all);
        $this->assertMatches(['n' => ['$in' => []]], $all);
        $this->assertMatches(['n' => ['$not' => 0]], $all);
        $this->assertMatches(['n' => ['$not' => '0']], $all);
        $this->assertMatches(['n' => ['$not' => '']], $all);
        $this->assertMatches(['n' => ['$not' => []]], $all);
        // Positive control: a non-empty operand IS applied.
        $this->assertMatches(['n' => ['$not' => '5']], [$minus, $zero]);
    }

    public function testUnknownOperatorIsIgnored(): void
    {
        $withN = $this->put($this->r(['n' => 5]));
        $without = $this->put($this->r(['other' => 1]));

        // Surprising: an unsupported operator is dropped silently, leaving no
        // condition on the field at all (even records without it match).
        $this->assertMatches(['n' => ['$regex' => 'x']], [$withN, $without]);
        $this->assertMatches(['n' => ['$exists' => true]], [$withN, $without]);
    }

    // ------------------------------------------------------------ combinations

    public function testOrGroup(): void
    {
        $a = $this->put($this->r(['slug' => 'a']));
        $b = $this->put($this->r(['slug' => 'b']));
        $c = $this->put($this->r(['slug' => 'c']));

        // The associative form: one field => value entry per alternative.
        $this->assertMatches(['$or' => ['slug' => 'a', 'owner' => 'http://contract.test/profile/nobody']], [$a]);
        $this->assertMatches(['$or' => ['slug' => ['$in' => ['a', 'b']]]], [$a, $b]);

        // Alternatives on two different document fields (two separate joins).
        $blue = $this->put($this->r(['colour' => 'blue']));
        $this->assertMatches(['$or' => ['slug' => 'a', 'colour' => 'blue']], [$a, $blue]);
        $this->assertMatches(['$or' => ['slug' => 'nope', 'colour' => 'nope']], []);

        // The MongoDB list-of-conditions form is not understood: it compiles to
        // invalid SQL, the error is logged, and find() answers with an empty list
        // (not a match-everything). count() fails; the new implementations deliberately
        // match nothing and count 0.
        $this->assertSame([], $this->ids(['$or' => [['slug' => 'a'], ['slug' => 'b']]]));
        $this->assertNotEmpty($this->ids([]), 'the records exist; only the malformed $or query fails');
        $malformed = ['$or' => [['slug' => 'a'], ['slug' => 'b']], self::MARKER_FIELD => $this->marker];
        $this->assertSame(0, $this->store()->count('entities', $this->criteria($malformed)));
    }

    public function testTwoConditionsOnDifferentFieldsAreAnded(): void
    {
        $redSmall = $this->put($this->r(['colour' => 'red', 'size' => 's', 'owner' => 'owner-a']));
        $redLarge = $this->put($this->r(['colour' => 'red', 'size' => 'l', 'owner' => 'owner-b']));
        $blueSmall = $this->put($this->r(['colour' => 'blue', 'size' => 's', 'owner' => 'owner-a']));

        $this->assertMatches(['colour' => 'red', 'size' => 's'], [$redSmall]);
        $this->assertMatches(['colour' => 'red', 'owner' => 'owner-a'], [$redSmall]);
        $this->assertMatches(['colour' => 'red', 'size' => 'x'], []);
        $this->assertMatches(['size' => 's', 'owner' => ['$not' => 'owner-b']], [$redSmall, $blueSmall]);
    }

    // ------------------------------------------------------------------ search

    public function testSearchShortTermUsesSubstringMatch(): void
    {
        $title = $this->put($this->r(['title' => 'xxabcxx']));
        $body = $this->put($this->r(['body' => '<p>yyabcyy</p>']));
        $tags = $this->put($this->r(['tags' => '#zzabczz']));
        $description = $this->put($this->r(['description' => 'wwabcww']));
        $unrelated = $this->put($this->r(['title' => 'nothing here']));

        $this->assertMatches(['$search' => ['abc']], [$title, $body, $tags, $description]);
        // The boundary is exactly 5 BYTES: five are still a substring match.
        $five = $this->put($this->r(['title' => 'xxabcdexx']));
        $this->assertMatches(['$search' => ['abcde']], [$five]);
        $this->assertNotContains($unrelated['_id'], $this->ids(['$search' => ['abc']]));
    }

    public function testSearchIndexesHandleAndProfileValues(): void
    {
        $handle = $this->put($this->r(['handle' => 'qqhandleqq']));
        $profile = $this->put($this->r(['profile' => ['name' => 'xxprofilexx', 'nested' => ['ignored']]]));

        $this->assertMatches(['$search' => ['han']], [$handle]);
        $this->assertMatches(['$search' => ['pro']], [$profile]);
        $this->assertMatches(['$search' => ['ign']], []);
    }

    public function testSearchLongTermFindsWholeWord(): void
    {
        $word = $this->put($this->r(['body' => 'a sentence about platypus today']));
        $longer = $this->put($this->r(['body' => 'a sentence about platypuses today']));

        // A seven-letter whole word is found. (Words are 7+ letters and are not
        // stop words, so full-text engines index them.)
        $this->assertMatches(['$search' => ['platypus']], $this->expectLongSearchTermToMatchSubstring() ? [$word, $longer] : [$word]);
        // A seven-letter substring of a longer word is found only if the backend matches substrings.
        $this->assertMatches(['$search' => ['platypu']], $this->expectLongSearchTermToMatchSubstring() ? [$word, $longer] : []);
        // A word that is in no record finds nothing.
        $this->assertMatches(['$search' => ['wombatsx']], []);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $short = $this->put($this->r(['title' => 'xxAbCxx']));
        $long = $this->put($this->r(['body' => 'about Capybaras and Platypus']));

        $this->assertMatches(['$search' => ['ABC']], [$short]);
        $this->assertMatches(['$search' => ['abc']], [$short]);
        $this->assertMatches(['$search' => ['PLATYPUS']], [$long]);
        $this->assertMatches(['$search' => ['capybaras']], [$long]);
    }

    public function testSearchCombinesWithOtherConditions(): void
    {
        $mine = $this->put($this->r(['title' => 'xxabcxx', 'owner' => 'owner-a']));
        $other = $this->put($this->r(['title' => 'xxabcxx', 'owner' => 'owner-b']));

        $this->assertMatches(['$search' => ['abc'], 'owner' => 'owner-a'], [$mine]);
        $this->assertMatches(['$search' => ['abc']], [$mine, $other]);
    }

    // ----------------------------------------------------- ordering and paging

    public function testOrderIsNewestFirstAndLimitOffsetPage(): void
    {
        $records = [];
        // Saved out of order on purpose.
        foreach ([3, 1, 5, 2, 4] as $n) {
            $records[$n] = $this->put($this->r(['created' => self::BASE_TIME + $n * 100, 'n' => $n]));
        }
        $newestFirst = [$records[5]['_id'], $records[4]['_id'], $records[3]['_id'], $records[2]['_id'], $records[1]['_id']];

        $this->assertSame($newestFirst, $this->ids([]));
        $this->assertSame([$records[4]['_id'], $records[3]['_id']], $this->ids([], 'entities', 2, 1));
        $this->assertSame([$records[5]['_id'], $records[4]['_id']], $this->ids([], 'entities', 2, 0));
        $this->assertSame([$records[1]['_id']], $this->ids([], 'entities', 2, 4));
        $this->assertSame([], $this->ids([], 'entities', 2, 5));
        $this->assertSame([$records[5]['_id']], $this->ids([], 'entities', 1, 0));
    }

    public function testFindWithNoMatchesReturnsEmptyList(): void
    {
        $this->assertSame([], $this->ids(['slug' => 'does-not-exist']));
        $this->assertSame(0, $this->store()->count('entities', $this->criteria(['slug' => 'does-not-exist', self::MARKER_FIELD => $this->marker])));
    }

    public function testCountMatchesFind(): void
    {
        $a = $this->put($this->r(['slug' => 'a', 'labels' => ['red', 'blue']]));
        $b = $this->put($this->r(['slug' => 'b', 'labels' => ['red']]));
        $this->put($this->r(['slug' => 'c']));

        $this->assertSame(3, $this->store()->count('entities', $this->criteria([self::MARKER_FIELD => $this->marker])));
        $this->assertSame(2, $this->store()->count('entities', $this->criteria(['labels' => 'red', self::MARKER_FIELD => $this->marker])));
        // Distinct records, not matching rows: two matching tags still count once.
        $this->assertSame(1, $this->store()->count('entities', $this->criteria(['labels' => ['$in' => ['red', 'blue']], 'slug' => 'a', self::MARKER_FIELD => $this->marker])));
        $this->assertMatches(['labels' => ['$in' => ['red', 'blue']]], [$a, $b]);
        $this->assertSame(0, $this->store()->count('entities', $this->criteria(['slug' => 'zzz', self::MARKER_FIELD => $this->marker])));
        // count() ignores paging by definition; find() with a small limit does not.
        $this->assertCount(1, $this->store()->find('entities', $this->criteria([self::MARKER_FIELD => $this->marker]), 1, 0));
    }

    // ----------------------------------------------------------------- deleting

    public function testDeleteRemovesRecordAndQueriesNoLongerFindIt(): void
    {
        $record = $this->put($this->r(['slug' => 'gone', 'title' => 'xxvanishxx', 'labels' => ['red']]));
        $keeper = $this->put($this->r(['slug' => 'stays', 'title' => 'xxvanishxx', 'labels' => ['red']]));
        $this->assertMatches(['slug' => 'gone'], [$record]);

        $this->assertTrue($this->store()->delete('entities', $record['_id']));

        $this->assertNull($this->store()->findById('entities', $record['_id']));
        $this->assertNull($this->store()->findByUuid('entities', $record['uuid']));
        $this->assertMatches(['slug' => 'gone'], []);
        $this->assertMatches(['labels' => 'red'], [$keeper]);
        $this->assertMatches(['$search' => ['nish']], [$keeper]);
        $this->assertMatches([], [$keeper]);

        // Saving the same id again creates a fresh, complete record with no stale index rows.
        $record['slug'] = 'reborn';
        unset($record['labels']);
        $this->put($record);
        $this->assertMatches(['slug' => 'gone'], []);
        $this->assertMatches(['slug' => 'reborn'], [$record]);
        $this->assertMatches(['labels' => 'red'], [$keeper]);
    }

    public function testDeleteOfUnknownIdSucceeds(): void
    {
        // Deleting something that is not there is not an error.
        $this->assertTrue($this->store()->delete('entities', $this->newId()));
    }

    public function testDeleteAllRemovesEveryRecordInTheCollection(): void
    {
        if (!$this->ownsPrivateDatabase()) {
            $this->markTestSkipped('deleteAll() empties a whole collection; it is only safe on a binding with a private database.');
        }

        $this->put($this->r(['slug' => 'a']));
        $this->put($this->r(['slug' => 'b']));
        $this->assertTrue($this->store()->deleteAll('entities'));

        $this->assertSame(0, $this->store()->count('entities', Criteria::all()));
        $this->assertNull($this->store()->findLatest('entities'));
    }

    // -------------------------------------------------------------- collections

    public function testCollectionsAreSeparate(): void
    {
        $record = $this->put($this->r(['slug' => 'in-reader']), 'reader');

        $this->assertSameRecord($record, $this->store()->findById('reader', $record['_id']));
        $this->assertNull($this->store()->findById('entities', $record['_id']));
        $this->assertNull($this->store()->findByUuid('entities', $record['uuid']));
        $this->assertSame([], $this->ids(['slug' => 'in-reader'], 'entities'));
        $this->assertSame([$record['_id']], $this->ids(['slug' => 'in-reader'], 'reader'));
    }

    public function testInvalidCollectionNameIsRejectedOrSanitised(): void
    {
        // Names are reduced to letters, digits and underscores, so these cannot
        // reach anything but a table of that reduced name. 'entities x' becomes
        // 'entitiesx' and 'nosuchtable' stays as it is: neither table exists.
        foreach (['entities x', 'nosuchtable'] as $name) {
            $record = $this->r(['slug' => 'invalid']);
            $this->assertFalse($this->store()->save($name, $record), $name);
            $this->assertNull($this->store()->findById($name, $record['_id']), $name);
            $this->assertNull($this->store()->findByUuid($name, $record['uuid']), $name);
            $this->assertNull($this->store()->findLatest($name), $name);
            $this->assertSame([], $this->store()->find($name, Criteria::all(), 10, 0), $name);
        }
    }

    public function testCollectionNameIsSanitisedToItsSafeCharacters(): void
    {
        // The punctuation is stripped, not rejected: 'enti-ties' is 'entities'.
        $record = $this->r(['slug' => 'sanitised']);
        $this->track($record['_id']);

        $this->assertSame($record['_id'], $this->store()->save('enti-ties', $record));
        $this->assertSameRecord($record, $this->store()->findById('entities', $record['_id']));
    }

    // ======================================================= further cases

    // ------------------------------------------------------- update and insert

    public function testResaveChangesEveryColumnField(): void
    {
        $record = $this->put($this->r(['slug' => 'col', 'owner' => 'owner-a', 'publish_status' => 'draft', 'entity_subtype' => 'Tests\\A']));

        $record['owner'] = 'owner-b';
        $record['publish_status'] = 'published';
        $record['entity_subtype'] = 'Tests\\B';
        $this->put($record);

        $this->assertMatches(['owner' => 'owner-a'], []);
        $this->assertMatches(['owner' => 'owner-b'], [$record]);
        $this->assertMatches(['publish_status' => 'draft'], []);
        $this->assertMatches(['publish_status' => 'published'], [$record]);
        $this->assertMatches(['entity_subtype' => 'Tests\\A'], []);
        $this->assertMatches(['entity_subtype' => 'Tests\\B'], [$record]);
    }

    public function testResaveChangingCreatedReorders(): void
    {
        $first = $this->put($this->r(['created' => self::BASE_TIME + 100]));
        $second = $this->put($this->r(['created' => self::BASE_TIME + 200]));
        $this->assertSame([$second['_id'], $first['_id']], $this->ids([]));

        $first['created'] = self::BASE_TIME + 300;
        $this->put($first);

        $this->assertSame([$first['_id'], $second['_id']], $this->ids([]));
        $at = date('Y-m-d H:i:s', self::BASE_TIME + 300);
        $this->assertMatches(['created' => $at], [$first]);
        $this->assertSame(self::BASE_TIME + 300, $this->store()->findById('entities', $first['_id'])['created']);
    }

    public function testMissingColumnFieldsGetDefaultsInTheColumnsButNotInTheDocument(): void
    {
        $bare = ['_id' => $this->newId(), 'siteid' => 'contract-site', 'slug' => 'bare', self::MARKER_FIELD => $this->marker];
        $bare['uuid'] = 'http://contract.test/view/' . $bare['_id'];
        $this->track($bare['_id']);
        $before = time();

        $this->assertSame($bare['_id'], $this->store()->save('entities', $bare));

        // The stored document is exactly what was saved: the defaults are applied
        // after the document is encoded, apart from the owner, which is filled in first.
        $stored = $this->store()->findById('entities', $bare['_id']);
        $this->assertSameRecord($bare + ['owner' => ''], $stored);
        $this->assertArrayNotHasKey('entity_subtype', $stored);
        $this->assertArrayNotHasKey('publish_status', $stored);
        $this->assertArrayNotHasKey('created', $stored);

        // The defaults ARE what queries on those fields see.
        $this->assertMatches(['entity_subtype' => 'Idno\\Common\\Entity'], [$bare]);
        $this->assertMatches(['publish_status' => 'published'], [$bare]);
        $this->assertMatches(['created' => ['$gt' => date('Y-m-d H:i:s', $before - 5)]], [$bare]);
        $this->assertMatches(['created' => ['$lt' => date('Y-m-d H:i:s', time() + 5)]], [$bare]);
        $this->assertMatches(['created' => ['$lt' => date('Y-m-d H:i:s', $before - 5)]], []);
    }

    public function testSiteIdIsFilledFromTheCurrentSiteWhenMissing(): void
    {
        $record = $this->r(['slug' => 'nosite']);
        unset($record['siteid']);
        $this->track($record['_id']);

        $this->assertSame($record['_id'], $this->store()->save('entities', $record));

        $stored = $this->store()->findById('entities', $record['_id']);
        $this->assertArrayHasKey('siteid', $stored);
        $this->assertSame($this->currentSiteId(), $stored['siteid']);

        // An explicit siteid is kept.
        $explicit = $this->put($this->r(['siteid' => 'another-site']));
        $this->assertSame('another-site', $this->store()->findById('entities', $explicit['_id'])['siteid']);
    }

    public function testSiteIdInTheDocumentFollowsTheLastSave(): void
    {
        // (The siteid column is not changed by an update, but it cannot be read
        // through this interface; only the document and its index are observable.)
        $record = $this->put($this->r(['siteid' => 'site-one']));
        $record['siteid'] = 'site-two';
        $this->put($record);

        $this->assertSame('site-two', $this->store()->findById('entities', $record['_id'])['siteid']);
        $this->assertMatches(['siteid' => 'site-two'], [$record]);
        $this->assertMatches(['siteid' => 'site-one'], []);
    }

    public function testSavingADifferentIdWithAnExistingUuidFailsAndKeepsTheOriginal(): void
    {
        $original = $this->put($this->r(['title' => 'original']));
        $clash = $this->r(['title' => 'clash', 'uuid' => $original['uuid']]);
        $this->track($clash['_id']);

        $this->assertFalse($this->store()->save('entities', $clash));

        $this->assertSameRecord($original, $this->store()->findById('entities', $original['_id']));
        $this->assertNull($this->store()->findById('entities', $clash['_id']));
        $this->assertSameRecord($original, $this->store()->findByUuid('entities', $original['uuid']));
        $this->assertMatches([], [$original]);
    }

    public function testArrayInASearchTextFieldRaisesOrIsSaved(): void
    {
        foreach (['tags', 'title', 'description', 'handle'] as $field) {
            $record = $this->r([$field => ['x', 'y']]);
            $this->track($record['_id']);
            $this->assertSame($record['_id'], $this->store()->save('entities', $record), $field);
            // PHP's own string conversion of an array: the word "Array" is searchable.
            $this->assertMatches(['$search' => ['array']], [$record], $field);
            $this->store()->delete('entities', $record['_id']);
        }
    }

    public function testArrayBodyRaisesOrIsSaved(): void
    {
        $record = $this->r(['body' => ['x', 'y']]);
        $this->track($record['_id']);
        $this->assertSame($record['_id'], $this->store()->save('entities', $record));
        // Stored and indexed like any list (its elements are findable)...
        $this->assertMatches(['body' => 'x'], [$record]);
        // ...but the body adds nothing to the search text (not even the word "Array").
        $this->assertMatches(['$search' => ['array']], []);
        $this->assertMatches(['$search' => ['x']], []);
    }

    // ---------------------------------------------- bytes, not characters

    public function testIndexedValueIsCutAt255Bytes(): void
    {
        // 127 two-byte characters plus one byte is exactly 255 bytes (128 characters).
        $prefix = str_repeat('é', 127) . 'a';
        $record = $this->put($this->r(['note' => $prefix . 'bbbb']));

        if ($this->expectValuesToBeCutAt255Bytes()) {
            $this->assertMatches(['note' => $prefix], [$record]);
            $this->assertMatches(['note' => $prefix . 'b'], []);
            $this->assertMatches(['note' => $prefix . 'bbbb'], []);
        } else {
            $this->assertMatches(['note' => $prefix], []);
            $this->assertMatches(['note' => $prefix . 'b'], []);
            $this->assertMatches(['note' => $prefix . 'bbbb'], [$record]);
        }
        $this->assertSame($prefix . 'bbbb', $this->store()->findById('entities', $record['_id'])['note']);
    }

    public function testAValueCutInTheMiddleOfAMultibyteCharacterIsStoredAndIndexedUpToTheCharacterBefore(): void
    {
        // 200 times 'é' is 400 bytes; the cut at byte 255 lands inside the 128th character.
        // A deliberate difference: the index is cut on a character boundary and the record is stored (the
        // legacy backend cut the character in half, MySQL rejected it, and the record was lost).
        $value = str_repeat('é', 200);
        $record = $this->put($this->r(['note' => $value]));

        // The record is stored byte for byte...
        $this->assertSame($value, $this->store()->findById('entities', $record['_id'])['note']);
        if ($this->expectValuesToBeCutAt255Bytes()) {
            // ...and indexed up to the last whole character that fits in 255 bytes: 127 of them, 254 bytes.
            $this->assertMatches(['note' => str_repeat('é', 127)], [$record]);
            $this->assertMatches(['note' => str_repeat('é', 128)], []);
            $this->assertMatches(['note' => $value], []);
        } else {
            // ...and indexed whole: only the whole value is equal.
            $this->assertMatches(['note' => str_repeat('é', 127)], []);
            $this->assertMatches(['note' => str_repeat('é', 128)], []);
            $this->assertMatches(['note' => $value], [$record]);
        }
    }

    public function testSearchTermLengthIsCountedInBytes(): void
    {
        $record = $this->put($this->r(['title' => 'xxéééxx', 'body' => 'a word éééé here']));

        // 'ééé' is 3 characters but 6 bytes, so it takes the long-term (whole word) path.
        $this->assertMatches(['$search' => ['ééé']], $this->expectLongSearchTermToMatchSubstring() ? [$record] : []);
        // 'éé' is 4 bytes: the short-term substring path.
        $this->assertMatches(['$search' => ['éé']], [$record]);
        // The same 3-character term made of one-byte characters is a substring match.
        $ascii = $this->put($this->r(['title' => 'xxabcxx']));
        $this->assertMatches(['$search' => ['abc']], [$ascii]);
    }

    // -------------------------------------------------------------- search

    public function testSearchSixCharacterBoundary(): void
    {
        $inside = $this->put($this->r(['title' => 'xxabcdefxx']));
        $whole = $this->put($this->r(['body' => 'the abcdef arrived']));

        // Six characters take the long-term path: whole words only, on MySQL.
        $this->assertMatches(['$search' => ['abcdef']], $this->expectLongSearchTermToMatchSubstring() ? [$inside, $whole] : [$whole]);
        // Five characters are always a substring match.
        $this->assertMatches(['$search' => ['abcde']], [$inside, $whole]);
    }

    public function testSearchOnlyCoversTheIndexedFields(): void
    {
        $indexed = $this->put($this->r(['title' => 'xxqqzxx']));
        $notIndexed = $this->put($this->r(['summary' => 'xxqqzxx', 'content' => 'xxqqzxx', 'slug' => 'xxqqzxx']));

        $this->assertMatches(['$search' => ['qqz']], [$indexed]);
        $this->assertNotContains($notIndexed['_id'], $this->ids(['$search' => ['qqz']]));
    }

    public function testSearchOfBodyIgnoresMarkup(): void
    {
        $body = $this->put($this->r(['body' => '<a href="http://example.test/qqzqq" class="zzmarkzz">link text</a>']));
        $control = $this->put($this->r(['title' => 'href and zzmarkzz']));

        // Text outside the tags is searched; tag names, attributes and URLs are not.
        $this->assertMatches(['$search' => ['lin']], [$body]);
        $this->assertMatches(['$search' => ['qqz']], []);
        $this->assertMatches(['$search' => ['zzm']], [$control]);
        $this->assertMatches(['$search' => ['href']], [$control]);
    }

    public function testSearchWithAShortMultiWordTermMatchesThePhrase(): void
    {
        $inOrder = $this->put($this->r(['body' => 'one two']));
        $reversed = $this->put($this->r(['body' => 'two one']));

        $this->assertMatches(['$search' => ['e t']], [$inOrder]);
        $this->assertMatches(['$search' => ['o o']], [$reversed]);
        $this->assertMatches(['$search' => ['o x']], []);
    }

    public function testSearchWithALongMultiWordTermUsesTheWords(): void
    {
        $platypus = $this->put($this->r(['body' => 'only platypus here']));
        $capybara = $this->put($this->r(['body' => 'only capybara here']));
        $both = $this->put($this->r(['body' => 'platypus and capybara together']));
        $neither = $this->put($this->r(['body' => 'only wombat here']));

        // A term of more than 5 bytes is looked for word by word, and a record matches when it
        // has ANY of the words (MySQL boolean mode ORs them; the other stores do the same).
        $this->assertMatches(['$search' => ['platypus capybara']], [$platypus, $capybara, $both]);
        $this->assertNotContains($neither['_id'], $this->ids(['$search' => ['platypus capybara']]));
    }

    public function testSearchShortTermTreatsLikeWildcardsAsWildcards(): void
    {
        $abc = $this->put($this->r(['title' => 'xxabcxx']));
        $other = $this->put($this->r(['title' => 'xxaXXcxx']));

        // The term is not escaped: % matches any run of characters, _ any single one.
        $this->assertMatches(['$search' => ['a%c']], [$abc, $other]);
        $this->assertMatches(['$search' => ['a_c']], [$abc]);
        $this->assertMatches(['$search' => ['a__c']], [$other]);
        // Positive control: the literal term still finds the plain record.
        $this->assertMatches(['$search' => ['abc']], [$abc]);
    }

    public function testSearchWithNoUsableTermAddsNoCondition(): void
    {
        $a = $this->put($this->r(['title' => 'xxabcxx']));
        $b = $this->put($this->r(['title' => 'something else']));

        // An empty term, '0' and an empty list are all "empty": no condition.
        $this->assertMatches(['$search' => ['']], [$a, $b]);
        $this->assertMatches(['$search' => ['0']], [$a, $b]);
        $this->assertMatches(['$search' => []], [$a, $b]);
        // Positive control.
        $this->assertMatches(['$search' => ['abc']], [$a]);
    }

    public function testSearchUsesOnlyTheFirstTerm(): void
    {
        $a = $this->put($this->r(['title' => 'xxabcxx']));
        $b = $this->put($this->r(['title' => 'xxxyzxx']));

        $this->assertMatches(['$search' => ['abc', 'xyz']], [$a]);
        $this->assertMatches(['$search' => ['xyz', 'abc']], [$b]);
    }

    public function testSearchGivenAPlainStringIsAFieldCondition(): void
    {
        $a = $this->put($this->r(['title' => 'xxabcxx']));

        // `$search` must be a list. A string is treated as a document field called
        // `$search` with that value, which nothing has.
        $this->assertMatches(['$search' => 'abc'], []);
        $this->assertMatches(['$search' => ['abc']], [$a]);
    }

    // -------------------------------------------------- several operators

    public function testSeveralOperatorsOnAListFieldMayBeSatisfiedByDifferentElements(): void
    {
        $split = $this->put($this->r(['labels' => ['a', 'd']]));
        $inside = $this->put($this->r(['labels' => ['bb']]));
        $outside = $this->put($this->r(['labels' => ['x', 'y']]));
        $scalarInside = $this->put($this->r(['labels' => 'bb']));

        // Each operator is evaluated independently: 'd' satisfies $gt 'b' and 'a'
        // satisfies $lt 'c', so [a, d] matches although no single element is between b and c.
        $this->assertMatches(['labels' => ['$gt' => 'b', '$lt' => 'c']], [$split, $inside, $scalarInside]);
        $this->assertNotContains($outside['_id'], $this->ids(['labels' => ['$gt' => 'b', '$lt' => 'c']]));
    }

    public function testInAndNotOnOneField(): void
    {
        $ab = $this->put($this->r(['labels' => ['a', 'b']]));
        $a = $this->put($this->r(['labels' => ['a']]));
        $b = $this->put($this->r(['labels' => ['b']]));
        $sa = $this->put($this->r(['slug' => 'a']));
        $sb = $this->put($this->r(['slug' => 'b']));

        // In on one element and not-equal on (possibly) another: [a, b] satisfies both.
        $this->assertMatches(['labels' => ['$in' => ['a'], '$not' => 'b']], [$ab, $a]);
        // On a scalar field the two conditions apply to the one value.
        $this->assertMatches(['slug' => ['$in' => ['a', 'b'], '$not' => 'a']], [$sb]);
        $this->assertMatches(['slug' => ['$in' => ['a'], '$not' => 'a']], []);
    }

    public function testAFieldTheRecordLacksNeverMatchesAnOperator(): void
    {
        $has = $this->put($this->r(['g' => 'm']));
        $lacks = $this->put($this->r(['other' => 'm']));

        // Each condition is satisfiable by the record that has the field.
        $this->assertMatches(['g' => 'm'], [$has]);
        $this->assertMatches(['g' => ['$not' => 'zz']], [$has]);
        $this->assertMatches(['g' => ['$in' => ['m']]], [$has]);
        $this->assertMatches(['g' => ['$not' => ['$in' => ['zz']]]], [$has]);
        $this->assertMatches(['g' => ['$lt' => 'z']], [$has]);
        $this->assertMatches(['g' => ['$gt' => 'a']], [$has]);
        $this->assertNotContains($lacks['_id'], $this->ids(['g' => ['$not' => 'zz']]));
    }

    public function testPlainListAndEmptyArrayCriteriaAreIgnored(): void
    {
        $a = $this->put($this->r(['f' => 'a']));
        $b = $this->put($this->r(['f' => 'b']));
        $c = $this->put($this->r(['f' => 'c']));

        // An array with no operator is not "equals any of": it adds no condition.
        $this->assertMatches(['f' => ['a', 'b']], [$a, $b, $c]);
        $this->assertMatches(['f' => []], [$a, $b, $c]);
        // Positive control.
        $this->assertMatches(['f' => 'a'], [$a]);
    }

    public function testKnownAndUnknownOperatorsMixed(): void
    {
        $three = $this->put($this->r(['n' => 3]));
        $seven = $this->put($this->r(['n' => 7]));

        // The unknown operator is dropped, the known one applies.
        $this->assertMatches(['n' => ['$regex' => 'x', '$gt' => '5']], [$seven]);
        $this->assertMatches(['n' => ['$gt' => '5', '$regex' => 'x']], [$seven]);
    }

    // -------------------------------------------------------------- paging

    public function testPagingReturnsARecordWithSeveralMatchingValuesOnce(): void
    {
        $many = $this->put($this->r(['labels' => ['a', 'b', 'c'], 'created' => self::BASE_TIME + 300]));
        $two = $this->put($this->r(['labels' => ['a', 'b'], 'created' => self::BASE_TIME + 200]));
        $one = $this->put($this->r(['labels' => ['a'], 'created' => self::BASE_TIME + 100]));
        $criteria = ['labels' => ['$in' => ['a', 'b', 'c']]];

        $this->assertSame([$many['_id']], $this->ids($criteria, 'entities', 1, 0));
        $this->assertSame([$two['_id']], $this->ids($criteria, 'entities', 1, 1));
        $this->assertSame([$one['_id']], $this->ids($criteria, 'entities', 1, 2));
        $this->assertSame([], $this->ids($criteria, 'entities', 1, 3));
        $this->assertSame([$many['_id'], $two['_id'], $one['_id']], $this->ids($criteria));
    }

    public function testLimitZeroReturnsNothing(): void
    {
        $this->put($this->r(['slug' => 'here']));

        $this->assertSame([], $this->ids([], 'entities', 0, 0));
        $this->assertCount(1, $this->ids([], 'entities', 1, 0));
    }

    // ------------------------------------------------------ created values

    public function testCreatedQueriedWithAnIntegerTimestampFails(): void
    {
        $record = $this->put($this->r(['created' => self::BASE_TIME + 100]));

        // MySQL rejects a bare integer as a TIMESTAMP ("Incorrect TIMESTAMP value");
        // the error is logged and find() answers with an empty list, not with the
        // records that a numeric comparison would find. (count() is not asserted here; it is 0,
        // as testCreatedRejectsTheLiteralsMysqlRejects pins.)
        $this->assertSame([], $this->ids(['created' => ['$lt' => self::BASE_TIME + 1000]]));
        $this->assertSame([], $this->ids(['created' => ['$gt' => self::BASE_TIME]]));
        // Positive control: the datetime string form finds the record.
        $this->assertMatches(['created' => ['$lt' => date('Y-m-d H:i:s', self::BASE_TIME + 1000)]], [$record]);
    }

    public function testCreatedIsFormattedInThePhpDefaultTimezoneAtSaveTime(): void
    {
        $previous = date_default_timezone_get();
        try {
            date_default_timezone_set('UTC');
            $record = $this->put($this->r(['created' => self::BASE_TIME + 100]));

            date_default_timezone_set('Asia/Tokyo');
            $utcText = gmdate('Y-m-d H:i:s', self::BASE_TIME + 100);
            $tokyoText = date('Y-m-d H:i:s', self::BASE_TIME + 100);

            // The text that was stored is the UTC rendering; the same instant written
            // in another timezone is a different wall-clock string and does not match.
            $this->assertNotSame($utcText, $tokyoText);
            $this->assertMatches(['created' => $utcText], [$record]);
            $this->assertMatches(['created' => $tokyoText], []);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    // ------------------------------------------------------ queue queries
    //
    // The three query shapes used by AsynchronousQueuedEvent::getPendingFromQueue(),
    // ::getFromQueue() and AsynchronousQueue::gc(), against records shaped like
    // AsynchronousQueuedEvent (queue, event, complete, completedTs).

    private function queueEvent(string $queue, array $fields, int $age): array
    {
        return $this->put($this->r(['queue' => $queue, 'event' => 'test/event', 'created' => self::BASE_TIME + $age] + $fields));
    }

    public function testQueuePendingQuery(): void
    {
        $queue = 'q-' . $this->marker;
        $pending = $this->queueEvent($queue, ['complete' => false], 1);
        $done = $this->queueEvent($queue, ['complete' => true, 'completedTs' => 1000], 2);
        $lacking = $this->queueEvent($queue, [], 3);
        $otherQueue = $this->queueEvent('other-' . $this->marker, ['complete' => false], 4);

        // getPendingFromQueue(): complete not in [true]
        $this->assertMatches(['queue' => $queue, 'complete' => ['$not' => ['$in' => [true]]]], [$pending]);
        // An event that has no `complete` field at all is NOT pending (there is no value to compare).
        $this->assertNotContains($lacking['_id'], $this->ids(['queue' => $queue, 'complete' => ['$not' => ['$in' => [true]]]]));
        // getFromQueue(): the queue alone.
        $this->assertMatches(['queue' => $queue], [$pending, $done, $lacking]);
        $this->assertMatches(['queue' => 'other-' . $this->marker], [$otherQueue]);
    }

    public function testQueueGarbageCollectionQuery(): void
    {
        $queue = 'q-' . $this->marker;
        $short = $this->queueEvent($queue, ['complete' => true, 'completedTs' => 999], 1);
        $thousand = $this->queueEvent($queue, ['complete' => true, 'completedTs' => 1000], 2);
        $long = $this->queueEvent($queue, ['complete' => true, 'completedTs' => 100000], 3);
        $pending = $this->queueEvent($queue, ['complete' => false, 'completedTs' => 5], 4);

        $gc = static fn (int $cutoff): array => ['completedTs' => ['$lt' => $cutoff], 'complete' => true, 'queue' => $queue];

        // completedTs is a document field and every value is bound as TEXT, so the
        // comparison is alphabetical: '999' > '1000' and '1000' < '5000'.
        $this->assertMatches($gc(1000), []);
        $this->assertMatches($gc(5000), [$thousand, $long]);
        $this->assertMatches($gc(1001), [$thousand, $long]);
        $this->assertMatches($gc(999), [$thousand, $long]);
        $this->assertMatches($gc(100001), [$thousand, $long]);
        $this->assertMatches($gc(10), []);
        // Numerically 999 < 1000 would have matched $short; alphabetically it is not before '1000'.
        $this->assertNotContains($short['_id'], $this->ids($gc(1000)));
        // `complete => true` excludes the pending event whatever its completedTs.
        $this->assertNotContains($pending['_id'], $this->ids($gc(5000)));
        // And $gt compares as text too: '999', '100000' and '5' all sort after '1000'.
        $this->assertMatches(['completedTs' => ['$gt' => 1000], 'queue' => $queue], [$short, $long, $pending]);
    }

    public function testBooleanAndIntegerQueryValues(): void
    {
        $true = $this->put($this->r(['b' => true]));
        $false = $this->put($this->r(['b' => false]));
        $one = $this->put($this->r(['b' => 1]));
        $oneString = $this->put($this->r(['b' => '1']));
        $zero = $this->put($this->r(['b' => 0]));
        $missing = $this->put($this->r(['other' => 1]));

        // true is stored as '1' and queried as '1'.
        $this->assertMatches(['b' => true], [$true, $one, $oneString]);
        $this->assertMatches(['b' => 1], [$true, $one, $oneString]);
        $this->assertMatches(['b' => '1'], [$true, $one, $oneString]);
        // false is stored as '0', but queried as false it is bound as '' and matches nothing.
        $this->assertMatches(['b' => false], []);
        $this->assertMatches(['b' => 0], [$false, $zero]);
        $this->assertMatches(['b' => '0'], [$false, $zero]);
        // $in with booleans.
        $this->assertMatches(['b' => ['$in' => [true]]], [$true, $one, $oneString]);
        $this->assertMatches(['b' => ['$in' => [false]]], []);
        $this->assertMatches(['b' => ['$in' => [0]]], [$false, $zero]);
        // not in [true]: records with another value, never the one that lacks the field.
        $this->assertMatches(['b' => ['$not' => ['$in' => [true]]]], [$false, $zero]);
        $this->assertMatches(['b' => ['$not' => true]], [$false, $zero]);
        $this->assertNotContains($missing['_id'], $this->ids(['b' => ['$not' => ['$in' => [true]]]]));
    }

    public function testIntegerOperandsCompareAsTextOnDocumentFields(): void
    {
        $small = $this->put($this->r(['ts' => 999]));
        $big = $this->put($this->r(['ts' => 1000]));
        $huge = $this->put($this->r(['ts' => 100000]));

        $this->assertMatches(['ts' => ['$lt' => 1000]], []);
        $this->assertMatches(['ts' => ['$gt' => 1000]], [$small, $huge]);
        $this->assertMatches(['ts' => ['$lt' => 5000]], [$big, $huge]);
        $this->assertMatches(['ts' => ['$gt' => 5000]], [$small]);
        $this->assertMatches(['ts' => 1000], [$big]);
    }

    // ------------------------------------------- datetime literals

    /**
     * Three records at 2023-06-14 00:00:00, 00:00:01 and 12:30:45 (PHP default timezone, the
     * one `created` is formatted in), A, B and C.
     *
     * @return array{array, array, array}
     */
    private function threeRecordsOnOneDay(): array
    {
        $midnight = strtotime('2023-06-14 00:00:00');

        return [
            $this->put($this->r(['created' => $midnight])),
            $this->put($this->r(['created' => $midnight + 1])),
            $this->put($this->r(['created' => $midnight + 12 * 3600 + 30 * 60 + 45])),
        ];
    }

    /**
     * Literals MySQL accepts for a `created` operand, with what `=`, `$lt` and `$gt` find among
     * A (00:00:00), B (00:00:01) and C (12:30:45). A fraction is compared exactly (no rounding).
     *
     * @return array<string, array{string, list<string>, list<string>, list<string>}>
     */
    public static function acceptedDatetimeLiterals(): array
    {
        return [
            'date only is midnight' => ['2023-06-14', ['A'], [], ['B', 'C']],
            'unpadded fields' => ['2023-6-14 0:0:0', ['A'], [], ['B', 'C']],
            'unpadded time' => ['2023-06-14 1:2:3', [], ['A', 'B'], ['C']],
            'T separator' => ['2023-06-14T00:00:00', ['A'], [], ['B', 'C']],
            'fraction above the second' => ['2023-06-14 00:00:00.5', [], ['A'], ['B', 'C']],
            'fraction below half is not rounded' => ['2023-06-14 12:30:45.4', [], ['A', 'B', 'C'], []],
            'zero fraction is the second' => ['2023-06-14 00:00:00.000', ['A'], [], ['B', 'C']],
        ];
    }

    /**
     * @dataProvider acceptedDatetimeLiterals
     *
     * @param list<string> $equal
     * @param list<string> $less
     * @param list<string> $greater
     */
    public function testCreatedAcceptsTheDatetimeLiteralsMysqlAccepts(string $literal, array $equal, array $less, array $greater): void
    {
        $records = array_combine(['A', 'B', 'C'], $this->threeRecordsOnOneDay());
        $pick = static fn (array $names): array => array_map(static fn (string $name): array => $records[$name], $names);

        $this->assertMatches(['created' => $literal], $pick($equal), $literal);
        $this->assertMatches(['created' => ['$lt' => $literal]], $pick($less), $literal);
        $this->assertMatches(['created' => ['$gt' => $literal]], $pick($greater), $literal);
        $this->assertMatches(['created' => ['$in' => [$literal]]], $pick($equal), $literal);
    }

    /**
     * Literals MySQL rejects ("Incorrect TIMESTAMP value", error 1525) for a `created` operand:
     * the query fails, which the store reports as no records (count 0).
     *
     * @return array<string, array{string|int|float|bool}>
     */
    public static function rejectedDatetimeLiterals(): array
    {
        return [
            'integer timestamp' => [1686700100],
            'timestamp as text' => ['1686700100'],
            'empty string' => [''],
            'decimal text' => ['1.5'],
            'one' => ['1'],
            'true (bound as 1)' => [true],
            'day that does not exist' => ['2023-02-30 00:00:00'],
            'time only' => ['24:00:00'],
            'hour 24' => ['2023-06-14 24:00:00'],
        ];
    }

    /**
     * @dataProvider rejectedDatetimeLiterals
     */
    public function testCreatedRejectsTheLiteralsMysqlRejects(string|int|float|bool $literal): void
    {
        $this->threeRecordsOnOneDay();
        // `$lt` and `$gt` with an empty operand add no condition, so '' is only an equality.
        $queries = [['created' => $literal], ['created' => ['$in' => [$literal]]]];
        if ($literal !== '') {
            $queries[] = ['created' => ['$lt' => $literal]];
            $queries[] = ['created' => ['$gt' => $literal]];
        }

        foreach ($queries as $query) {
            $this->assertSame([], $this->ids($query), json_encode($query));
            $this->assertNotEmpty($this->ids([]), 'the records exist');
            $this->assertSame(0, $this->store()->count('entities', $this->criteria($query + [self::MARKER_FIELD => $this->marker])), json_encode($query));
        }
    }

    // ------------------------------------------------------ collation weights

    public function testTextComparisonFoldsToUpperCase(): void
    {
        $underscore = $this->put($this->r(['slug' => 'a_']));
        $letter = $this->put($this->r(['slug' => 'aZ']));

        if ($this->expectTextToSortFoldedToUpperCase()) {
            // Folded to upper case, '_' (between Z and a in ASCII) sorts after every letter.
            $this->assertMatches(['slug' => ['$gt' => 'aZ']], [$underscore]);
            $this->assertMatches(['slug' => ['$lt' => 'a_']], [$letter]);
            $this->assertMatches(['slug' => ['$gt' => 'a_']], []);
        } else {
            // utf8mb4_unicode_520_ci: '_' sorts before every letter, whatever its case.
            $this->assertMatches(['slug' => ['$gt' => 'aZ']], []);
            $this->assertMatches(['slug' => ['$lt' => 'a_']], []);
            $this->assertMatches(['slug' => ['$gt' => 'a_']], [$letter]);
            $this->assertMatches(['slug' => ['$lt' => 'aZ']], [$underscore]);
        }
    }

    // ------------------------------------------------- search below the top level

    public function testASearchBelowTheTopLevelFailsTheQuery(): void
    {
        $record = $this->put($this->r(['title' => 'xxabcxx', 'slug' => 'x']));

        // Positive control: the same search at the top level finds it.
        $this->assertMatches(['$search' => ['abc']], [$record]);
        // MySQL joins the search table only for a top-level `$search`: inside `$or` the query
        // fails (find gives [], count fails; the new stores match nothing, count 0).
        $query = ['$or' => ['$search' => ['abc'], 'slug' => 'x']];
        $this->assertSame([], $this->ids($query));
        $this->assertSame(0, $this->store()->count('entities', $this->criteria($query + [self::MARKER_FIELD => $this->marker])));
    }

    // ------------------------------------------------ four-byte characters

    public function testAFourByteCharacterInTheBodyIsRejectedOrStored(): void
    {
        $original = $this->put($this->r(['body' => 'a plain body']));
        $update = $original;
        $update['body'] = "a body with an emoji \u{1F600} in it";
        $fresh = $this->r(['body' => "\u{1F600}"]);
        $this->track($fresh['_id']);

        if ($this->expectFourByteCharactersToBeRejected()) {
            // The search row is written before the metadata rows: it is rejected first, and the
            // save fails (legacy included).
            $this->assertFalse($this->store()->save('entities', $update), 'an update');
            $this->assertSameRecord($original, $this->store()->findById('entities', $original['_id']), 'the previous version stays');
            $this->assertMatches(['body' => 'a plain body'], [$original]);
            $this->assertFalse($this->store()->save('entities', $fresh), 'an insert');
            $this->assertNull($this->store()->findById('entities', $fresh['_id']));
        } else {
            $this->assertSame($original['_id'], $this->store()->save('entities', $update));
            $this->assertSameRecord($update, $this->store()->findById('entities', $original['_id']));
            $this->assertMatches(['body' => $update['body']], [$update]);
            $this->assertMatches(['$search' => ['emoji']], [$update]);
            $this->assertSame($fresh['_id'], $this->store()->save('entities', $fresh));
            $this->assertSameRecord($fresh, $this->store()->findById('entities', $fresh['_id']));
        }
    }

    public function testAFourByteCharacterInAnIndexedFieldIsRejectedOrStored(): void
    {
        // `note` is not part of the search text: only its metadata row holds the character.
        $original = $this->put($this->r(['note' => 'plain']));
        $update = $original;
        $update['note'] = "\u{1F600} smile";
        $fresh = $this->r(['note' => "smile \u{1F600}"]);
        $this->track($fresh['_id']);

        if ($this->expectFourByteCharactersToBeRejected()) {
            $this->assertFalse($this->store()->save('entities', $update), 'an update');
            $this->assertSameRecord($original, $this->store()->findById('entities', $original['_id']), 'the previous version stays');
            $this->assertMatches(['note' => 'plain'], [$original]);
            $this->assertFalse($this->store()->save('entities', $fresh), 'an insert');
            $this->assertNull($this->store()->findById('entities', $fresh['_id']));
            $this->assertNull($this->store()->findByUuid('entities', $fresh['uuid']));
        } else {
            $this->assertSame($original['_id'], $this->store()->save('entities', $update));
            $this->assertSameRecord($update, $this->store()->findById('entities', $original['_id']));
            $this->assertMatches(['note' => $update['note']], [$update]);
            $this->assertSame($fresh['_id'], $this->store()->save('entities', $fresh));
            $this->assertMatches(['note' => $fresh['note']], [$fresh]);
        }
    }

    // ------------------------------------------------ boolean-mode search terms

    /**
     * Terms longer than 5 bytes that MySQL's boolean-mode full-text parser rejects (error 1064).
     *
     * @return array<string, array{string}>
     */
    public static function unparsableBooleanSearchTerms(): array
    {
        return [
            'an email address' => ['abc@def.com'],
            'a trailing operator' => ['hello-'],
            'two operators' => ['foo +-bar'],
            'a leading @' => ['@hello'],
            'unbalanced parentheses' => ['(((abc'],
            'a word and operators' => ['test ~-'],
        ];
    }

    /**
     * @dataProvider unparsableBooleanSearchTerms
     */
    public function testASearchTermTheBooleanParserRejectsFailsTheQuery(string $term): void
    {
        // The record holds the term (and so its words): an empty result comes from the failure.
        $record = $this->put($this->r(['body' => 'before ' . $term . ' after']));
        $this->put($this->r(['body' => 'nothing related']));
        $query = ['$search' => [$term]];

        if (!$this->expectBooleanModeSearch()) {
            $this->assertMatches($query, [$record], $term);

            return;
        }
        $this->assertSame([], $this->ids($query), $term);
        $this->assertNotEmpty($this->ids([]), 'the records exist');
        $this->assertSame(0, $this->store()->count('entities', $this->criteria($query + [self::MARKER_FIELD => $this->marker])), $term);
    }

    public function testABooleanSearchTermTheParserAcceptsAppliesItsOperators(): void
    {
        $there = $this->put($this->r(['body' => 'hello there friend']));
        $this->put($this->r(['body' => 'hello world friend']));

        // MySQL: +hello -world finds the records with "hello" and without "world". Without boolean
        // mode the words '+hello' and '-world' are substrings no record contains.
        $this->assertMatches(['$search' => ['+hello -world']], $this->expectBooleanModeSearch() ? [$there] : []);
    }

    // ------------------------------------------- what the new schema must keep or change

    public function testEqualCreatedIsOrderedByIdDescending(): void
    {
        $ids = [];
        foreach (['a1', 'a2', 'a3', 'a4'] as $suffix) {
            $ids[] = $this->put($this->r(['_id' => $this->marker . '-' . $suffix, 'created' => self::BASE_TIME]))['_id'];
        }
        [$a1, $a2, $a3, $a4] = $ids;

        if ($this->expectEqualCreatedToBeOrderedById()) {
            $this->assertSame([$a4, $a3, $a2, $a1], $this->ids([]));
            $this->assertSame([$a3, $a2], $this->ids([], 'entities', 2, 1));
        } else {
            $found = $this->ids([]);
            sort($found);
            $this->assertSame($ids, $found);
            $this->assertCount(2, $this->ids([], 'entities', 2, 1));
        }
    }

    public function testNestedObjectKeyOrderIsPreserved(): void
    {
        $record = $this->put($this->r(['annotations' => ['z' => ['b' => 1, 'a' => 2], 'm' => [], 'a' => ['y' => 1]]]));

        $stored = $this->store()->findById('entities', $record['_id']);

        $this->assertSame(['z', 'm', 'a'], array_keys($stored['annotations']));
        $this->assertSame(['b', 'a'], array_keys($stored['annotations']['z']));
        $this->assertSame($record['annotations'], $stored['annotations']);
    }

    public function testNumbersRoundTripExactly(): void
    {
        $numbers = ['i' => 9007199254740993, 'f' => 0.1, 'whole' => 1.0, 'neg' => -0.0, 'big' => 1.0E+25];
        $record = $this->put($this->r($numbers));

        $stored = $this->store()->findById('entities', $record['_id']);

        // Integers beyond 2^53 and fractions survive exactly.
        foreach (['i', 'f', 'big'] as $name) {
            $this->assertSame($numbers[$name], $stored[$name], $name);
        }
        if ($this->expectWholeFloatsToKeepTheirType()) {
            $this->assertSame(1.0, $stored['whole']);
            $this->assertSame(-0.0, $stored['neg']);
        } else {
            // The document is PHP's json_encode() of the record, which writes a float with no
            // fraction without one: it comes back as an integer, and -0.0 as 0.
            $this->assertSame(1, $stored['whole']);
            $this->assertSame(0, $stored['neg']);
        }
    }

    public function testEmptyObjectAndEmptyListRoundTrip(): void
    {
        $record = $this->put($this->r(['o' => [], 'l' => []]));

        $stored = $this->store()->findById('entities', $record['_id']);

        $this->assertSame([], $stored['o']);
        $this->assertSame([], $stored['l']);
    }

    public function testLongFieldNameIsStoredOrRefused(): void
    {
        $name = str_repeat('n', 70);
        $record = $this->r([$name => 'v']);
        $this->track($record['_id']);

        if ($this->expectLongFieldNamesToBeRefused()) {
            // `name` is varchar(64): the database rejects the row and the save is rolled back.
            $this->assertFalse($this->store()->save('entities', $record));
            $this->assertNull($this->store()->findById('entities', $record['_id']));
        } else {
            $this->assertSame($record['_id'], $this->store()->save('entities', $record));
            $this->assertMatches([$name => 'v'], [$record]);
            $this->assertSame('v', $this->store()->findById('entities', $record['_id'])[$name]);
        }
    }

    public function testUuidClashOnResave(): void
    {
        $a = $this->put($this->r(['title' => 'a']));
        $b = $this->put($this->r(['title' => 'b']));
        $clash = $b;
        $clash['uuid'] = $a['uuid'];

        $result = $this->store()->save('entities', $clash);

        if ($this->expectUuidClashOnResaveToOverwrite()) {
            // The row that held a's uuid now holds b's document (so a's own record is gone from
            // it), and b's own row is untouched.
            $this->assertSame($b['_id'], $result);
            $this->assertSame('b', $this->store()->findById('entities', $a['_id'])['title']);
            $this->assertSame($b['_id'], $this->store()->findById('entities', $a['_id'])['_id']);
            $this->assertSame('b', $this->store()->findByUuid('entities', $a['uuid'])['title']);
            $this->assertSameRecord($b, $this->store()->findById('entities', $b['_id']));
            $this->assertSameRecord($b, $this->store()->findByUuid('entities', $b['uuid']));
        } else {
            $this->assertFalse($result);
            $this->assertSameRecord($a, $this->store()->findById('entities', $a['_id']));
            $this->assertSameRecord($b, $this->store()->findById('entities', $b['_id']));
            $this->assertSameRecord($a, $this->store()->findByUuid('entities', $a['uuid']));
            $this->assertSameRecord($b, $this->store()->findByUuid('entities', $b['uuid']));
            $this->assertMatches([], [$a, $b]);
        }
    }

    public function testNonAsciiCase(): void
    {
        $upper = $this->put($this->r(['f' => 'ÉTÉ']));
        $mixed = $this->put($this->r(['f' => 'Été']));
        $ascii = $this->put($this->r(['g' => 'Summer']));

        // ASCII case is ignored on every binding.
        $this->assertMatches(['g' => 'summer'], [$ascii]);
        $this->assertMatches(['g' => 'SUMMER'], [$ascii]);
        // The same letters in another case match each other exactly when non-ASCII case is ignored.
        $this->assertMatches(['f' => 'ÉTÉ'], $this->expectNonAsciiCaseToBeIgnored() ? [$upper, $mixed] : [$upper]);
        $this->assertMatches(['f' => 'été'], $this->expectNonAsciiCaseToBeIgnored() ? [$upper, $mixed] : []);
        $this->assertMatches(['f' => 'Été'], $this->expectNonAsciiCaseToBeIgnored() ? [$upper, $mixed] : [$mixed]);
    }

    public function testEveryCollectionRoundTripsAndQueries(): void
    {
        $collections = $this->collections();
        $records = [];
        foreach ($collections as $collection) {
            $first = $this->put($this->r(['slug' => 'first-' . $collection]), $collection);
            $second = $this->put($this->r(['slug' => 'second-' . $collection]), $collection);
            $records[$collection] = [$first, $second];

            $this->assertSameRecord($first, $this->store()->findById($collection, $first['_id']), $collection);
            $this->assertSame([$first['_id']], $this->ids(['slug' => 'first-' . $collection], $collection), $collection);
            $this->assertSame(2, $this->store()->count($collection, $this->criteria([self::MARKER_FIELD => $this->marker])), $collection);
        }

        foreach ($collections as $collection) {
            [$first, $second] = $records[$collection];
            foreach ($collections as $other) {
                if ($other !== $collection) {
                    $this->assertNull($this->store()->findById($other, $first['_id']), $collection . ' in ' . $other);
                    $this->assertSame([], $this->ids(['slug' => 'first-' . $collection], $other), $collection . ' in ' . $other);
                }
            }
            $this->assertTrue($this->store()->delete($collection, $first['_id']), $collection);
            $this->assertNull($this->store()->findById($collection, $first['_id']), $collection);
            $this->assertSame([$second['_id']], $this->ids([], $collection), $collection);
            $this->assertSame(1, $this->store()->count($collection, $this->criteria([self::MARKER_FIELD => $this->marker])), $collection);
        }
    }
}
