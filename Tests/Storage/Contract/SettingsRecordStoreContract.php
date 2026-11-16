<?php

declare(strict_types=1);

namespace Tests\Storage\Contract;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Idno\Storage\Criteria;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\IdentityTypeMap;
use Idno\Storage\InMemoryRecordStore;
use Idno\Storage\JsonRecordStore;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\RecordStore;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SettingsRecordStore;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Storage\FailingStatementMiddleware;

/**
 * SettingsRecordStore against a database with the schema migrations run: the tests are written
 * once and bound to an engine by a subclass (SQLite in the unit tier, a private MySQL or MariaDB
 * database in the rig tier), which says how to open the database and which dialect it uses.
 *
 * The inner store is a real JsonRecordStore on the same connection, so that "every other
 * collection is the inner store's" is tested against real tables, and so that the document a
 * save reads back can be compared with what the JSON store gives for the same record (the
 * settings are one document, read back as the JSON store reads one).
 */
abstract class SettingsRecordStoreContract extends TestCase
{
    protected FailingStatementMiddleware $failure;

    protected Connection $connection;

    protected RecordStore $inner;

    protected SettingsRecordStore $store;

    /**
     * Opens a database with the schema migrations run and `idno_settings` and the collection
     * tables empty, on a connection that has the middleware among its driver middlewares.
     */
    abstract protected function openDatabase(FailingStatementMiddleware $failure): Connection;

    abstract protected function closeDatabase(Connection $connection): void;

    /**
     * A NEW connection to the same database (no middleware), which waits only briefly for a lock
     * another connection holds (a second writer).
     */
    abstract protected function openSecond(): Connection;

    abstract protected function dialect(Connection $connection): Dialect;

    protected function setUp(): void
    {
        $this->failure = new FailingStatementMiddleware();
        $this->connection = $this->openDatabase($this->failure);
        $this->inner = $this->newInner();
        $this->store = $this->newStore();
    }

    protected function tearDown(): void
    {
        $this->closeDatabase($this->connection);
    }

    protected function newInner(): RecordStore
    {
        return new JsonRecordStore(
            $this->connection,
            $this->dialect($this->connection),
            new FixedRecordDefaults(),
            new IdentityTypeMap(),
            new SchemaCatalog($this->connection),
            static fn (string $text): string => $text,
            true,
            false,
        );
    }

    protected function newStore(): SettingsRecordStore
    {
        return new SettingsRecordStore($this->inner, $this->connection, $this->dialect($this->connection), new FixedRecordDefaults());
    }

    /**
     * A settings store on another connection to the same database.
     */
    protected function storeOn(Connection $connection): SettingsRecordStore
    {
        $inner = new JsonRecordStore($connection, $this->dialect($connection), new FixedRecordDefaults(), new IdentityTypeMap(), new SchemaCatalog($connection), static fn (string $text): string => $text, true, false);

        return new SettingsRecordStore($inner, $connection, $this->dialect($connection), new FixedRecordDefaults());
    }

    /**
     * A document whose keys are not in alphabetical order and whose values cover every JSON type.
     *
     * @return array<string, mixed>
     */
    protected static function document(string $id = 'settings-1'): array
    {
        return [
            'zeta' => 'written first, sorts last',
            '_id' => $id,
            'uuid' => 'http://example.test/view/' . $id,
            'siteid' => 'fixed-site',
            'owner' => '',
            'alpha' => ['nested' => ['b' => 1, 'a' => 2], 'list' => [3, 2, 1], 'empty' => [], 'object' => new \stdClass()],
            'flag' => true,
            'off' => false,
            'nothing' => null,
            'blank' => '',
            'zero' => 0,
            'negative' => -12,
            'ratio' => 0.5,
            'whole' => 1.0,
            'big' => 9_007_199_254_740_993,
            'text' => "caf\u{e9} \u{1F600} \"quoted\" / slash \\ back \n newline",
            'created' => 1_700_000_000,
        ];
    }

    /**
     * What the JSON store gives back for the record: the document as a settings store must give it.
     *
     * @param array<int|string, mixed> $record
     *
     * @return array<string, mixed>
     */
    protected function jsonStoreReads(array $record): array
    {
        $id = $this->inner->save('entities', $record);
        $this->assertNotFalse($id);
        $document = $this->inner->findById('entities', $id);
        $this->assertNotNull($document);
        $this->inner->deleteAll('entities');

        return $document;
    }

    /**
     * The rows of `idno_settings`, in the order of their position: name => [value, position].
     *
     * @return array<int|string, array{string, int}>
     */
    protected function rows(): array
    {
        $rows = [];
        foreach ($this->connection->fetchAllAssociative('select name, value, position from idno_settings order by position') as $row) {
            $rows[$row['name']] = [(string) $row['value'], (int) $row['position']];
        }

        return $rows;
    }

    // ----------------------------------------------------------------------------- pass-through

