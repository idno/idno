<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Idno\Storage\Criteria;
use Idno\Storage\Criterion\AllOf;
use Idno\Storage\Criterion\AnyOf;
use Idno\Storage\Criterion\Equals;
use Idno\Storage\Criterion\In;
use Idno\Storage\Criterion\NotIn;
use Idno\Storage\Criterion\Search;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\IdentityTypeMap;
use Idno\Storage\JsonRecordStore;
use Idno\Storage\Platform\MySqlDialect;
use Idno\Storage\RecordStore;
use Idno\Storage\SchemaCatalog;
use Tests\Storage\Contract\RecordStoreContract;

/**
 * The record storage contract against JsonRecordStore on MySQL or MariaDB: a private database on
 * the rig's server (PrivateDatabase::mysql('contract'), made once for the class and dropped after
 * it), never the site's tables. Skipped when the site's database is not MySQL or MariaDB.
 *
 * The search settings are the site's (`boolean_search`, `bypass_fulltext_search`), as on the
 * site. Text compares with utf8mb4_unicode_520_ci, whose behaviour the hooks' defaults describe.
 * The hooks it overrides, and why (the same on MySQL and MariaDB but one):
 *  - ownsPrivateDatabase (true);
 *  - expectCreatedToBeComparedInTheTimeZoneOfItsSave (false): the store keeps `created` as a
 *    timestamp;
 *  - expectFloatsInExponentFormToMatchTheirJsonText: false on MySQL, whose JSON functions parse a
 *    number and print it their own way (1.0e+25 is `9.999999999999999e24`, 1.0e-5 `0.00001`); true
 *    on MariaDB, which keeps the number's text;
 *  - collections: entities, reader and site, the collections of the schema.
 */
final class JsonMySqlRecordStoreTest extends RecordStoreContract
{
    private static ?Connection $connection = null;

