<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Doctrine\DBAL\Connection;
use Idno\Storage\Criteria;
use Idno\Storage\Criterion\AllOf;
use Idno\Storage\Criterion\AnyOf;
use Idno\Storage\Criterion\Equals;
use Idno\Storage\Criterion\GreaterThan;
use Idno\Storage\Criterion\In;
use Idno\Storage\Criterion\Search;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\IdentityTypeMap;
use Idno\Storage\JsonRecordStore;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\RecordStore;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\TypeMap;
use Tests\Storage\Contract\RecordStoreContract;
use Tests\Storage\PrivateDatabase;

/**
 * The record storage contract against JsonRecordStore on SQLite: a new in-memory database per
 * test with the schema migrations run (PrivateDatabase::sqliteMemory()), on a connection set up as
 * the site's would be. It needs nothing but PHP, so it runs in the unit tier. The store owns its
 * database, so deleteAll() is tested too.
 *
 * The hooks it overrides, and why:
 *  - ownsPrivateDatabase (true);
 *  - expectLongSearchTermToMatchSubstring (true), expectBooleanModeSearch (false): no full-text
 *    index; a long term's words are matched as substrings;
 *  - expectCreatedToBeComparedInTheTimeZoneOfItsSave (false): the store keeps `created` as a
 *    timestamp;
 *  - expectNonAsciiCaseToBeIgnored (false), expectAccentsToBeIgnored (false),
 *    expectExpansionsToBeEqual (false), expectTrailingSpacesToBeIgnored (false): text compares with
 *    SQLite's NOCASE, which folds the case of ASCII letters only;
 *  - expectTextToSortFoldedToUpperCase (true): `$lt` and `$gt` compare text folded to upper case;
 *  - collections: entities, reader and site, the collections of the schema.
 */
final class JsonSqliteRecordStoreTest extends RecordStoreContract
{
    private Connection $connection;