    public function testOtherCollectionsPassThrough(): void
    {
        $id = $this->store->save('entities', ['_id' => 'entity-1', 'title' => 'An entity', 'created' => 1_700_000_000]);

        $this->assertSame('entity-1', $id);
        $this->assertSame('An entity', $this->inner->findById('entities', 'entity-1')['title'] ?? null, 'the inner store holds it');
        $this->assertSame('An entity', $this->store->findById('entities', 'entity-1')['title'] ?? null);
        $this->assertSame('entity-1', $this->store->findByUuid('entities', 'http://example.test/view/entity-1')['_id'] ?? null);
        $this->assertSame('entity-1', $this->store->findLatest('entities')['_id'] ?? null);
        $this->assertSame(1, $this->store->count('entities', Criteria::fromLegacyArray(['title' => 'An entity'])));
        $this->assertCount(1, $this->store->find('entities', Criteria::all(), 10, 0));
        $this->assertSame([], $this->rows(), 'nothing of it reached the settings');

        $this->assertSame('site-1', $this->store->save('site', ['_id' => 'site-1', 'title' => 'A site']));
        $this->assertSame('A site', $this->inner->findById('site', 'site-1')['title'] ?? null);

        // A collection that is none is the inner store's answer, not a settings document.
        $this->assertFalse($this->store->save('nonexistent', ['_id' => 'x']));
        $this->assertNull($this->store->findLatest('nonexistent'));
        $this->assertSame(0, $this->store->count('nonexistent', Criteria::all()));
        $this->assertFalse($this->store->deleteAll('nonexistent'));

        $this->assertTrue($this->store->delete('entities', 'entity-1'));
        $this->assertNull($this->inner->findById('entities', 'entity-1'));
        $this->assertTrue($this->store->deleteAll('site'));
        $this->assertNull($this->inner->findLatest('site'));
    }

    public function testSettingsOperationsNeverReachTheInnerStore(): void
    {
        $this->inner->save('entities', ['_id' => 'entity-1', 'created' => 1_700_000_000]);

        $this->assertNotFalse($this->store->save('config', self::document()));
        $this->assertTrue($this->store->deleteAll('config'));

        $this->assertNotNull($this->inner->findById('entities', 'entity-1'), 'deleteAll(config) leaves the entities');
        $this->assertSame(1, $this->inner->count('entities', Criteria::all()));
    }

    public function testTheCollectionNameIsReducedAsOtherStoresReduceIt(): void
    {
        $this->assertSame('settings-1', $this->store->save('Con-fig', self::document()));

        $this->assertSame('settings-1', $this->store->findLatest('CONFIG')['_id'] ?? null);
        $this->assertSame(1, $this->store->count('config', Criteria::all()));
    }

    // ----------------------------------------------------------------------------- save and read

    public function testSaveStoresOneRowPerKeyAndRoundTrips(): void
    {
        $document = self::document();
        $expected = $this->jsonStoreReads($document);

        $this->assertSame('settings-1', $this->store->save('config', $document));

        $this->assertSame($expected, $this->store->findLatest('config'), 'what the JSON store reads for the same record, key order and types included');
        $this->assertSame(array_keys($expected), array_keys($this->rows()), 'one row per top-level key, in the document order');
        $this->assertSame(count($expected), count($this->rows()));
        $this->assertSame(SettingsRecordStore::rowsOf($document), array_map(static fn (array $row): string => $row[0], $this->rows()));
        // Pinned values.
        $this->assertSame('{"nested":{"b":1,"a":2},"list":[3,2,1],"empty":[],"object":{}}', $this->rows()['alpha'][0]);
        $this->assertSame('null', $this->rows()['nothing'][0]);
        $this->assertSame('""', $this->rows()['blank'][0]);
        $this->assertSame('true', $this->rows()['flag'][0]);
        $this->assertSame('false', $this->rows()['off'][0]);
        $this->assertSame(json_encode(1.0), $this->rows()['whole'][0], 'a whole float is the text json_encode() gives it, as in the JSON store');
        $this->assertSame(['b' => 1, 'a' => 2], $expected['alpha']['nested']);
        $this->assertSame(true, $this->store->findLatest('config')['flag']);
        $this->assertSame(false, $this->store->findLatest('config')['off']);
        $this->assertNull($this->store->findLatest('config')['nothing']);
        $this->assertSame('', $this->store->findLatest('config')['blank']);
    }

    public function testSaveFillsDefaultsAsTheJsonStoreDoes(): void
    {
        $record = ['title' => 'No identity', 'created' => 1_700_000_000];
        $expected = $this->jsonStoreReads($record + ['_id' => '00000000-0000-4000-8000-000000000001', 'siteid' => 'fixed-site', 'uuid' => 'http://example.test/view/00000000-0000-4000-8000-000000000001', 'owner' => '']);

        $id = $this->store->save('config', $record);

        $this->assertSame('00000000-0000-4000-8000-000000000001', $id);
        $this->assertSame($expected, $this->store->findLatest('config'));
        $this->assertSame(['title', 'created', '_id', 'siteid', 'uuid', 'owner'], array_keys($this->rows()), 'the defaults are appended, as the JSON store appends them');
    }

