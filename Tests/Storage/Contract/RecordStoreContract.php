<?php

declare(strict_types=1);

namespace Tests\Storage\Contract;

use PHPUnit\Framework\TestCase;

/**
 * Backend-independent contract for record storage.
 *
 * Every expectation here describes what today's MySQL backend (Idno\Data\MySQL)
 * does, odd corners included; other bindings must reproduce it. Where a
 * backend may legitimately differ, the expectation is routed through a
 * protected method whose default is today's MySQL value, so a binding
 * overrides the expectation rather than weakening the test.
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

    abstract protected function store(): RecordStoreUnderTest;

    /**
     * Generates a record id. Bindings without the legacy application override this.
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
     * Saves a record, remembers it for tearDown() and returns it.
     */
    protected function put(array $record, string $collection = 'entities'): array
    {
        $this->saved[] = [$collection, (string)$record['_id']];
        $this->assertSame($record['_id'], $this->store()->save($collection, $record));

        return $record;
    }

    /**
     * @return list<string> ids of this test's records matching the criteria, newest first
     */
    protected function ids(array $criteria, string $collection = 'entities', int $limit = 100, int $offset = 0): array
    {
        $found = $this->store()->find($collection, $criteria + [self::MARKER_FIELD => $this->marker], $limit, $offset);

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
            $this->store()->count('entities', $criteria + [self::MARKER_FIELD => $this->marker]),
            'count() ' . $message . ' ' . json_encode($criteria)
        );
    }

    // ------------------------------------------------------- basic round trips

    public function testSaveReturnsIdAndRecordRoundTrips(): void
    {
        $record = $this->r(['title' => 'a', 'body' => 'hello', 'nested' => ['x' => ['y' => 1]], 'flag' => true, 'count' => 3]);

        $this->assertSame($record['_id'], $this->store()->save('entities', $record));
        $this->saved[] = ['entities', $record['_id']];

        $this->assertEquals($record, $this->store()->findById('entities', $record['_id']));
    }

    public function testSaveFillsInIdUuidAndOwnerWhenMissing(): void
    {
        $record = ['title' => 'bare', self::MARKER_FIELD => $this->marker];

        $id = $this->store()->save('entities', $record);
        $this->assertIsString($id);
        $this->assertNotSame('', $id);
        $this->saved[] = ['entities', $id];

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

        $this->assertEquals($record, $this->store()->findByUuid('entities', $record['uuid']));
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
        $future = strtotime('2037-06-01 12:00:00 UTC');
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

    public function testLongValueIsMatchedOnItsFirst255Characters(): void
    {
        $long = str_repeat('abcdefghij', 30); // 300 characters
        $record = $this->put($this->r(['note' => $long]));

        // Only the first 255 characters are indexed, so the whole value is never equal.
        $this->assertMatches(['note' => $long], []);
        $this->assertMatches(['note' => substr($long, 0, 255)], [$record]);
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
        // An element that is itself an array is indexed as its JSON encoding.
        $listOfObjects = $this->put($this->r(['meta' => [['k' => 'v']]]));
        $this->assertMatches(['meta' => '{"k":"v"}'], [$listOfObjects]);

        // An associative array is NOT indexed as JSON: it is treated like a list of its values.
        $assoc = $this->put($this->r(['pair' => ['a' => 'alpha', 'b' => 'beta']]));
        $this->assertMatches(['pair' => 'alpha'], [$assoc]);
        $this->assertMatches(['pair' => 'beta'], [$assoc]);
        $this->assertMatches(['pair' => '{"a":"alpha","b":"beta"}'], []);
        // Keys are not indexed.
        $this->assertMatches(['pair' => 'a'], []);
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

        // An empty operand (0, '0', '', null, []) adds no condition: everything matches.
        $this->assertMatches(['n' => ['$lt' => 0]], [$five, $minus]);
        $this->assertMatches(['n' => ['$gt' => 0]], [$five, $minus]);
        $this->assertMatches(['n' => ['$gt' => '0']], [$five, $minus]);
        $this->assertMatches(['n' => ['$in' => []]], [$five, $minus]);
        $this->assertMatches(['n' => ['$not' => 0]], [$five, $minus]);
        $this->assertMatches(['n' => ['$not' => '']], [$five, $minus]);
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

        // The MongoDB list-of-conditions form is not understood: it compiles to
        // invalid SQL, the error is logged, and find() answers with an empty list
        // (not a match-everything). count() reports the failure, which the contract
        // cannot express, so only find() is pinned.
        $this->assertSame([], $this->ids(['$or' => [['slug' => 'a'], ['slug' => 'b']]]));
        $this->assertNotEmpty($this->ids([]), 'the records exist; only the malformed $or query fails');
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
        // The boundary: five characters are still a substring match, six are not.
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
        $this->assertSame(0, $this->store()->count('entities', ['slug' => 'does-not-exist', self::MARKER_FIELD => $this->marker]));
    }

    public function testCountMatchesFind(): void
    {
        $a = $this->put($this->r(['slug' => 'a', 'labels' => ['red', 'blue']]));
        $b = $this->put($this->r(['slug' => 'b', 'labels' => ['red']]));
        $this->put($this->r(['slug' => 'c']));

        $this->assertSame(3, $this->store()->count('entities', [self::MARKER_FIELD => $this->marker]));
        $this->assertSame(2, $this->store()->count('entities', ['labels' => 'red', self::MARKER_FIELD => $this->marker]));
        // Distinct records, not matching rows: two matching tags still count once.
        $this->assertSame(1, $this->store()->count('entities', ['labels' => ['$in' => ['red', 'blue']], 'slug' => 'a', self::MARKER_FIELD => $this->marker]));
        $this->assertMatches(['labels' => ['$in' => ['red', 'blue']]], [$a, $b]);
        $this->assertSame(0, $this->store()->count('entities', ['slug' => 'zzz', self::MARKER_FIELD => $this->marker]));
        // count() ignores paging by definition; find() with a small limit does not.
        $this->assertCount(1, $this->store()->find('entities', [self::MARKER_FIELD => $this->marker], 1, 0));
    }

    // ----------------------------------------------------------------- deleting

    public function testDeleteRemovesRecordAndItsIndexRows(): void
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

        $this->assertSame(0, $this->store()->count('entities', []));
        $this->assertNull($this->store()->findLatest('entities'));
    }

    // -------------------------------------------------------------- collections

    public function testCollectionsAreSeparate(): void
    {
        $record = $this->put($this->r(['slug' => 'in-reader']), 'reader');

        $this->assertEquals($record, $this->store()->findById('reader', $record['_id']));
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
        foreach (['entities x', 'nosuchtable', 'no such;table'] as $name) {
            $record = $this->r(['slug' => 'invalid']);
            $this->assertFalse($this->store()->save($name, $record), $name);
            $this->assertNull($this->store()->findById($name, $record['_id']), $name);
            $this->assertNull($this->store()->findByUuid($name, $record['uuid']), $name);
            $this->assertNull($this->store()->findLatest($name), $name);
            $this->assertSame([], $this->store()->find($name, [], 10, 0), $name);
        }
    }

    public function testCollectionNameIsSanitisedToItsSafeCharacters(): void
    {
        // The punctuation is stripped, not rejected: 'enti-ties' is 'entities'.
        $record = $this->r(['slug' => 'sanitised']);
        $this->saved[] = ['entities', $record['_id']];

        $this->assertSame($record['_id'], $this->store()->save('enti-ties', $record));
        $this->assertEquals($record, $this->store()->findById('entities', $record['_id']));
    }
}
