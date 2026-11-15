<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Storage\Criteria;
use Idno\Storage\Criterion\AllOf;
use Idno\Storage\Criterion\AnyOf;
use Idno\Storage\Criterion\Equals;
use Idno\Storage\Criterion\GreaterThan;
use Idno\Storage\Criterion\In;
use Idno\Storage\Criterion\Search;
use Idno\Storage\DbalRecordStore;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\RecordStore;
use Tests\Storage\Contract\RecordStoreContract;

/**
 * The record storage contract against DbalRecordStore on SQLite: a new in-memory database per
 * test, with warmup/schemas/sqlite/sqlite.sql loaded, on a connection set up as the site's would
 * be. It needs nothing but PHP (no server, no configuration, no legacy application), so it runs
 * in the unit tier. The store owns its database, so deleteAll() is tested too.
 *
 * It overrides exactly the hooks the in-memory binding overrides (InMemoryRecordStoreTest), with
 * the same values:
 *  - ownsPrivateDatabase (true);
 *  - expectLongSearchTermToMatchSubstring (true), expectBooleanModeSearch (false): ruling P8, no
 *    full-text index; a long term's words are matched as substrings;
 *  - expectArrayInSearchFieldsToRaise, expectArrayBodyToRaise (false): ruling P5;
 *  - expectFailedQueryCountToFail (false): ruling P6;
 *  - expectMidCharacterCutToLoseTheRecord, expectRejectedMetadataToReportSuccess (false): ruling P7;
 *  - expectFourByteCharactersToBeRejected (false): SQLite stores any UTF-8.
 */
final class DbalSqliteRecordStoreTest extends RecordStoreContract
{
    private Connection $connection;