    public function testThePositionColumnHoldsTheIndexOfTheKey(): void
    {
        $this->store->save('config', self::document());

        $positions = array_map(static fn (array $row): int => $row[1], $this->rows());

        $this->assertSame(range(0, count($positions) - 1), array_values($positions));
    }

    public function testKeysAreCaseSensitiveAndNumericKeysComeBackAsPhpGivesThem(): void
    {
        $document = ['_id' => 'k', 'Title' => 'upper', 'title' => 'lower', 'TITLE' => 'shout', 5 => 'five', '0' => 'zero', '-3' => 'minus', '1.5' => 'decimal', '01' => 'padded', '+1' => 'plus', ' lead' => 'leading space'];
        $expected = $this->jsonStoreReads($document);

        $this->assertSame('k', $this->store->save('config', $document));

        $read = $this->store->findLatest('config');
        $this->assertSame($expected, $read);
        // Observed: PHP turns the decimal-integer strings into int keys, in json_decode() as here.
        $this->assertSame(['_id', 'Title', 'title', 'TITLE', 5, 0, -3, '1.5', '01', '+1', ' lead', 'siteid', 'uuid', 'owner'], array_keys($read));
        $this->assertSame('five', $read[5]);
        $this->assertSame('zero', $read[0]);
        $this->assertSame('minus', $read[-3]);
        $this->assertSame(['_id', 'Title', 'title', 'TITLE', '5', '0', '-3', '1.5', '01', '+1', ' lead', 'siteid', 'uuid', 'owner'], array_map('strval', array_keys($this->rows())), 'the table holds each key as a string');
    }

    public function testADocumentThatIsAListIsStoredAsItsIntegerKeys(): void
    {
        $document = [0 => 'a', 1 => 'b', '_id' => 'list'];
        $expected = $this->jsonStoreReads($document);

        $this->assertSame('list', $this->store->save('config', $document));

        $this->assertSame($expected, $this->store->findLatest('config'));
    }

    public function testRowsOfIsPureAndAppliesNoDefaults(): void
    {
        $this->assertSame([], SettingsRecordStore::rowsOf([]));
        $this->assertSame(['b' => '1', 'a' => '[1,2]', 'c' => '"x"', 'd' => 'null', 'e' => 'true', 'f' => '0.5', 'g' => '{"k":{}}'], SettingsRecordStore::rowsOf(['b' => 1, 'a' => [1, 2], 'c' => 'x', 'd' => null, 'e' => true, 'f' => 0.5, 'g' => ['k' => new \stdClass()]]));
        $this->assertSame(['zeta' => '1', 'alpha' => '2'], SettingsRecordStore::rowsOf(['zeta' => 1, 'alpha' => 2]), 'document order, not sorted');
        $this->assertSame('{"a\\u00e9":"\\u00e9"}', SettingsRecordStore::rowsOf(['k' => ["a\u{e9}" => "\u{e9}"]])['k'], 'encoded as the JSON store encodes a document: no flags');
        $this->assertSame([5 => '"x"'], SettingsRecordStore::rowsOf([5 => 'x']), 'an integer key is a string key of the table (PHP shows it as an int again)');
        $this->assertSame([], $this->rows(), 'nothing was written');
    }

