<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Idno\Storage\Criteria;
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
 * The search settings are the site's (`boolean_search`, `bypass_fulltext_search`), as for the
 * DbalRecordStore binding. The hooks it overrides, and why (the same on MySQL and MariaDB but one):
 *  - ownsPrivateDatabase (true);
 *  - expectTextToSortFoldedToUpperCase (true): text compares with utf8mb4_general_ci;
 *  - expectEqualCreatedToBeOrderedById (true), expectValuesToBeCutAt255Bytes (false),
 *    expectNestedArraysToMatchTheirJson (false), expectFieldNamesToIgnoreCase (false),
 *    expectCreatedToBeComparedInTheTimeZoneOfItsSave (false), expectFloatsToMatchTheirJsonText
 *    (true): the store reads each field from the stored document, orders by `created` then id, and
 *    keeps `created` as a timestamp;
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

    protected function expectTextToSortFoldedToUpperCase(): bool
    {
        return true;
    }

    protected function expectEqualCreatedToBeOrderedById(): bool
    {
        return true;
    }

    protected function expectValuesToBeCutAt255Bytes(): bool
    {
        return false;
    }

    protected function expectNestedArraysToMatchTheirJson(): bool
    {
        return false;
    }

    protected function expectFieldNamesToIgnoreCase(): bool
    {
        return false;
    }

    protected function expectCreatedToBeComparedInTheTimeZoneOfItsSave(): bool
    {
        return false;
    }

    protected function expectFloatsToMatchTheirJsonText(): bool
    {
        return true;
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
}
