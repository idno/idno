<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Idno\Storage\Criteria;
use Idno\Storage\Criterion\AllOf;
use Idno\Storage\Criterion\AnyOf;
use Idno\Storage\Criterion\Equals;
use Idno\Storage\Criterion\GreaterThan;
use Idno\Storage\Criterion\In;
use Idno\Storage\Criterion\LessThan;
use Idno\Storage\Criterion\NotEquals;
use Idno\Storage\Criterion\NotIn;
use Idno\Storage\Criterion\Search;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\InMemoryRecordStore;
use Idno\Storage\RecordStore;
use Tests\Storage\Contract\RecordStoreContract;

/**
 * The storage contract against the in-memory store (it owns its data, so deleteAll() is
 * tested too), plus what is specific to this implementation.
 *
 * The hooks it overrides, and why:
 *  - expectLongSearchTermToMatchSubstring (true): there is no full-text index, a long term is
 *    a substring match on each of its words;
 *  - ownsPrivateDatabase (true): the store is private to the test;
 *  - expectArrayInSearchFieldsToRaise, expectArrayBodyToRaise (false): ruling P5;
 *  - expectFailedQueryCountToFail (false): ruling P6;
 *  - expectMidCharacterCutToLoseTheRecord (false): ruling P7.
 */
final class InMemoryRecordStoreTest extends RecordStoreContract
{
    private InMemoryRecordStore $store;