    private ?JsonRecordStore $store = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$connection = PrivateDatabase::mysql('contract');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$connection !== null) {
            PrivateDatabase::drop(self::$connection);
            self::$connection = null;
        }
        parent::tearDownAfterClass();
    }

    protected function store(): RecordStore
    {
        $connection = self::$connection;
        self::assertNotNull($connection);

        return $this->store ??= new JsonRecordStore(
            $connection,
            new MySqlDialect(),
            new FixedRecordDefaults('http://contract.test/', $this->currentSiteId(), fn (): string => $this->newId()),
            new IdentityTypeMap(),
            new SchemaCatalog($connection),
            \Idno\Core\Idno::site()->language()->uncurlQuotes(...),
            (bool) \Idno\Core\Idno::site()->config()->boolean_search,
            (bool) \Idno\Core\Idno::site()->config()->bypass_fulltext_search,
        );
    }

    private static function isMariaDb(): bool
    {
        return self::$connection?->getDatabasePlatform() instanceof MariaDBPlatform;
    }

    protected function ownsPrivateDatabase(): bool
    {
        return true;
    }

    protected function expectCreatedToBeComparedInTheTimeZoneOfItsSave(): bool
    {
        return false;
    }

    protected function expectFloatsInExponentFormToMatchTheirJsonText(): bool
    {
        return self::isMariaDb();
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
        $record = $this->r(['title' => "Ünïcode/slash \u{1F600}", 'f' => 0.1 + 0.2]);
        $this->put($record);

        $row = self::$connection?->fetchAssociative('select * from idno_entities where id = ?', [$record['_id']]);
        $this->assertIsArray($row);
        $this->assertSame(json_encode($record), $row['data'], 'the document is json_encode()d exactly as today');
        $this->assertSame((string) $record['created'], (string) $row['created']);
        $this->assertSame($record['entity_subtype'], $row['type']);
        $this->assertSame("Ünïcode/slash \u{1F600} ", $row['search']);
    }

    public function testAwkwardButValidDocumentsAreNeverRefused(): void
    {
        // isRefusedData() counts the JSON functions' refusals as refused data: a document the store
        // builds must never meet them, or a malformed document would hide behind `false`.
        $nested = 'bottom';
        for ($i = 0; $i < 29; ++$i) {
            $nested = [$nested];
        }
        $documents = [
            'nested as deep as both servers accept' => ['deep' => $nested, 'access' => ['a' => ['b' => ['c' => 'PUBLIC']]]],
            'a large string' => ['body' => str_repeat("Large \"text\" \\ with escapes\n", 40_000), 'title' => str_repeat('é', 10_000)],
            'every scalar' => ['i' => PHP_INT_MAX, 'n' => PHP_INT_MIN, 'f' => 1.5e300, 'tiny' => 5.0e-324, 't' => true, 'false' => false, 'null' => null, 'empty' => '', 'list' => [], 'zero' => '0'],
            'unusual keys' => ['' => 'empty key', ' ' => 'space', 'a.b' => 'dot', '$' => 'dollar', '$.x' => 'path', 'with "quote"' => 'q', "nul\u{0}" => 'nul', '日本' => 'cjk', '😀' => 'emoji', '0' => 'zero', 'access' => 'PUBLIC'],
            'awkward strings in indexed fields' => ['access' => "\u{1F600}\u{0}", 'slug' => str_repeat('s', 70_000), 'url' => '"quoted"', 'canonical' => '\\', 'shorturl' => "\t\n"],
        ];
        foreach ($documents as $case => $fields) {
            $record = $this->r($fields);
            $this->assertSame($record['_id'], $this->store()->save('entities', $record), $case);
            $this->assertSameRecord($record, $this->store()->findById('entities', $record['_id']), $case);
        }
    }

    /**
     * A document nested deeper than the server's JSON functions accept (counted in nested objects and
     * arrays, the document itself the first: MySQL 8 accepts 100, MariaDB 10.6 accepts 31, found by
     * observation) is refused by save(): false, nothing stored, under the connection's own sql_mode
     * and with sql_mode '' (a server that is not strict), whether or not the collection's table has
     * generated columns that would refuse it (this one has none). A stored document too deep for
     * JSON_TABLE would make every query on a document field of its collection fail; a query on a
     * document field still works afterwards. The deepest document the server accepts is stored and
     * found.
     */
    public function testADocumentTooDeepForTheServerIsRefusedAndQueriesStillWork(): void
    {
        $connection = self::$connection;
        self::assertNotNull($connection);
        $limit = self::isMariaDb() ? 31 : 100;
        self::assertSame($limit, (new MySqlDialect())->jsonDepthLimit($connection));
        // A document of $levels nested objects (itself the first).
        $document = function (int $levels, string $title): array {
            $value = 'bottom';
            for ($i = 2; $i < $levels; ++$i) {
                $value = ['n' => $value];
            }

            return ['_id' => $this->newId(), 'title' => $title, 'entity_subtype' => 'Deep', 'deep' => $levels > 1 ? ['n' => $value] : $value];
        };
        $mode = (string) $connection->fetchOne('select @@session.sql_mode');
        $store = $this->store();
        try {
            foreach ([$mode, ''] as $sqlMode) {
                $connection->executeStatement('set session sql_mode = ?', [$sqlMode]);
                foreach ([$limit + 1, $limit + 20] as $levels) {
                    $tooDeep = $document($levels, 'too deep');

                    self::assertFalse($store->save('entities', $tooDeep), 'sql_mode "' . $sqlMode . '", ' . $levels . ' levels: refused');
                    self::assertSame(0, (int) $connection->fetchOne('select count(*) from idno_entities where id = ?', [$tooDeep['_id']]), 'nothing stored');
                }
                self::assertSame([], $store->find('entities', Criteria::fromLegacyArray(['entity_subtype' => 'Deep', 'title' => 'too deep']), 10, 0), 'a document-field query works');
                $deepest = $document($limit, 'deepest');
                self::assertSame($deepest['_id'], $store->save('entities', $deepest), 'sql_mode "' . $sqlMode . '": ' . $limit . ' levels are accepted');
                self::assertSame($deepest['deep'], $store->findById('entities', $deepest['_id'])['deep'] ?? null);
                self::assertSame([$deepest['_id']], array_column($store->find('entities', Criteria::fromLegacyArray(['entity_subtype' => 'Deep', 'title' => 'deepest']), 10, 0), '_id'), 'found by a document field');
                $store->delete('entities', $deepest['_id']);
            }
        } finally {
            $connection->executeStatement('set session sql_mode = ?', [$mode]);
        }
    }

    public function testSavingTheSameRecordAgainReturnsItsId(): void
    {
        // The upsert changes no row (MySQL reports 0 affected rows), and the record is there.
        $record = $this->put($this->r(['title' => 'same']));

        $this->assertSame($record['_id'], $this->store()->save('entities', $record));
        $this->assertSameRecord($record, $this->store()->findById('entities', $record['_id']));
    }

    public function testANewRecordWithAnotherRecordsUuidChangesNoRow(): void
    {
        // MySQL's `on duplicate key update` finds the other record's row by its uuid: the guard
        // leaves it as it is, and the save is refused.
        $original = $this->put($this->r(['title' => 'original']));
        $clash = $this->r(['title' => 'clash', 'uuid' => $original['uuid'], 'owner' => 'someone else', 'created' => 1_800_000_000]);
        $this->track($clash['_id']);
        $before = self::$connection?->fetchAssociative('select * from idno_entities where id = ?', [$original['_id']]);

        $this->assertFalse($this->store()->save('entities', $clash));

        $this->assertSame($before, self::$connection?->fetchAssociative('select * from idno_entities where id = ?', [$original['_id']]));
        $this->assertFalse(self::$connection?->fetchOne('select 1 from idno_entities where id = ?', [$clash['_id']]));
    }

    public function testATableOfAnotherCollectionMadeLaterIsFoundAfterTheCatalogIsRefreshed(): void
    {
        $connection = self::$connection;
        self::assertNotNull($connection);
        $catalog = new SchemaCatalog($connection);
        $store = new JsonRecordStore($connection, new MySqlDialect(), new FixedRecordDefaults(), new IdentityTypeMap(), $catalog, static fn (string $text): string => $text, true, false);
        $this->assertFalse($store->save('later', ['_id' => 'l1']));

        $connection->executeStatement('create table idno_later like idno_entities');
        try {
            $this->assertFalse($store->save('later', ['_id' => 'l1']), 'the catalog is read once');
            $catalog->forget();
            $this->assertSame('l1', $store->save('later', ['_id' => 'l1', 'created' => 1_700_000_000]));
            $this->assertSame(1, $store->count('later', Criteria::all()));
        } finally {
            $connection->executeStatement('drop table idno_later');
        }
    }

    // ------------------------------------------------ the SQL the store runs (moved from the old store's MySQL binding)

    public function testAnEmptyAnyOfIsNotSentAsInvalidSql(): void
    {
        $record = $this->put($this->r(['slug' => 'x']));
        $marker = new Equals('contract_marker', $this->marker);

        $find = fn (Criteria $c): array => array_map(static fn (array $r): string => $r['_id'], $this->store()->find('entities', $c, 10, 0));
        $this->assertSame([], $find(new Criteria(new AllOf([$marker, new AnyOf([])]))));
        $this->assertSame([$record['_id']], $find(new Criteria(new AllOf([$marker, new AnyOf([new AnyOf([]), new Equals('slug', 'x')])]))));
        $this->assertSame([$record['_id']], $find(new Criteria(new AllOf([$marker, new AllOf([])]))));
        $this->assertSame([], $find(new Criteria(new AllOf([$marker, new In('slug', [])]))));
        $this->assertSame([$record['_id']], $find(new Criteria(new AllOf([$marker, new NotIn('slug', [])]))));
        $this->assertSame([], $find(new Criteria(new AllOf([$marker, new NotIn('missing', [])]))));
        $this->assertSame([$record['_id']], $find(new Criteria(new AllOf([$marker, new AnyOf([new AllOf([new Equals('slug', 'x')]), new Equals('slug', 'y')])]))));
    }

    public function testASearchBelowTheTopLevelMatchesNothingWithoutQuerying(): void
    {
        $record = $this->put($this->r(['title' => 'xxabcxx', 'slug' => 'x']));
        $marker = new Equals('contract_marker', $this->marker);

        $this->assertCount(1, $this->store()->find('entities', new Criteria(new AllOf([$marker, new Search('abc')])), 10, 0));
        foreach ([new AnyOf([new Search('abc')]), new AllOf([new Search('abc')]), new AnyOf([new AllOf([new Search('abc')]), new Equals('slug', 'x')])] as $nested) {
            $criteria = new Criteria(new AllOf([$marker, $nested]));
            $this->assertSame([], $this->store()->find('entities', $criteria, 10, 0));
            $this->assertSame(0, $this->store()->count('entities', $criteria));
        }
        $this->assertNotNull($this->store()->findById('entities', $record['_id']));
    }

    public function testNegativePagingFindsNothing(): void
    {
        $this->put($this->r(['slug' => 'x']));

        $this->assertSame([], $this->store()->find('entities', Criteria::all(), -1, 0));
        $this->assertSame([], $this->store()->find('entities', Criteria::all(), 10, -1));
    }

    /**
     * The tables that are no collections (the settings, the sessions, the migrations' bookkeeping)
     * exist, but no store method touches them.
     */
    public function testATableThatIsNotACollectionIsRejected(): void
    {
        foreach (JsonRecordStore::SYSTEM_TABLES as $name) {
            $this->assertFalse($this->store()->save($name, $this->r()), $name);
            $this->assertSame([], $this->store()->find($name, Criteria::all(), 10, 0), $name);
            $this->assertSame(0, $this->store()->count($name, Criteria::all()), $name);
            $this->assertNull($this->store()->findLatest($name), $name);
            $this->assertFalse($this->store()->delete($name, 'x'), $name);
            $this->assertFalse($this->store()->deleteAll($name), $name);
        }
    }

    /**
     * A collection name is reduced to lower case, so it is found whatever its case, on a server
     * whose table names are case-sensitive too (the rig's MySQL: lower_case_table_names = 0). The
     * old store looked the name up as given there: `ENTITIES` was no collection on MySQL.
     */
    public function testACollectionNameIsMatchedWithoutRegardToCase(): void
    {
        $record = $this->put($this->r(['slug' => 'case']));

        $this->assertSame($record['_id'], $this->store()->findById('ENTITIES', $record['_id'])['_id'] ?? null);
        $this->assertSame(1, $this->store()->count('Entities', Criteria::fromLegacyArray(['contract_marker' => $this->marker, 'slug' => 'case'])));
    }
}