    private DbalRecordStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = SqliteTestDatabase::withSchema();
        $this->store = $this->newStore($this->connection);
    }

    private function newStore(Connection $connection): DbalRecordStore
    {
        return new DbalRecordStore(
            $connection,
            new SqliteDialect(),
            new FixedRecordDefaults('http://contract.test/', $this->currentSiteId(), fn (): string => $this->newId()),
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

    protected function expectFourByteCharactersToBeRejected(): bool
    {
        return false;
    }

    protected function expectRejectedMetadataToReportSuccess(): bool
    {
        return false;
    }

    protected function expectBooleanModeSearch(): bool
    {
        return false;
    }

    // ------------------------------------------------ specific to SQLite

    /**
     * @return list<string>
     */
    private function found(Criteria $criteria): array
    {
        return array_map(static fn (array $r): string => $r['_id'], $this->store->find('entities', $criteria, 100, 0));
    }

    public function testTheConnectionIsSetUp(): void
    {
        $this->assertSame('1', (string) $this->connection->fetchOne('pragma foreign_keys'));
        $this->assertSame('5000', (string) $this->connection->fetchOne('pragma busy_timeout'));
        // WAL is what a database file gets (Tests/Storage/SqliteConnectionTest); in memory there is no journal file.
        $this->assertSame('memory', (string) $this->connection->fetchOne('pragma journal_mode'));
        $this->assertSame('0', (string) $this->connection->fetchOne("select 'a_' < 'aZ' collate " . SqliteDialect::COLLATION));
        $this->assertSame('1', (string) $this->connection->fetchOne('select ' . SqliteDialect::SEARCH_FUNCTION . "('The quick brown fox', 'xx BROWN')"));

        // Once per physical connection: a new connection after close() is set up again.
        $this->connection->close();
        $this->assertSame('1', (string) $this->connection->fetchOne('pragma foreign_keys'));
        $this->assertSame('0', (string) $this->connection->fetchOne("select 'a_' < 'aZ' collate " . SqliteDialect::COLLATION));
    }

    public function testWithoutTheConnectionSetupTheDatabaseCannotBeUsed(): void
    {
        $plain = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], new Configuration());

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->expectExceptionMessage('no such collation sequence: idno_ci');
        SqliteTestDatabase::loadSchema($plain);
    }

    public function testAFieldNameLongerThan64CharactersIsStored(): void
    {
        // MySQL refuses it (`name` is varchar(64): save() is false, DbalMySqlRecordStoreTest);
        // SQLite does not enforce lengths, like the in-memory store.
        $name = str_repeat('n', 65);
        $record = $this->put($this->r([$name => 'v']));

        $this->assertMatches([$name => 'v'], [$record]);
    }

    public function testANulByteIsStoredAndMatched(): void
    {
        $record = $this->put($this->r(['note' => "a\0b", 'title' => "xx\0yy"]));

        $this->assertSame("a\0b", $this->store->findById('entities', $record['_id'])['note']);
        $this->assertMatches(['note' => "a\0b"], [$record]);
        $this->assertMatches(['note' => 'a'], []);
        $this->assertMatches(['$search' => ["x\0y"]], [$record]);
    }

    public function testInvalidUtf8IsNotSaved(): void
    {
        $record = $this->r(['title' => "invalid \xB1\x31 utf-8"]);

        $this->assertFalse($this->store->save('entities', $record));
        $this->assertNull($this->store->findById('entities', $record['_id']));
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

    public function testCollectionNamesIgnoreCaseAsSqliteTableNamesDo(): void
    {
        $record = $this->put($this->r(['slug' => 'case']));

        $this->assertSame($record['_id'], $this->store->findById('ENTITIES', $record['_id'])['_id'] ?? null);
        $this->assertSame($record['_id'], $this->store->findById('Entities', $record['_id'])['_id'] ?? null);
    }

    public function testATableThatIsNotACollectionIsRejected(): void
    {
        foreach (['session', 'versions'] as $table) {
            $this->assertFalse($this->store->save($table, $this->r()), $table);
            $this->assertSame([], $this->store->find($table, Criteria::all(), 10, 0), $table);
            $this->assertSame(0, $this->store->count($table, Criteria::all()), $table);
            $this->assertFalse($this->store->deleteAll($table), $table);
        }
    }

    public function testEveryCollectionOfTheSchemaWorks(): void
    {
        foreach (['entities', 'reader', 'site', 'config'] as $collection) {
            $record = $this->put($this->r(['slug' => 'in-' . $collection]), $collection);
            $this->assertSame([$record['_id']], $this->ids(['slug' => 'in-' . $collection], $collection), $collection);
            $this->assertTrue($this->store->delete($collection, $record['_id']), $collection);
            $this->assertNull($this->store->findById($collection, $record['_id']), $collection);
            $this->assertSame('0', (string) $this->connection->fetchOne('select count(*) from ' . $collection . '_metadata where _id = ?', [$record['_id']]), $collection);
        }
    }

    public function testAnInvalidCreatedOperandAnywhereMatchesNothing(): void
    {
        $record = $this->put($this->r(['slug' => 'x']));
        $marker = new Equals('contract_marker', $this->marker);
        // The dialect refuses it before a statement is built (MySQL's server rejects it); a valid
        // literal, and NULL, are fine.
        foreach ([new GreaterThan('created', 1686700000), new AnyOf([new Equals('slug', 'x'), new Equals('created', '2023-02-30')]), new In('created', ['2023-06-14', ''])] as $criterion) {
            $criteria = new Criteria(new AllOf([$marker, $criterion]));
            $this->assertSame([], $this->found($criteria));
            $this->assertSame(0, $this->store->count('entities', $criteria));
        }
        $this->assertSame([$record['_id']], $this->found(new Criteria(new AllOf([$marker, new GreaterThan('created', '2000-1-1')]))));
        $this->assertSame([$record['_id']], $this->found(new Criteria(new AllOf([$marker, new AnyOf([new Equals('slug', 'x'), new Equals('created', null)])]))));
    }

    public function testSearchIgnoresTheSitesFullTextSettings(): void
    {
        $record = $this->put($this->r(['body' => 'a sentence about platypuses']));
        $marker = new Equals('contract_marker', $this->marker);

        foreach ([[true, false], [false, false], [true, true], [false, true]] as [$boolean, $bypass]) {
            $store = new DbalRecordStore($this->connection, new SqliteDialect(), new FixedRecordDefaults(), static fn (string $s): string => $s, $boolean, $bypass);
            $this->assertCount(1, $store->find('entities', new Criteria(new AllOf([$marker, new Search('platypu')])), 10, 0));
            // No boolean mode: '+platypuses' and '-sentence' are plain words no record contains.
            $this->assertCount(0, $store->find('entities', new Criteria(new AllOf([$marker, new Search('+platypuses -sentence')])), 10, 0));
        }
        $this->assertNotNull($this->store->findById('entities', $record['_id']));
    }
}