    private FixedRecordDefaults $defaults;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaults = new FixedRecordDefaults('http://contract.test/', $this->currentSiteId(), fn (): string => $this->newId());
        $this->store = new InMemoryRecordStore($this->defaults);
    }

    protected function store(): RecordStore
    {
        return $this->store;
    }

    protected function ownsPrivateDatabase(): bool
    {
        return true;
    }

    protected function expectLongSearchTermToMatchSubstring(): bool
    {
        return true;
    }

    protected function expectArrayInSearchFieldsToRaise(): bool
    {
        return false;
    }

    protected function expectArrayBodyToRaise(): bool
    {
        return false;
    }

    protected function expectFailedQueryCountToFail(): bool
    {
        return false;
    }

    protected function expectMidCharacterCutToLoseTheRecord(): bool
    {
        return false;
    }

    // ------------------------------------------------ specific to this implementation

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function rec(array $fields): array
    {
        return $fields + ['_id' => 'id-' . count($fields) . bin2hex(random_bytes(4)), 'created' => 1686700000];
    }

    private function ids2(Criteria $criteria): array
    {
        return array_map(static fn (array $r): string => $r['_id'], $this->store->find('entities', $criteria, 100, 0));
    }

    public function testDefaultsComeFromTheRecordDefaults(): void
    {
        $store = new InMemoryRecordStore(new FixedRecordDefaults('https://site.test/', 'site-9'));

        $first = $store->save('entities', ['title' => 'one']);
        $second = $store->save('entities', ['title' => 'two']);

        $this->assertSame('00000000-0000-4000-8000-000000000001', $first);
        $this->assertSame('00000000-0000-4000-8000-000000000002', $second);
        $stored = $store->findById('entities', $first);
        $this->assertSame('https://site.test/view/' . $first, $stored['uuid']);
        $this->assertSame('site-9', $stored['siteid']);
        $this->assertSame('', $stored['owner']);
    }

    public function testASiteWithoutAnIdGivesANullSiteId(): void
    {
        $store = new InMemoryRecordStore(new FixedRecordDefaults('https://site.test/', null));

        $id = $store->save('entities', ['title' => 'x']);

        $this->assertArrayHasKey('siteid', $store->findById('entities', $id));
        $this->assertNull($store->findById('entities', $id)['siteid']);
    }

    public function testRecordsAreCopiedNotShared(): void
    {
        $record = $this->rec(['n' => ['a']]);
        $id = $this->store->save('entities', $record);
        $record['n'][] = 'b';
        $read = $this->store->findById('entities', $id);
        $read['n'][] = 'c';

        $this->assertSame(['a'], $this->store->findById('entities', $id)['n']);
    }

    public function testARecordThatCannotBeEncodedIsNotSaved(): void
    {
        $id = 'bad-' . $this->marker;

        $this->assertFalse($this->store->save('entities', ['_id' => $id, 'title' => "invalid \xB1\x31 utf-8"]));

        $this->assertNull($this->store->findById('entities', $id));
    }

    public function testACreatedThatIsNotATimestampThrowsAndStoresNothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->store->save('entities', ['_id' => 'bad-created', 'created' => 'yesterday']);
        } finally {
            $this->assertNull($this->store->findById('entities', 'bad-created'));
        }
    }

    public function testUnknownCollectionsFindNothingAndCountZero(): void
    {
        $this->assertSame(0, $this->store->count('nosuchtable', Criteria::all()));
        $this->assertFalse($this->store->delete('nosuchtable', 'x'));
        $this->assertFalse($this->store->deleteAll('nosuchtable'));
        $this->assertFalse($this->store->save('', ['_id' => 'x']));
    }

    public function testResavingWithAnotherUuidMovesTheUuid(): void
    {
        $this->store->save('entities', ['_id' => 'a', 'uuid' => 'u1']);
        $this->store->save('entities', ['_id' => 'a', 'uuid' => 'u2']);

        $this->assertNull($this->store->findByUuid('entities', 'u1'));
        $this->assertSame('a', $this->store->findByUuid('entities', 'u2')['_id']);
        // The old uuid is free for another record.
        $this->assertSame('b', $this->store->save('entities', ['_id' => 'b', 'uuid' => 'u1']));
    }

    public function testTiesInCreatedStayInInsertionOrder(): void
    {
        foreach (['c', 'a', 'b'] as $id) {
            $this->store->save('entities', ['_id' => $id, 'created' => 1686700000]);
        }

        $this->assertSame(['c', 'a', 'b'], $this->ids2(Criteria::all()));
    }

    public function testNegativeLimitOrOffsetFindNothing(): void
    {
        $this->store->save('entities', ['_id' => 'a']);

        $this->assertSame([], $this->store->find('entities', Criteria::all(), -1, 0));
        $this->assertSame([], $this->store->find('entities', Criteria::all(), 10, -1));
    }

    public function testCreatedOperandThatIsNotADatetimeMatchesNothingWhereverItIs(): void
    {
        $this->store->save('entities', ['_id' => 'a', 'created' => 1686700000, 'slug' => 'x']);
        $valid = date('Y-m-d H:i:s', 1686700000);

        foreach ([1686700000, '1686700000', '2023-02-30 00:00:00', '2023-06-14 24:00:00', '24:00:00', true, 1.5, '', '2023-06-14 00:00:00.1234567', '2023-06-14x'] as $bad) {
            $this->assertSame([], $this->ids2(new Criteria(new AllOf([new GreaterThan('created', $bad)]))), json_encode($bad));
            $this->assertSame(0, $this->store->count('entities', new Criteria(new AllOf([new LessThan('created', $bad)]))));
            $this->assertSame([], $this->ids2(new Criteria(new AllOf([new In('created', [$valid, $bad])]))));
            $this->assertSame([], $this->ids2(new Criteria(new AllOf([new NotIn('created', [$bad])]))));
            // The whole query fails, also when the bad operand is in an alternative that is not needed.
            $this->assertSame([], $this->ids2(new Criteria(new AllOf([new AnyOf([new Equals('slug', 'x'), new Equals('created', $bad)])]))));
        }
        // Other literals that MySQL accepts are fine (the contract pins the list), and NULL is not an error (it is just never equal).
        $this->assertSame(['a'], $this->ids2(new Criteria(new AllOf([new Equals('created', $valid)]))));
        $this->assertSame(['a'], $this->ids2(new Criteria(new AllOf([new AnyOf([new Equals('slug', 'x'), new Equals('created', null)])]))));
    }

    public function testASearchOutsideTheTopLevelMatchesNothingWhereverItIs(): void
    {
        $this->store->save('entities', ['_id' => 'a', 'title' => 'xxabcxx', 'slug' => 'x']);
        $top = new Search('abc');

        $this->assertSame(['a'], $this->ids2(new Criteria(new AllOf([$top, new Equals('slug', 'x')]))));
        foreach ([new AnyOf([$top]), new AnyOf([$top, new Equals('slug', 'x')]), new AllOf([$top]), new AnyOf([new AllOf([$top])])] as $nested) {
            $criteria = new Criteria(new AllOf([$nested]));
            $this->assertSame([], $this->ids2($criteria), $nested::class);
            $this->assertSame(0, $this->store->count('entities', $criteria));
        }
    }

    public function testFractionsAndDateOnlyOperandsOnCreated(): void
    {
        $this->store->save('entities', ['_id' => 'a', 'created' => strtotime('2023-06-14 00:00:00')]);

        $this->assertSame(['a'], $this->ids2(new Criteria(new AllOf([new GreaterThan('created', '2023-06-13')]))));
        $this->assertSame([], $this->ids2(new Criteria(new AllOf([new GreaterThan('created', '2023-06-14')]))));
        $this->assertSame(['a'], $this->ids2(new Criteria(new AllOf([new LessThan('created', '2023-06-14 00:00:00.000001')]))));
        $this->assertSame(['a'], $this->ids2(new Criteria(new AllOf([new Equals('created', '2023-06-14 00:00:00.0')]))));
    }

    public function testANullOperandIsNeverTrue(): void
    {
        $this->store->save('entities', ['_id' => 'a', 'f' => 'v']);

        foreach ([new Equals('f', null), new NotEquals('f', null), new LessThan('f', null), new GreaterThan('f', null), new In('f', [null]), new NotIn('f', [null]), new NotIn('f', ['zz', null])] as $criterion) {
            $this->assertSame([], $this->ids2(new Criteria(new AllOf([$criterion]))), $criterion::class);
        }
        // A NULL among other values of In does not stop the others from matching.
        $this->assertSame(['a'], $this->ids2(new Criteria(new AllOf([new In('f', [null, 'v'])]))));
    }

    public function testTreesBuiltByHand(): void
    {
        $this->store->save('entities', ['_id' => 'a', 'f' => 'x', 'g' => 'y']);
        $this->store->save('entities', ['_id' => 'b', 'f' => 'x', 'g' => 'z']);
        $all = static fn (\Idno\Storage\Criterion\Criterion ...$c): Criteria => new Criteria(new AllOf($c));

        $this->assertSame([], $this->ids2($all(new AnyOf([]))), 'an AnyOf with no children matches nothing');
        $this->assertSame(['a', 'b'], $this->ids2($all(new AllOf([]))), 'an AllOf with no children matches everything');
        $this->assertSame(['a'], $this->ids2($all(new AnyOf([new Equals('g', 'y'), new Equals('g', 'nope')]), new Equals('f', 'x'))));
        $this->assertSame(['a'], $this->ids2($all(new AnyOf([new AllOf([new Equals('f', 'x'), new Equals('g', 'y')]), new Equals('g', 'nope')]))));
        $this->assertSame(['a', 'b'], $this->ids2($all(new AnyOf([new AnyOf([new Equals('g', 'y')]), new Equals('g', 'z')]))));
        $this->assertSame([], $this->ids2($all(new In('f', []))), 'In with no values matches nothing');
        $this->assertSame(['a', 'b'], $this->ids2($all(new NotIn('f', []))), 'NotIn with no values matches records that have the field');
        $this->assertSame([], $this->ids2($all(new NotIn('missing', []))));
    }

    public function testFieldNamesIgnoreCaseLikeEverythingElseInMysql(): void
    {
        $this->store->save('entities', ['_id' => 'a', 'Slug' => 'x']);

        $this->assertSame(['a'], $this->ids2(Criteria::fromLegacyArray(['slug' => 'x'])));
    }

    public function testValuesAreComparedAsStringsNotNumbers(): void
    {
        $this->store->save('entities', ['_id' => 'a', 'n' => 1.5, 'm' => '007']);

        // 1.5 is '1.5'; '007' is not 7 and 1 is not '1.0'.
        $this->assertSame(['a'], $this->ids2(Criteria::fromLegacyArray(['n' => 1.5])));
        $this->assertSame(['a'], $this->ids2(Criteria::fromLegacyArray(['m' => '007'])));
        $this->assertSame([], $this->ids2(Criteria::fromLegacyArray(['m' => 7])));
        $this->assertSame([], $this->ids2(Criteria::fromLegacyArray(['n' => '1.50'])));
    }

    public function testSearchWordsAndPhrases(): void
    {
        $this->store->save('entities', ['_id' => 'a', 'body' => 'The quick brown fox']);

        // Up to 5 bytes: one substring, wildcards and escapes as in LIKE.
        $this->assertSame(['a'], $this->ids2(new Criteria(new AllOf([new Search('k b')]))));
        $this->assertSame(['a'], $this->ids2(new Criteria(new AllOf([new Search('t_e')]))));
        $this->assertSame([], $this->ids2(new Criteria(new AllOf([new Search('t\\_e')]))));
        $this->assertSame([], $this->ids2(new Criteria(new AllOf([new Search('50%')]))));
        // Longer: any word, as a substring, in any case; regular-expression characters are plain.
        $this->assertSame(['a'], $this->ids2(new Criteria(new AllOf([new Search('nothing BROWN')]))));
        $this->assertSame(['a'], $this->ids2(new Criteria(new AllOf([new Search('xx uick')]))));
        $this->assertSame([], $this->ids2(new Criteria(new AllOf([new Search('(brown|fox)')]))));
        $this->assertSame([], $this->ids2(new Criteria(new AllOf([new Search('       ')]))), 'no words, no match');
    }

    public function testTheQuoteNormaliserIsAppliedToTheSearchText(): void
    {
        $store = new InMemoryRecordStore(new FixedRecordDefaults(), static fn (string $text): string => str_replace("\u{2019}", "'", $text));
        $store->save('entities', ['_id' => 'a', 'title' => "it\u{2019}s"]);

        $this->assertCount(1, $store->find('entities', new Criteria(new AllOf([new Search("t's")])), 10, 0));
    }

    public function testCollectionsGivenToTheConstructorAreTheOnesThatExist(): void
    {
        $store = new InMemoryRecordStore(new FixedRecordDefaults(), null, ['entities', 'extra']);

        $this->assertSame('a', $store->save('extra', ['_id' => 'a']));
        $this->assertFalse($store->save('reader', ['_id' => 'b']));
        $this->assertTrue($store->deleteAll('extra'));
        $this->assertNull($store->findById('extra', 'a'));
    }
}