    private JsonRecordStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = PrivateDatabase::sqliteMemory();
        $this->store = $this->newStore(new IdentityTypeMap());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        PrivateDatabase::drop($this->connection);
    }

    private function newStore(TypeMap $types): JsonRecordStore
    {
        return new JsonRecordStore(
            $this->connection,
            new SqliteDialect($this->connection),
            new FixedRecordDefaults('http://contract.test/', $this->currentSiteId(), fn (): string => $this->newId()),
            $types,
            new SchemaCatalog($this->connection),
            static fn (string $text): string => $text,
            true,
            false,
        );
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

    protected function expectAccentsToBeIgnored(): bool
    {
        return false;
    }

    protected function expectExpansionsToBeEqual(): bool
    {
        return false;
    }

    protected function expectTrailingSpacesToBeIgnored(): bool
    {
        return false;
    }

    protected function expectTextToSortFoldedToUpperCase(): bool
    {
        return true;
    }

    protected function expectBooleanModeSearch(): bool
    {
        return false;
    }

    protected function expectNonAsciiCaseToBeIgnored(): bool
    {
        return false;
    }

    protected function expectCreatedToBeComparedInTheTimeZoneOfItsSave(): bool
    {
        return false;
    }

    protected function collections(): array
    {
        return ['entities', 'reader', 'site'];
    }

    // ------------------------------------------------ specific to this implementation

    public function testAFieldNameThatIsNotUtf8MatchesNothing(): void
    {
        $record = $this->put($this->r(['slug' => 'x']));

        foreach (['=' => 'x', '$not' => ['$not' => 'x'], '$in' => ['$in' => ['x']], '$not $in' => ['$not' => ['$in' => ['x']]]] as $name => $condition) {
            $this->assertSame([], $this->store()->find('entities', Criteria::fromLegacyArray(["invalid \xB1 utf-8" => $condition]), 10, 0), $name);
        }
        $this->assertSame([$record['_id']], array_column($this->store()->find('entities', Criteria::fromLegacyArray(['slug' => 'x']), 10, 0), '_id'));
    }

    public function testTheRecordIsStoredAsGivenWithItsColumns(): void
    {
        $record = ['_id' => 'col-1', 'uuid' => 'https://site.test/view/col-1', 'siteid' => 'site', 'owner' => 'me', 'entity_subtype' => 'Tests\\Thing', 'publish_status' => 'draft', 'created' => 1_700_000_000, 'title' => 'Ünïcode/slash', 'f' => 0.1 + 0.2];

        $this->assertSame('col-1', $this->store->save('entities', $record));

        $row = $this->connection->fetchAssociative('select * from idno_entities where id = ?', ['col-1']);
        $this->assertSame(json_encode($record), $row['data'], 'the document is json_encode()d exactly as today');
        $this->assertSame(['id' => 'col-1', 'uuid' => 'https://site.test/view/col-1', 'type' => 'Tests\\Thing', 'owner' => 'me', 'created' => 1_700_000_000, 'publish_status' => 'draft'], array_intersect_key($row, array_flip(['id', 'uuid', 'type', 'owner', 'created', 'publish_status'])));
        $this->assertSame('Ünïcode/slash ', $row['search'], 'the search text, lower-cased (ASCII) as today');
    }

    public function testADocumentWrittenByAnotherProgramIsReadAsItsJson(): void
    {
        // As the upgrade copies 1.x documents: the bytes of `data` are the record, whatever the columns say.
        $data = '{"_id":"copied","uuid":"https:\/\/site.test\/view\/copied","title":"Café","n":1.0,"l":[1,"2"]}';
        $this->connection->insert('idno_entities', ['id' => 'copied', 'uuid' => 'https://site.test/view/copied', 'type' => 'Tests\\Thing', 'owner' => '', 'created' => 1_700_000_000, 'publish_status' => 'published', 'data' => $data, 'search' => '']);

        $this->assertSame(json_decode($data, true), $this->store->findById('entities', 'copied'));
        $this->assertSame(['copied'], array_column($this->store->find('entities', Criteria::fromLegacyArray(['title' => 'café']), 10, 0), '_id'));
        $this->assertSame(['copied'], array_column($this->store->find('entities', Criteria::fromLegacyArray(['l' => '2']), 10, 0), '_id'));
    }

    public function testEntitySubtypeQueriesGoThroughTheTypeMap(): void
    {
        $types = new class () implements TypeMap {
            public function typeFor(string $class): string
            {
                return $class === 'Tests\\Old' || $class === 'Tests\\New' ? 'thing' : $class;
            }

            public function typesFor(string $class): array
            {
                return $class === 'Tests\\New' ? ['thing', 'Tests\\New', 'Tests\\Old'] : [$class];
            }
        };
        $this->store = $this->newStore($types);
        $this->store->save('entities', ['_id' => 'n', 'entity_subtype' => 'Tests\\New', 'created' => 1_700_000_000]);
        $this->store->save('entities', ['_id' => 'o', 'entity_subtype' => 'Tests\\Old', 'created' => 1_700_000_001]);
        $this->store->save('entities', ['_id' => 'x', 'entity_subtype' => 'Tests\\Other', 'created' => 1_700_000_002]);

        $this->assertSame('thing', $this->connection->fetchOne('select type from idno_entities where id = ?', ['n']));
        $ids = fn (array $query): array => array_column($this->store->find('entities', Criteria::fromLegacyArray($query), 10, 0), '_id');
        $this->assertSame(['o', 'n'], $ids(['entity_subtype' => 'Tests\\New']));
        $this->assertSame(['x'], $ids(['entity_subtype' => ['$not' => 'Tests\\New']]));
        $this->assertSame(['x', 'o', 'n'], $ids(['entity_subtype' => ['$in' => ['Tests\\New', 'Tests\\Other']]]));
        $this->assertSame([], $ids(['entity_subtype' => 'Tests\\Old']), 'the type of Tests\\Old is "thing", but the map does not list it for that class');
        // A name with a leading backslash is compared as it is: no class is stored under it.
        $this->assertSame([], $ids(['entity_subtype' => '\\Tests\\New']));
        $this->assertSame([], $ids(['entity_subtype' => ['$in' => ['\\Tests\\New']]]));
        $this->assertSame(['x', 'o', 'n'], $ids(['entity_subtype' => ['$not' => '\\Tests\\New']]));
        $this->assertSame('Tests\\New', $this->store->findById('entities', 'n')['entity_subtype'], 'the document keeps the class name');
    }

    public function testSystemTablesAndOtherTablesAreNoCollections(): void
    {
        // A table without the prefix is not a collection, even one named like a collection.
        $this->connection->executeStatement('create table plain (id text)');
        foreach ([...JsonRecordStore::SYSTEM_TABLES, 'plain', 'nosuch', 'idno_entities'] as $name) {
            $this->assertFalse($this->store->save($name, ['_id' => 'x']), $name);
            $this->assertNull($this->store->findById($name, 'x'), $name);
            $this->assertNull($this->store->findByUuid($name, 'x'), $name);
            $this->assertNull($this->store->findLatest($name), $name);
            $this->assertSame([], $this->store->find($name, Criteria::all(), 10, 0), $name);
            $this->assertSame(0, $this->store->count($name, Criteria::all()), $name);
            $this->assertFalse($this->store->delete($name, 'x'), $name);
            $this->assertFalse($this->store->deleteAll($name), $name);
        }
        $this->assertSame(0, (int) $this->connection->fetchOne('select count(*) from idno_sessions'));
        $this->assertSame(0, (int) $this->connection->fetchOne('select count(*) from plain'));
    }

    public function testACollectionNameIsMatchedWithoutRegardToCase(): void
    {
        $this->assertSame('mixed', $this->store->save('Entities', ['_id' => 'mixed', 'created' => 1_700_000_000]));

        $this->assertSame('mixed', $this->store->findById('entities', 'mixed')['_id']);
    }

    public function testARecordThatCannotBeEncodedIsNotSaved(): void
    {
        $this->assertFalse($this->store->save('entities', ['_id' => 'bad', 'title' => "invalid \xB1\x31 utf-8"]));

        $this->assertNull($this->store->findById('entities', 'bad'));
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

    public function testCreatedIsATimestampAndFractionalOperandsCompareExactly(): void
    {
        $this->store->save('entities', ['_id' => 'neg', 'created' => -100]);
        $at = date('Y-m-d H:i:s', -100);

        $ids = fn (array $query): array => array_column($this->store->find('entities', Criteria::fromLegacyArray($query), 10, 0), '_id');
        $this->assertSame(-100, (int) $this->connection->fetchOne('select created from idno_entities where id = ?', ['neg']));
        $this->assertSame(['neg'], $ids(['created' => $at]));
        $this->assertSame(['neg'], $ids(['created' => ['$lt' => $at . '.25']]));
        $this->assertSame([], $ids(['created' => ['$gt' => $at . '.25']]));
        $this->assertSame(['neg'], $ids(['created' => ['$gt' => date('Y-m-d H:i:s', -101) . '.75']]));
    }

    // ------------------------------------------------ moved from the old store's SQLite binding

    public function testANulByteIsStoredAndMatched(): void
    {
        $record = $this->put($this->r(['note' => "a\0b", 'title' => "xx\0yy"]));

        $this->assertSame("a\0b", $this->store->findById('entities', $record['_id'])['note']);
        $this->assertMatches(['note' => "a\0b"], [$record]);
        $this->assertMatches(['note' => 'a'], []);
        $this->assertMatches(['$search' => ["x\0y"]], [$record]);
    }

    public function testIdsAndUuidsIgnoreCaseAsInMysql(): void
    {
        $record = $this->put($this->r(['_id' => 'abc-' . $this->marker]));
        $record['uuid'] = 'http://contract.test/view/abc-' . $this->marker;
        $this->put($record);

        $this->assertSame($record['_id'], $this->store->findById('entities', strtoupper($record['_id']))['_id'] ?? null);
        $this->assertSame($record['_id'], $this->store->findByUuid('entities', strtoupper($record['uuid']))['_id'] ?? null);
        // Another record whose uuid differs only in case is a duplicate: the save fails.
        $clash = $this->r(['uuid' => strtoupper($record['uuid'])]);
        $this->track($clash['_id']);
        $this->assertFalse($this->store->save('entities', $clash));
        $this->assertNull($this->store->findById('entities', $clash['_id']));
    }

    public function testAnInvalidCreatedOperandAnywhereMatchesNothing(): void
    {
        $record = $this->put($this->r(['slug' => 'x']));
        $marker = new Equals('contract_marker', $this->marker);
        $found = fn (Criteria $criteria): array => array_column($this->store->find('entities', $criteria, 10, 0), '_id');
        // Read in PHP and refused before a statement is built, wherever it is; a valid literal, and
        // NULL, are fine.
        foreach ([new GreaterThan('created', 1686700000), new AnyOf([new Equals('slug', 'x'), new Equals('created', '2023-02-30')]), new In('created', ['2023-06-14', ''])] as $criterion) {
            $criteria = new Criteria(new AllOf([$marker, $criterion]));
            $this->assertSame([], $found($criteria));
            $this->assertSame(0, $this->store->count('entities', $criteria));
        }
        $this->assertSame([$record['_id']], $found(new Criteria(new AllOf([$marker, new GreaterThan('created', '2000-1-1')]))));
        $this->assertSame([$record['_id']], $found(new Criteria(new AllOf([$marker, new AnyOf([new Equals('slug', 'x'), new Equals('created', null)])]))));
    }

    public function testSearchIgnoresTheSitesFullTextSettings(): void
    {
        $record = $this->put($this->r(['body' => 'a sentence about platypuses']));
        $marker = new Equals('contract_marker', $this->marker);

        foreach ([[true, false], [false, false], [true, true], [false, true]] as [$boolean, $bypass]) {
            $store = new JsonRecordStore($this->connection, new SqliteDialect(), new FixedRecordDefaults(), new IdentityTypeMap(), new SchemaCatalog($this->connection), static fn (string $s): string => $s, $boolean, $bypass);
            $this->assertCount(1, $store->find('entities', new Criteria(new AllOf([$marker, new Search('platypu')])), 10, 0));
            // No boolean mode: '+platypuses' and '-sentence' are plain words no record contains.
            $this->assertCount(0, $store->find('entities', new Criteria(new AllOf([$marker, new Search('+platypuses -sentence')])), 10, 0));
        }
        $this->assertNotNull($this->store->findById('entities', $record['_id']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function collectionsOfTheSchema(): array
    {
        return ['entities' => ['entities'], 'reader' => ['reader'], 'site' => ['site']];
    }

    /**
     * Records a (uuid u-a) and b (uuid u-b) in the collection.
     */
    private function twoRecords(string $collection): void
    {
        $this->assertSame('a', $this->store->save($collection, ['_id' => 'a', 'uuid' => 'u-a', 'title' => 'A', 'created' => 1686700000]));
        $this->assertSame('b', $this->store->save($collection, ['_id' => 'b', 'uuid' => 'u-b', 'title' => 'B', 'created' => 1686700000]));
    }

    /**
     * The rows of the collection's table: `uuid id title`, by id.
     *
     * @return list<string>
     */
    private function rows(string $collection): array
    {
        return array_map(
            static fn (array $row): string => $row['uuid'] . ' ' . $row['id'] . ' ' . (json_decode((string) $row['data'], true)['title'] ?? ''),
            $this->connection->fetchAllAssociative('select uuid, id, data from idno_' . $collection . ' order by id, uuid'),
        );
    }

    /**
     * Every collection the same: a re-save updates in place; a record re-saved with another
     * record's uuid, and a new record with an existing uuid, are refused with both rows intact; a
     * record re-saved with a new uuid keeps its one row. (The old store's `site` and `config`,
     * whose _id was not unique, overwrote the other record's row in the first case and kept a second
     * row for the same _id in the last.)
     *
     * @dataProvider collectionsOfTheSchema
     */
    public function testUuidChangesAreTheSameInEveryCollection(string $collection): void
    {
        $this->twoRecords($collection);

        $this->assertSame('b', $this->store->save($collection, ['_id' => 'b', 'uuid' => 'u-b', 'title' => 'B2', 'created' => 1686700000]));
        $this->assertSame(['u-a a A', 'u-b b B2'], $this->rows($collection));

        $this->assertFalse($this->store->save($collection, ['_id' => 'b', 'uuid' => 'u-a', 'title' => 'B3', 'created' => 1686700000]));
        $this->assertFalse($this->store->save($collection, ['_id' => 'c', 'uuid' => 'u-a', 'title' => 'C', 'created' => 1686700000]));
        $this->assertSame(['u-a a A', 'u-b b B2'], $this->rows($collection));

        $this->assertSame('b', $this->store->save($collection, ['_id' => 'b', 'uuid' => 'u-new', 'title' => 'B4', 'created' => 1686700000]));
        $this->assertSame(['u-a a A', 'u-new b B4'], $this->rows($collection));
    }
}