    /**
     * @param array<int|string, mixed> $document
     *
     * @dataProvider documentsTheTableCannotHold
     */
    public function testRowsOfRefusesWhatTheTableCannotHold(array $document): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SettingsRecordStore::rowsOf($document);
    }

    /**
     * @return array<string, array{array<int|string, mixed>}>
     */
    public static function documentsTheTableCannotHold(): array
    {
        return [
            'a value that is NAN' => [['a' => 1, 'b' => NAN]],
            'a value that is infinite' => [['b' => INF]],
            'a string that is not UTF-8' => [['b' => "\xff\xfe"]],
            'a value nested too deep' => [['b' => self::nested(600)]],
            'a resource' => [['b' => STDIN]],
            'an empty key' => [['' => 1]],
            'a key over 190 bytes' => [[str_repeat('k', 191) => 1]],
            'a key of two-byte characters over 190 bytes' => [[str_repeat("\u{e9}", 96) => 1]],
            'a key that is not UTF-8' => [["bad\xffkey" => 1]],
            'a key ending in a space' => [['key ' => 1]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function nested(int $depth): array
    {
        $value = ['leaf' => 1];
        for ($i = 0; $i < $depth; ++$i) {
            $value = ['n' => $value];
        }

        return $value;
    }

    public function testAKeyOf190BytesIsStored(): void
    {
        $ascii = str_repeat('k', 190);
        $accented = str_repeat("\u{e9}", 95);
        $document = ['_id' => 'long', $ascii => 'ascii', $accented => 'accented'];

        $this->assertSame('long', $this->store->save('config', $document));

        $read = $this->store->findLatest('config');
        $this->assertSame('ascii', $read[$ascii] ?? null);
        $this->assertSame('accented', $read[$accented] ?? null);
    }

    public function testASecondSaveReplacesTheDocument(): void
    {
        $this->store->save('config', ['_id' => 'one', 'a' => 1, 'b' => 2, 'c' => 3, 'created' => 1_700_000_000]);
        $second = ['_id' => 'one', 'd' => 4, 'b' => 20, 'created' => 1_700_000_100];

        $this->assertSame('one', $this->store->save('config', $second));

        $expected = $this->jsonStoreReads($second + ['siteid' => 'fixed-site', 'uuid' => 'http://example.test/view/one', 'owner' => '']);
        $this->assertSame($expected, $this->store->findLatest('config'), 'a, c and the first created are gone; the order is the second save\'s');
        $this->assertSame(count($expected), count($this->rows()), 'the row count is the key count');
        $this->assertArrayNotHasKey('a', $this->rows());
        $this->assertArrayNotHasKey('c', $this->rows());
        $this->assertSame(['_id', 'd', 'b', 'created', 'siteid', 'uuid', 'owner'], array_map('strval', array_keys($this->rows())));
    }

    public function testSavingTheSameDocumentAgainChangesNothing(): void
    {
        $this->store->save('config', self::document());
        $rows = $this->rows();

        $this->assertSame('settings-1', $this->store->save('config', self::document()));

        $this->assertSame($rows, $this->rows());
    }

    public function testSavingAnotherIdReplacesTheDocument(): void
    {
        $this->store->save('config', ['_id' => 'one', 'only_in_one' => 1, 'shared' => 'first', 'created' => 1_700_000_000]);

        $this->assertSame('two', $this->store->save('config', ['_id' => 'two', 'shared' => 'second', 'created' => 1_700_000_100]));

        $this->assertSame(1, $this->store->count('config', Criteria::all()), 'config holds one document');
        $this->assertNull($this->store->findById('config', 'one'));
        $this->assertSame('second', $this->store->findById('config', 'two')['shared'] ?? null);
        $this->assertArrayNotHasKey('only_in_one', $this->rows());
        $this->assertSame('"two"', $this->rows()['_id'][0]);
    }

    public function testALargeDocumentRoundTripsAndShrinks(): void
    {
        $large = ['_id' => 'large'];
        for ($i = 0; $i < 1_300; ++$i) {
            $large['key_' . $i] = ['value' => $i];
        }

        $this->assertSame('large', $this->store->save('config', $large));
        $this->assertSame(1_300 + 4, count($this->rows()));
        $this->assertSame(['value' => 1_299], $this->store->findLatest('config')['key_1299'] ?? null);

        $this->assertSame('large', $this->store->save('config', ['_id' => 'large', 'key_7' => 'kept']));

        $this->assertSame(['_id', 'key_7', 'siteid', 'uuid', 'owner'], array_map('strval', array_keys($this->rows())));
        $this->assertSame('kept', $this->store->findLatest('config')['key_7'] ?? null);
    }

    // ----------------------------------------------------------------------------- finders

    public function testFindLatestAndFindByIdAndUuid(): void
    {
        $this->assertNull($this->store->findLatest('config'));
        $this->assertNull($this->store->findById('config', 'settings-1'));
        $this->assertNull($this->store->findByUuid('config', 'http://example.test/view/settings-1'));

        $this->store->save('config', self::document());
        $expected = $this->jsonStoreReads(self::document());

        $this->assertSame($expected, $this->store->findLatest('config'));
        $this->assertSame($expected, $this->store->findById('config', 'settings-1'));
        $this->assertSame($expected, $this->store->findByUuid('config', 'http://example.test/view/settings-1'));
        $this->assertNull($this->store->findById('config', 'other'));
        $this->assertNull($this->store->findById('config', 'SETTINGS-1x'));
        $this->assertNull($this->store->findByUuid('config', 'http://example.test/view/other'));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function criteria(): array
    {
        return [
            'none' => [[]],
            'a field that equals' => [['title' => 'Hello']],
            'a field that differs' => [['title' => 'Other']],
            'equals ignoring case' => [['title' => 'hello']],
            'a list element' => [['tags' => 'b']],
            'a list element that is absent' => [['tags' => 'z']],
            'in' => [['title' => ['$in' => ['x', 'Hello']]]],
            'in that misses' => [['title' => ['$in' => ['x', 'y']]]],
            'not' => [['title' => ['$not' => ['$in' => ['Hello']]]]],
            'not equal' => [['title' => ['$not' => 'Hello']]],
            'greater' => [['count' => ['$gt' => '0']]],
            'less' => [['count' => ['$lt' => '1']]],
            'a field the document lacks' => [['missing' => 'x']],
            'the id' => [['_id' => 'settings-1']],
            'another id' => [['_id' => 'other']],
            'the uuid' => [['uuid' => 'http://example.test/view/settings-1']],
            'owner' => [['owner' => '']],
            'created before' => [['created' => ['$lt' => '2099-01-01 00:00:00']]],
            'created after' => [['created' => ['$gt' => '2099-01-01 00:00:00']]],
            'a created operand that is no datetime' => [['created' => 5]],
            'two conditions that hold' => [['title' => 'Hello', 'tags' => 'a']],
            'two conditions, one fails' => [['title' => 'Hello', 'tags' => 'z']],
            'a search that matches' => [['$search' => ['hello']]],
            'a search that misses' => [['$search' => ['absent']]],
            'or' => [['$or' => [['title' => 'x'], ['tags' => 'a']]]],
            'or that misses' => [['$or' => [['title' => 'x'], ['tags' => 'q']]]],
            'an empty or' => [['$or' => []]],
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @dataProvider criteria
     */
    public function testFindAndCountEvaluateCriteria(array $query): void
    {
        $document = ['_id' => 'settings-1', 'title' => 'Hello', 'tags' => ['a', 'b'], 'count' => 0, 'created' => 1_700_000_000, 'zeta' => 1];
        $this->store->save('config', $document);
        $memory = new InMemoryRecordStore(new FixedRecordDefaults(), null, ['config']);
        $memory->save('config', $document);
        $criteria = Criteria::fromLegacyArray($query);

        $expectedCount = $memory->count('config', $criteria);

        $this->assertSame($expectedCount, $this->store->count('config', $criteria));
        $found = $this->store->find('config', $criteria, 10, 0);
        $this->assertCount($expectedCount, $found);
        if ($expectedCount === 1) {
            $this->assertSame($this->store->findLatest('config'), $found[0]);
            $this->assertSame([], $this->store->find('config', $criteria, 10, 1), 'offset past the one document');
            $this->assertSame([], $this->store->find('config', $criteria, 0, 0));
            $this->assertSame($expectedCount, $this->store->count('config', $criteria), 'count ignores paging');
        }
    }

    public function testFindAndCountPinnedValues(): void
    {
        $this->store->save('config', ['_id' => 'settings-1', 'title' => 'Hello', 'tags' => ['a', 'b'], 'created' => 1_700_000_000]);

        $this->assertSame(1, $this->store->count('config', Criteria::all()));
        $this->assertSame(1, $this->store->count('config', Criteria::fromLegacyArray(['title' => 'Hello'])));
        $this->assertSame(1, $this->store->count('config', Criteria::fromLegacyArray(['tags' => 'b'])));
        $this->assertSame(0, $this->store->count('config', Criteria::fromLegacyArray(['title' => 'Other'])));
        $this->assertSame(0, $this->store->count('config', Criteria::fromLegacyArray(['created' => 5])), 'an operand that is no datetime matches nothing');
        $this->assertSame(0, $this->store->count('config', Criteria::fromLegacyArray(['$or' => []])));
        $this->assertCount(1, $this->store->find('config', Criteria::all(), 1, 0));
        $this->assertSame([], $this->store->find('config', Criteria::all(), 1, 1));
        $this->assertSame([], $this->store->find('config', Criteria::all(), 0, 0));
        $this->assertSame([], $this->store->find('config', Criteria::all(), 5, -1));

        $this->store->deleteAll('config');
        $this->assertSame([], $this->store->find('config', Criteria::all(), 10, 0));
        $this->assertSame(0, $this->store->count('config', Criteria::all()));
    }

    // ----------------------------------------------------------------------------- deletes

    public function testDeleteAndDeleteAll(): void
    {
        $this->assertTrue($this->store->delete('config', 'settings-1'), 'deleting from an empty collection succeeds');
        $this->assertTrue($this->store->deleteAll('config'));

        $this->store->save('config', self::document());
        $rows = $this->rows();

        $this->assertTrue($this->store->delete('config', 'another-id'), 'an unknown id succeeds');
        $this->assertSame($rows, $this->rows(), 'and removes nothing');

        $this->assertTrue($this->store->delete('config', 'settings-1'));
        $this->assertSame([], $this->rows(), 'the document\'s id removes every row');
        $this->assertNull($this->store->findLatest('config'));

        $this->store->save('config', self::document('second'));
        $this->assertTrue($this->store->deleteAll('config'));
        $this->assertSame([], $this->rows());
        $this->assertSame(0, $this->store->count('config', Criteria::all()));
    }

    // ----------------------------------------------------------------------------- refusals

    public function testAnUnencodableValueOrOverlongKeyIsRefusedAndTheOldDocumentKept(): void
    {
        $this->store->save('config', ['_id' => 'kept', 'a' => 1, 'b' => ['x' => 'y'], 'created' => 1_700_000_000]);
        $rows = $this->rows();
        $document = $this->store->findLatest('config');

        $refused = [
            'NAN' => ['_id' => 'kept', 'c' => NAN],
            'invalid UTF-8' => ['_id' => 'kept', 'c' => "\xff"],
            'a key over 190 bytes' => ['_id' => 'kept', str_repeat('k', 191) => 1, 'a' => 'changed'],
            'a multi-byte key over 190 bytes' => ['_id' => 'kept', str_repeat("\u{e9}", 96) => 1, 'a' => 'changed'],
            'an empty key' => ['_id' => 'kept', '' => 1, 'a' => 'changed'],
            'a key that is not UTF-8' => ['_id' => 'kept', "k\xff" => 1, 'a' => 'changed'],
            'a key ending in a space' => ['_id' => 'kept', 'a' => 'changed', 'trailing ' => 1],
            'another id' => ['_id' => 'another', 'a' => 'changed', 'c' => NAN],
        ];
        foreach ($refused as $case => $record) {
            $this->assertFalse($this->store->save('config', $record), $case);
            $this->assertSame($rows, $this->rows(), $case . ': nothing changed');
            $this->assertSame($document, $this->store->findLatest('config'), $case);
        }
    }

    public function testAKeyEndingInASpaceWouldCollideWithItsNeighbourOnMySql(): void
    {
        // utf8mb4_bin compares as if padded with spaces, so 'a' and 'a ' are one key there:
        // refused on every engine, rather than silently merged on one.
        $this->assertFalse($this->store->save('config', ['_id' => 'x', 'a' => 1, 'a ' => 2]));
        $this->assertSame([], $this->rows());
    }

    public function testACreatedThatIsNotATimestampIsThrown(): void
    {
        $this->store->save('config', ['_id' => 'kept', 'a' => 1, 'created' => 1_700_000_000]);
        $rows = $this->rows();

        try {
            $this->store->save('config', ['_id' => 'kept', 'a' => 2, 'created' => ['not', 'a', 'timestamp']]);
            $this->fail('save() should throw');
        } catch (\InvalidArgumentException) {
            $this->assertSame($rows, $this->rows());
        }
    }

    // ----------------------------------------------------------------------------- failures

    /**
     * @return array<string, array{string, \Closure(SettingsRecordStore): mixed}>
     */
    public static function failingReadsAndDeletes(): array
    {
        $criteria = Criteria::fromLegacyArray(['title' => 'Before']);

        return [
            'findLatest' => ['/^select .*idno_settings.* order by/', static fn (SettingsRecordStore $store): mixed => $store->findLatest('config')],
            'findById' => ['/^select .*idno_settings.* order by/', static fn (SettingsRecordStore $store): mixed => $store->findById('config', 'kept')],
            'findByUuid' => ['/^select .*idno_settings.* order by/', static fn (SettingsRecordStore $store): mixed => $store->findByUuid('config', 'http://example.test/view/kept')],
            'find' => ['/^select .*idno_settings.* order by/', static fn (SettingsRecordStore $store): mixed => $store->find('config', $criteria, 10, 0)],
            'count' => ['/^select .*idno_settings.* order by/', static fn (SettingsRecordStore $store): mixed => $store->count('config', $criteria)],
            'delete of the id row' => ['/^delete from .idno_settings. where/', static fn (SettingsRecordStore $store): mixed => $store->delete('config', 'kept')],
            'delete of the other rows' => ['/^delete from .idno_settings.$/', static fn (SettingsRecordStore $store): mixed => $store->delete('config', 'kept')],
            'delete commit' => ['/^COMMIT$/', static fn (SettingsRecordStore $store): mixed => $store->delete('config', 'kept')],
            'deleteAll' => ['/^delete from .idno_settings.$/', static fn (SettingsRecordStore $store): mixed => $store->deleteAll('config')],
        ];
    }

    /**
     * @param \Closure(SettingsRecordStore): mixed $call
     *
     * @dataProvider failingReadsAndDeletes
     */
    public function testADatabaseFailureIsThrown(string $statement, \Closure $call): void
    {
        $this->store->save('config', ['_id' => 'kept', 'title' => 'Before', 'created' => 1_700_000_000]);
        $rows = $this->rows();
        $this->failure->failOn($statement);

        $thrown = self::thrown(fn (): mixed => $call($this->store));

        $this->assertInstanceOf(DriverException::class, $thrown);
        $this->assertStringEndsWith(FailingStatementMiddleware::MESSAGE, $thrown->getMessage());
        $this->assertCount(1, $this->failure->failed, 'the chosen statement ran and failed');
        $this->assertSame($rows, $this->rows(), 'nothing was deleted');
        $this->assertFalse($this->connection->isTransactionActive());
    }

    /**
     * A failure of each statement of save() in turn (the delete of every row, each insert, the
     * commit): thrown, the transaction rolled back, nothing of the save stored, the connection
     * usable afterwards.
     */
    public function testAFailingStatementIsThrownAndRolledBack(): void
    {
        $this->store->save('config', ['_id' => 'kept', 'a' => 1, 'b' => 2, 'c' => 3, 'created' => 1_700_000_000]);
        $before = $this->rows();
        $document = ['_id' => 'kept', 'd' => 4, 'b' => 20, 'e' => 5, 'created' => 1_700_000_100];
        $inserts = count($document) + 3; // and the defaults: siteid, uuid, owner

        $cases = ['the delete of the rows' => ['/^delete from .idno_settings.$/', 1], 'the start of the transaction' => ['/^BEGIN$/', 1], 'the commit' => ['/^COMMIT$/', 1]];
        for ($nth = 1; $nth <= $inserts; ++$nth) {
            $cases['insert ' . $nth . ' of ' . $inserts] = ['/^insert into .idno_settings./', $nth];
        }

        foreach ($cases as $case => [$pattern, $nth]) {
            $this->failure->failOn($pattern, $nth);

            $thrown = self::thrown(fn (): mixed => $this->store->save('config', $document));

            $this->assertInstanceOf(DriverException::class, $thrown, $case);
            $this->assertStringEndsWith(FailingStatementMiddleware::MESSAGE, $thrown->getMessage(), $case);
            $this->assertCount(1, $this->failure->failed, $case);
            $this->failure->failed = [];
            $this->assertFalse($this->connection->isTransactionActive(), $case);
            $this->assertSame($before, $this->rows(), $case . ': nothing of the save is stored');
            $this->assertSame(1, $this->store->count('config', Criteria::all()), $case);
        }

        // The connection is usable: the same save now succeeds.
        $this->assertSame('kept', $this->store->save('config', $document));
        $this->assertSame(20, $this->store->findLatest('config')['b'] ?? null);
    }

    // ----------------------------------------------------------------------------- two writers

    /**
     * The table holds exactly this document: all its keys in its order, nothing of any other, and
     * the positions 0..n-1 without gaps or duplicates.
     *
     * @param array<string, mixed> $record
     */
    private function assertTableHolds(array $record, string $message): void
    {
        $expected = $this->jsonStoreReads($record);
        $rows = $this->connection->fetchAllAssociative('select name, position from idno_settings order by position, name');

        $this->assertSame(array_map('strval', array_keys($expected)), array_column($rows, 'name'), $message . ': the keys');
        $this->assertSame(range(0, count($expected) - 1), array_map('intval', array_column($rows, 'position')), $message . ': the positions');
        $this->assertSame($expected, $this->store->findLatest('config'), $message . ': the document');
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>}
     */
    private static function twoDocuments(): array
    {
        return [
            ['_id' => 'one', 'a' => 1, 'b' => 2, 'c' => 3, 'created' => 1_700_000_000],
            ['_id' => 'one', 'a' => 10, 'b' => 20, 'c' => 30, 'x' => 'only the first writer', 'created' => 1_700_000_100],
            ['_id' => 'one', 'a' => 100, 'b' => 200, 'c' => 300, 'k' => 'only the second writer', 'created' => 1_700_000_200],
        ];
    }

    /**
     * Writer B saves while writer A is in the middle of its save (A has run its first statement):
     * A holds the write lock, so B waits for it, gives up and returns false (a lock wait is refused
     * data); the table holds A's document and nothing of B's. B then saves again, after A has
     * committed, and the table holds B's document and nothing of A's.
     */
    public function testASaveWhileAnotherSaveIsInProgressIsRefusedAndTheDocumentsNeverMix(): void
    {
        [$base, $first, $second] = self::twoDocuments();
        $this->store->save('config', $base);
        $other = $this->openSecond();
        try {
            $storeB = $this->storeOn($other);
            $duringA = 'not run';
            $this->failure->hookOn('/^insert into .idno_settings./', static function () use ($storeB, $second, &$duringA): void {
                $duringA = $storeB->save('config', $second);
            });

            $this->assertSame('one', $this->store->save('config', $first));

            $this->assertFalse($duringA, 'the second writer could not get the lock');
            $this->assertTableHolds($first, 'after A');

            $this->assertSame('one', $storeB->save('config', $second), 'B retries once A is done');
            $this->assertTableHolds($second, 'after B');
        } finally {
            $other->close();
        }
    }

    /**
     * Writer B saves completely before writer A's first statement, after A began: A's save then
     * replaces B's document, and the table holds A's document and nothing of B's.
     */
    public function testASaveThatCommitsBeforeAnotherSavesFirstStatementIsReplacedWhole(): void
    {
        [$base, $first, $second] = self::twoDocuments();
        $this->store->save('config', $base);
        $other = $this->openSecond();
        try {
            $storeB = $this->storeOn($other);
            $duringA = 'not run';
            $this->failure->hookOn('/^(select|delete from) .*idno_settings/', static function () use ($storeB, $second, &$duringA): void {
                $duringA = $storeB->save('config', $second);
            });

            $this->assertSame('one', $this->store->save('config', $first));

            $this->assertSame('one', $duringA);
            $this->assertTableHolds($first, 'A replaced B\'s document');
        } finally {
            $other->close();
        }
    }

    /**
     * delete() removes the rows only while the document's _id is still the id it was given: a
     * document that replaced the first one between the check and the delete is not lost.
     */
    public function testDeleteDoesNotRemoveADocumentThatReplacedTheOneItChecked(): void
    {
        [$base, , $second] = self::twoDocuments();
        $second['_id'] = 'new';
        $this->store->save('config', $base);
        $other = $this->openSecond();
        try {
            $storeB = $this->storeOn($other);
            $duringA = 'not run';
            $this->failure->hookOn('/^delete from .idno_settings./', static function () use ($storeB, $second, &$duringA): void {
                $duringA = $storeB->save('config', $second);
            });

            $this->assertTrue($this->store->delete('config', 'one'), 'no such record: true, as for an unknown id');

            $this->assertSame('new', $duringA);
            $this->assertTableHolds($second, 'the new document');
        } finally {
            $other->close();
        }
    }

    public function testDeleteComparesTheIdAsTheDocumentHoldsIt(): void
    {
        $this->store->save('config', ['_id' => 42, 'a' => 1, 'created' => 1_700_000_000]);

        $this->assertTrue($this->store->delete('config', '43'));
        $this->assertSame(42, $this->store->findLatest('config')['_id'] ?? null);

        $this->assertTrue($this->store->delete('config', '42'));
        $this->assertSame([], $this->rows());
    }

    /**
     * A stored document the in-memory evaluator cannot hold (a value that was JSON but cannot be
     * encoded again: 1e999 decodes to INF) is thrown by find() and count(), never taken for an
     * empty collection; the plain finders return it.
     */
    public function testADocumentTheEvaluatorCannotHoldIsThrown(): void
    {
        $this->store->save('config', ['_id' => 'x', 'created' => 1_700_000_000]);
        $this->connection->executeStatement('insert into idno_settings (name, value, position) values (?, ?, ?)', ['huge', '1e999', '99']);

        $this->assertIsInfiniteFloat($this->store->findLatest('config')['huge'] ?? null);
        $this->assertIsInfiniteFloat($this->store->findById('config', 'x')['huge'] ?? null);
        $this->assertInstanceOf(\UnexpectedValueException::class, self::thrown(fn (): mixed => $this->store->count('config', Criteria::all())));
        $this->assertInstanceOf(\UnexpectedValueException::class, self::thrown(fn (): mixed => $this->store->find('config', Criteria::all(), 10, 0)));
    }

    private function assertIsInfiniteFloat(mixed $value): void
    {
        $this->assertTrue(is_float($value) && is_infinite($value));
    }

    /**
     * A missing `idno_settings` is a broken database, thrown from every method; it is not an
     * unknown collection. The other collections are unaffected.
     */
    public function testAMissingSettingsTableIsThrown(): void
    {
        $this->store->save('config', ['_id' => 'kept', 'created' => 1_700_000_000]);
        $store = $this->newStore();
        $this->connection->executeStatement('alter table idno_settings rename to idno_settings_away');
        try {
            foreach ([
                'save' => static fn (SettingsRecordStore $store): mixed => $store->save('config', ['_id' => 'x']),
                'findLatest' => static fn (SettingsRecordStore $store): mixed => $store->findLatest('config'),
                'findById' => static fn (SettingsRecordStore $store): mixed => $store->findById('config', 'kept'),
                'findByUuid' => static fn (SettingsRecordStore $store): mixed => $store->findByUuid('config', 'u'),
                'find' => static fn (SettingsRecordStore $store): mixed => $store->find('config', Criteria::all(), 10, 0),
                'count' => static fn (SettingsRecordStore $store): mixed => $store->count('config', Criteria::all()),
                'delete' => static fn (SettingsRecordStore $store): mixed => $store->delete('config', 'kept'),
                'deleteAll' => static fn (SettingsRecordStore $store): mixed => $store->deleteAll('config'),
            ] as $method => $call) {
                $this->assertInstanceOf(\Throwable::class, self::thrown(static fn (): mixed => $call($store)), $method);
                $this->assertFalse($this->connection->isTransactionActive(), $method);
            }

            $this->assertSame('entity', $store->save('entities', ['_id' => 'entity', 'created' => 1_700_000_000]), 'other collections are not affected');
        } finally {
            $this->connection->executeStatement('alter table idno_settings_away rename to idno_settings');
        }

        $this->assertSame('kept', $this->store->findLatest('config')['_id'] ?? null, 'the settings are intact');
    }

    protected static function thrown(\Closure $call): ?\Throwable
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }
}
