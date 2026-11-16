<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Doctrine\DBAL\Connection;
use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Legacy\LegacyAccessContext;
use Idno\Legacy\LegacyRecordDefaults;
use Idno\Legacy\LegacySchemaUpgrader;
use Idno\Legacy\RepositoryDataConcierge;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\Criteria;
use Idno\Storage\DbalRecordStore;
use Idno\Storage\EntityQuery;
use Idno\Storage\EntityRepository;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\RecordStore;
use IdnoPlugins\Status\Status;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Tests\IdnoTestCase;
use Tests\Unit\Storage\SqliteTestDatabase;

/**
 * The legacy db() service on an SQL site is Idno\Legacy\RepositoryDataConcierge: the old backend's
 * API (Idno\Data\MySQL / AbstractSQL) served by the storage layer, on the shared DBAL connection.
 * Rig tests on the rig's database (MySQL or SQLite; the tests that need MySQL skip on SQLite and
 * say why), plus the adapter built over an SQLite database of its own.
 */
final class RepositoryDataConciergeTest extends IdnoTestCase
{
    /**
     * Every public method of Idno\Data\MySQL (with those it inherits from AbstractSQL, DataConcierge
     * and Component), captured from the class on the commit before Task 7, with its parameters
     * (name => default, or no default when required) and whether it is static.
     */
    private const OLD_BACKEND_METHODS = [
        'build_where_from_array' => [['params', 'variables', 'metadata_joins', 'non_md_variables', 'clause' => 'and', 'collection' => 'entities'], false],
        'camelCase' => [['string'], false],
        'checkAndUpgradeSchema' => [[], false],
        'countObjects' => [['subtypes' => '', 'search' => [], 'collection' => 'entities'], false],
        'countRecords' => [['parameters', 'collection' => 'entities'], false],
        'createSearchArray' => [['query'], false],
        'deleteAllRecords' => [['collection'], false],
        'deleteRecord' => [['id', 'collection' => 'entities'], false],
        'exportRecords' => [['collection' => 'entities', 'limit' => 10, 'offset' => 0], false],
        'formatDate' => [['timestamp'], false],
        'generateID' => [[], false],
        'getAnyObject' => [['collection' => 'entities'], false],
        'getAnyRecord' => [['collection' => 'entities'], false],
        'getClass' => [[], false],
        'getClassName' => [[], false],
        'getClassSelector' => [[], false],
        'getClient' => [[], false],
        'getDatabase' => [[], false],
        'getErrors' => [[], false],
        'getFilename' => [[], false],
        'getFilesystem' => [[], false],
        'getFullClassName' => [['convert_slashes' => false], false],
        'getIDSelector' => [[], false],
        'getIgnoreAccess' => [[], false],
        'getNamespace' => [[], false],
        'getObject' => [['uuid'], false],
        'getObjects' => [['subtypes' => '', 'search' => [], 'fields' => [], 'limit' => 10, 'offset' => 0, 'collection' => 'entities', 'readGroups' => []], false],
        'getRecord' => [['id', 'collection' => 'entities'], false],
        'getRecordByUUID' => [['uuid', 'collection' => 'entities'], false],
        'getRecords' => [['fields', 'parameters', 'limit', 'offset', 'collection' => 'entities'], false],
        'getSchemaFields' => [[], false],
        'getVersions' => [[], false],
        'handleSession' => [[], false],
        'init' => [[], false],
        'normaliseArray' => [['variable'], true],
        'optimize' => [[], false],
        'processID' => [['id'], false],
        'reconnectIfNeeded' => [[], false],
        'registerEventHooks' => [[], false],
        'registerLibraries' => [[], false],
        'registerPages' => [[], false],
        'registerTranslations' => [[], false],
        'rowToEntity' => [['row'], false],
        'saveObject' => [['object'], false],
        'saveRecord' => [['collection', 'array'], false],
        'setIgnoreAccess' => [['value' => true], false],
    ];

    /**
     * Methods of the old backend the adapter does not have, and why. build_where_from_array() is the
     * old backend's SQL compiler (Criteria::fromLegacyArray() and DbalRecordStore replace it); nothing
     * outside Idno\Data\ calls it. The constructor differs on purpose (the adapter is built by
     * LegacyServiceFactory::db()).
     */
    private const NOT_SERVED = ['build_where_from_array'];

    private string|false $previousErrorLog = false;

    private ?string $logFile = null;

    /** @var list<string> uuids of entities to delete */
    private array $created = [];

    /** @var list<string> files to remove */
    private array $files = [];

    /** @var (\Closure(): list<string>)|null the temporary files exportRecords() made during the test */
    private ?\Closure $exportTempFiles = null;

    protected function setUp(): void
    {
        parent::setUp();
        $config = Idno::container()->get(\Idno\Config\BootConfig::class)->all();
        if (!in_array(ConnectionParameters::driverFamily($config), ['mysql', 'sqlite'], true)) {
            $this->markTestSkipped('The site\'s database is not SQL (MySQL or SQLite): db() is not the adapter.');
        }
    }

    /**
     * Skips a test that needs the site's database to be MySQL (the rig's MySQL mode or CI's MySQL
     * job), saying why.
     */
    private function requireMysql(string $why): void
    {
        $config = Idno::container()->get(\Idno\Config\BootConfig::class)->all();
        if (ConnectionParameters::driverFamily($config) !== 'mysql') {
            $this->markTestSkipped('Needs a MySQL site: ' . $why);
        }
    }

    protected function tearDown(): void
    {
        $db = Idno::site()->db();
        $ignore = $db->setIgnoreAccess(true);
        try {
            foreach ($this->created as $uuid) {
                if ($record = $db->getRecordByUUID($uuid)) {
                    $db->deleteRecord($record['_id']);
                }
            }
        } finally {
            $db->setIgnoreAccess($ignore);
        }
        $this->created = [];
        if ($this->previousErrorLog !== false) {
            ini_set('error_log', $this->previousErrorLog);
            $this->previousErrorLog = false;
        }
        if ($this->exportTempFiles !== null) {
            $this->files = [...$this->files, ...($this->exportTempFiles)()];
            $this->exportTempFiles = null;
        }
        foreach ([...$this->files, $this->logFile] as $file) {
            if ($file !== null && file_exists($file)) {
                unlink($file);
            }
        }
        $this->files = [];
        $this->logFile = null;
        parent::tearDown();
    }

    // ------------------------------------------------ the service

    public function testDbServiceIsTheAdapterOnSqlSites(): void
    {
        $this->assertInstanceOf(RepositoryDataConcierge::class, Idno::site()->db());
        $this->assertSame(Idno::site()->db(), Idno::container()->get(\Idno\Core\DataConcierge::class));
        $this->assertInstanceOf(\Idno\Common\SessionStorageInterface::class, Idno::site()->db());
    }

    public function testEveryPublicMethodOfTheOldBackendExists(): void
    {
        $adapter = new \ReflectionClass(RepositoryDataConcierge::class);
        foreach (self::OLD_BACKEND_METHODS as $name => [$parameters, $static]) {
            if (in_array($name, self::NOT_SERVED, true)) {
                $this->assertFalse($adapter->hasMethod($name), $name . ' is listed as not served');
                continue;
            }
            $this->assertTrue($adapter->hasMethod($name), $name);
            $method = $adapter->getMethod($name);
            $this->assertTrue($method->isPublic(), $name . ' is public');
            $this->assertSame($static, $method->isStatic(), $name . ' static');

            $actual = [];
            foreach ($method->getParameters() as $parameter) {
                $this->assertFalse($parameter->hasType(), $name . '(): $' . $parameter->getName() . ' is untyped, as in the old backend');
                if ($parameter->isDefaultValueAvailable()) {
                    $actual[$parameter->getName()] = $parameter->getDefaultValue();
                } else {
                    $actual[] = $parameter->getName();
                }
            }
            $this->assertSame($parameters, $actual, $name . '(): same parameters, names and defaults');
        }
    }

    public function testTheListOfOldMethodsIsComplete(): void
    {
        if (!class_exists(\Idno\Data\MySQL::class)) {
            $this->markTestSkipped('The old backend has been deleted; the literal list above is what it had.');
        }
        $names = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(\Idno\Data\MySQL::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        $names = array_values(array_diff($names, ['__construct']));
        sort($names);
        $this->assertSame(array_keys(self::OLD_BACKEND_METHODS), $names);
    }

    public function testGetClientReturnsNativePdo(): void
    {
        $client = Idno::site()->db()->getClient();

        $this->assertInstanceOf(\PDO::class, $client);
        /** @var Connection $connection */
        $connection = Idno::container()->get('database_connection');
        $this->assertSame($connection->getNativeConnection(), $client, 'the shared DBAL connection\'s PDO: one connection');
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $client->getAttribute(\PDO::ATTR_ERRMODE));
        $this->assertSame(Idno::site()->config()->dbname, Idno::site()->db()->getDatabase());
    }

    /**
     * The shared connection has the session the legacy backend's own connection had (since db() is
     * the adapter, DbalMySqlRecordStoreTest::testTheSessionMatchesTheLegacyConnection compares the
     * shared connection with itself; this compares it with a legacy backend built for the test).
     */
    public function testTheConnectionMatchesTheLegacyBackendsConnection(): void
    {
        $this->requireMysql('it compares the shared connection\'s MySQL session with a connection of the old backend (Idno\\Data\\MySQL).');
        $query = 'SELECT @@session.sql_mode, @@session.time_zone, @@character_set_client, @@collation_connection, @@character_set_connection, @@character_set_results, @@session.autocommit';
        $legacy = (new \Idno\Data\MySQL())->getClient();
        $this->assertNotSame($legacy, Idno::site()->db()->getClient());

        $this->assertSame($legacy->query($query)->fetch(\PDO::FETCH_NUM), Idno::site()->db()->getClient()->query($query)->fetch(\PDO::FETCH_NUM));
        foreach ([\PDO::ATTR_ERRMODE, \PDO::ATTR_EMULATE_PREPARES, \PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::ATTR_CASE, \PDO::ATTR_ORACLE_NULLS, \PDO::ATTR_STRINGIFY_FETCHES] as $attribute) {
            $this->assertSame($legacy->getAttribute($attribute), Idno::site()->db()->getClient()->getAttribute($attribute), 'PDO attribute ' . $attribute);
        }
    }

    public function testTheStoreAndRepositoryAreTheContainerServices(): void
    {
        $db = Idno::site()->db();
        $this->assertSame(Idno::container()->get(RecordStore::class), (fn () => $this->store())->call($db));
        $this->assertSame(Idno::container()->get(EntityRepository::class), (fn () => $this->repository())->call($db));
    }

    /**
     * The order in which Idno::init() built the legacy services is unchanged: captured on the commit
     * before Task 7 (reflection on the container's services, in the order they were instantiated).
     * The DBAL connection is now built inside db() (it does not connect by itself), and the record
     * store and the repository only after the logger, when first used.
     */
    public function testLegacyServicesAreBuiltInTheSameOrder(): void
    {
        $before = [
            \Idno\Core\PageHandler::class,
            \Idno\Core\EventDispatcher::class,
            \Idno\Core\Config::class,
            \Idno\Core\DataConcierge::class,
            \Idno\Files\FileSystem::class,
            \Idno\Core\Logging::class,
            \Idno\Core\Session::class,
            \Idno\Core\Actions::class,
            \Idno\Core\Template::class,
            \Idno\Core\Language::class,
            \Idno\Core\Syndication::class,
            \Idno\Core\Reader::class,
            \Idno\Core\HelperRobot::class,
            \Idno\Core\EventQueue::class,
            \Idno\Stats\StatisticsCollector::class,
            \Idno\Caching\PersistentCache::class,
            \Idno\Core\Plugins::class,
            \Idno\Core\Themes::class,
        ];
        $built = array_keys((new \ReflectionProperty(\Symfony\Component\DependencyInjection\Container::class, 'services'))->getValue(Idno::container()));

        $this->assertSame($before, array_values(array_intersect($built, $before)));
        $position = array_flip($built);
        $this->assertLessThan($position[\Idno\Core\DataConcierge::class], $position['doctrine.dbal.default_connection'] ?? PHP_INT_MAX, 'the connection is built by db()');
        foreach ([RecordStore::class, EntityRepository::class] as $id) {
            if (isset($position[$id])) {
                $this->assertGreaterThan($position[\Idno\Core\Logging::class], $position[$id], $id . ' is built after the logger, at first use');
            }
        }
    }

    // ------------------------------------------------ fix round 1

    /**
     * Ruling P7 (I1a): a save the database refuses returns false and stores nothing. The value is a
     * 4-byte character in a document field: the JSON document escapes it, so the main row and the
     * search row are written, and the rig's utf8mb3 metadata table refuses it.
     * OBSERVED on the old backend: it returns the id (set before the metadata inserts) although it
     * rolled the save back, and nothing is stored.
     */
    public function testARefusedSaveReturnsFalseAndStoresNothing(): void
    {
        $this->requireMysql('the refused value is a 4-byte character, which MySQL\'s utf8mb3 tables refuse and SQLite stores; it also runs the old backend.');
        $db = Idno::site()->db();
        $marker = 't7-' . bin2hex(random_bytes(6));
        $refused = ['t7marker' => $marker, 't7note' => "a seal \u{1F9AD}", 'access' => 'PUBLIC', 'created' => 1_700_000_000];

        $legacy = new \Idno\Data\MySQL();
        $this->captureLog();
        $legacyId = $legacy->saveRecord('entities', $refused);
        $this->assertIsString($legacyId, 'OBSERVED: the old backend returns the id of the save it rolled back');
        $this->assertFalse($legacy->getRecord($legacyId), 'OBSERVED: and stored nothing');

        $this->assertFalse($db->saveRecord('entities', $refused));
        $this->assertSame(0, $db->countRecords(['t7marker' => $marker]));

        // An update the database refuses leaves the previous version intact.
        $id = $db->saveRecord('entities', ['t7marker' => $marker, 't7note' => 'first version', 'access' => 'PUBLIC', 'created' => 1_700_000_000]);
        $this->assertIsString($id);
        $this->created[] = $db->getRecord($id)['uuid'];
        $this->assertFalse($db->saveRecord('entities', ['_id' => $id] + $refused));
        $this->assertSame('first version', $db->getRecord($id)['t7note']);
        $this->assertSame(1, $db->countRecords(['t7marker' => $marker]));

        $status = new Status();
        $status->setOwner($this->user());
        $status->body = 'Refused ' . $marker;
        $status->t7note = "a seal \u{1F9AD}";
        $status->setAccess('PUBLIC');
        $this->assertFalse($db->saveObject($status));
    }

    /**
     * Ruling P7 (I1a), through Entity::save(): a refused save returns false and fires neither
     * `saved` (a new entity) nor `updated` (an existing one); the old backend reported success and
     * fired them for a record it had not stored.
     */
    public function testEntitySaveOfARefusedRecordReturnsFalseAndFiresNoEvent(): void
    {
        $this->requireMysql('the refused value is a 4-byte character, which MySQL\'s utf8mb3 tables refuse and SQLite stores.');
        $marker = 't7-' . bin2hex(random_bytes(6));
        $fired = [];
        $listener = static function (\Idno\Core\Event $event) use (&$fired, $marker): void {
            $object = $event->data()['object'] ?? null;
            if ($object instanceof Entity && $object->t7marker === $marker) {
                $fired[] = $object->t7note;
            }
        };
        $dispatcher = (new \ReflectionProperty(\Idno\Core\EventDispatcher::class, 'dispatcher'))->getValue(Idno::site()->events());
        $dispatcher->addListener('saved', $listener);
        $dispatcher->addListener('updated', $listener);
        try {
            $new = new Status();
            $new->setOwner($this->user());
            $new->body = 'New refused ' . $marker;
            $new->t7marker = $marker;
            $new->t7note = "new \u{1F9AD}";
            $new->setAccess('PUBLIC');
            $this->assertFalse($new->save(true));

            $existing = $this->status('Existing ' . $marker, $marker, 1_700_000_000);
            $this->assertSame([null], $fired, 'the control: saving a storable entity fires saved');
            $fired = [];
            $existing->t7note = "updated \u{1F9AD}";
            $this->assertFalse($existing->save(true));
            $this->assertSame([], $fired, 'no saved or updated event for a refused save');
            $this->assertSame(1, Idno::site()->db()->countRecords(['t7marker' => $marker]));
            $this->assertArrayNotHasKey('t7note', Idno::site()->db()->getRecordByUUID($existing->getUUID()));
        } finally {
            $dispatcher->removeListener('saved', $listener);
            $dispatcher->removeListener('updated', $listener);
        }
    }

    /**
     * Ruling P7 (I1a), through Entity::save(), with a refusal both databases make: a NEW entity whose
     * uuid is another record's (the uuid is unique) returns false and fires no `saved` event, and the
     * other record is left as it was. (The 4-byte case above needs MySQL.)
     */
    public function testEntitySaveOfANewRecordWithAnExistingUuidReturnsFalseAndFiresNoEvent(): void
    {
        $marker = 't7-' . bin2hex(random_bytes(6));
        $fired = [];
        $listener = static function (\Idno\Core\Event $event, string $name) use (&$fired, $marker): void {
            $object = $event->data()['object'] ?? null;
            if ($object instanceof Entity && $object->t7marker === $marker) {
                $fired[] = $name;
            }
        };
        $existing = $this->status('Existing ' . $marker, $marker, 1_700_000_000);
        $dispatcher = (new \ReflectionProperty(\Idno\Core\EventDispatcher::class, 'dispatcher'))->getValue(Idno::site()->events());
        $dispatcher->addListener('saved', $listener);
        $dispatcher->addListener('updated', $listener);
        try {
            $clash = new Status();
            $clash->setOwner($this->user());
            $clash->body = 'Clashing ' . $marker;
            $clash->t7marker = $marker;
            $clash->uuid = $existing->getUUID();
            $clash->setAccess('PUBLIC');

            $this->assertFalse($clash->save(true));
            $this->assertSame([], $fired, 'no saved or updated event for a refused save');
            $this->assertSame(1, Idno::site()->db()->countRecords(['t7marker' => $marker]));
            $this->assertSame('Existing ' . $marker, Idno::site()->db()->getRecordByUUID($existing->getUUID())['body']);
        } finally {
            $dispatcher->removeListener('saved', $listener);
            $dispatcher->removeListener('updated', $listener);
        }
    }

    /**
     * I1c: a string $readGroups is one read group. OBSERVED reasoning, not runnable on the rig: the old
     * backend ran `'$in' => 'PUBLIC'` into foreach (a warning, an exception here) and found nothing.
     */
    public function testAStringReadGroupIsHonoured(): void
    {
        $db = Idno::site()->db();
        $marker = 't7-' . bin2hex(random_bytes(6));
        $status = $this->status('Read group ' . $marker, $marker, 1_700_000_000);

        $this->assertSame([$status->getUUID()], $this->uuids($db->getObjects('', ['t7marker' => $marker], [], 10, 0, 'entities', 'PUBLIC')));
        $this->assertSame([], $db->getObjects('', ['t7marker' => $marker], [], 10, 0, 'entities', 'SITE'));
    }

    /**
     * M1: the record store reads `bypass_fulltext_search` and `boolean_search` from the legacy config
     * each time a search runs, as the old backend did. A term of more than 5 bytes uses the full-text
     * index (whole words) unless bypassed, when it is a substring match.
     */
    public function testSearchSettingsAreReadAtQueryTime(): void
    {
        $this->requireMysql('bypass_fulltext_search changes MySQL\'s full-text search only; SQLite always matches substrings (ruling P8).');
        $config = Idno::site()->config();
        $previous = [$config->bypass_fulltext_search, $config->boolean_search];
        $store = (new \Idno\Legacy\LegacyRecordStoreFactory(
            static fn (): Connection => Idno::container()->get('database_connection'),
            Idno::container()->get(\Idno\Config\BootConfig::class),
            new LegacyRecordDefaults(),
        ))->create();
        $marker = 't7-' . bin2hex(random_bytes(6));
        $id = Idno::site()->db()->saveRecord('entities', ['title' => 'Seawalruses ahoy', 't7marker' => $marker, 'access' => 'PUBLIC', 'created' => 1_700_000_000]);
        $this->assertIsString($id);
        $this->created[] = Idno::site()->db()->getRecord($id)['uuid'];
        $search = Criteria::fromLegacyArray(['$search' => ['walruses'], 't7marker' => $marker]);
        try {
            $config->bypass_fulltext_search = false;
            $this->assertSame(0, $store->count('entities', $search), 'full text: no whole word "walruses"');
            $config->bypass_fulltext_search = true;
            $this->assertSame(1, $store->count('entities', $search), 'bypassed: substring match, same store object');

            $config->boolean_search = false;
            $this->assertFalse((new \ReflectionProperty(DbalRecordStore::class, 'booleanSearch'))->getValue($store)());
            $config->boolean_search = true;
            $this->assertTrue((new \ReflectionProperty(DbalRecordStore::class, 'booleanSearch'))->getValue($store)());
        } finally {
            [$config->bypass_fulltext_search, $config->boolean_search] = $previous;
        }
    }

    /**
     * M6: what db() builds for a `database` value that is not a known name, in a fresh process: a
     * loadable backend class is built by componentFactory() as before.
     */
    public function testDbBuildsALoadableBackendClass(): void
    {
        $this->assertSame(['class' => FixtureDataBackend::class, 'family' => 'other'], $this->bootWithDatabase(FixtureDataBackend::class));
    }

    /**
     * M6: a `database` name that is no class gets the adapter on MySQL (componentFactory() fell back
     * to Idno\Data\MySQL).
     */
    public function testDbFallsBackToTheAdapterOnMysqlForANameThatIsNoClass(): void
    {
        $this->requireMysql('a `database` name that is no class means MySQL, so the subprocess connects to the rig\'s MySQL server.');
        $this->assertSame(['class' => RepositoryDataConcierge::class, 'family' => 'mysql'], $this->bootWithDatabase('NoSuchBackend'));
    }

    /**
     * @return array<string, string>
     */
    private function bootWithDatabase(string $database): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=stderr ' . escapeshellarg(__DIR__ . '/boot_db_backend.php');
        [$exit, $stdout, $stderr] = self::runProcess($command, ['IDNO_DOMAIN' => '127.0.0.1', 'IDNO_PORT' => '8089', 'IDNO_DATABASE' => $database, 'IDNO_PROXY_STRING' => '127.0.0.1:9']);
        $lines = self::lines($stdout);
        $decoded = json_decode((string) end($lines), true);
        $this->assertIsArray($decoded, "boot_db_backend.php did not print JSON (exit $exit).\nstdout: $stdout\nstderr: $stderr");

        return $decoded;
    }

    // ------------------------------------------------ entities through the repository

    public function testStaticFindersGoThroughTheRepository(): void
    {
        $repository = Idno::container()->get(EntityRepository::class);
        $marker = 't7-' . bin2hex(random_bytes(6));
        $first = $this->status('First ' . $marker, $marker, 1_700_000_000);
        $second = $this->status('Second ' . $marker, $marker, 1_700_000_060);

        $byUuid = Entity::getByUUID($first->getUUID(), false);
        $found = $repository->find(new EntityQuery([], Criteria::fromLegacyArray(['uuid' => $first->getUUID()]), 1));
        $this->assertInstanceOf(Status::class, $byUuid);
        $this->assertSame([$first->getUUID()], $this->uuids($found));
        $this->assertSame($found[0]->getUUID(), $byUuid->getUUID());

        $this->assertSame(
            $this->uuids($repository->find(new EntityQuery([Status::class], Criteria::fromLegacyArray(['t7marker' => $marker])))),
            $this->uuids(Status::get(['t7marker' => $marker])),
        );
        $this->assertSame([$second->getUUID(), $first->getUUID()], $this->uuids(Status::get(['t7marker' => $marker])));

        $slug = $first->getSlug();
        $this->assertNotEmpty($slug);
        $this->assertSame(
            $this->uuids($repository->find(new EntityQuery([], Criteria::fromLegacyArray(['slug' => $slug]), 1))),
            [Entity::getBySlug($slug)->getUUID()],
        );

        $this->assertSame(2, Entity::countFromX(Status::class, ['t7marker' => $marker]));
        $this->assertSame($repository->count(new EntityQuery([Status::class], Criteria::fromLegacyArray(['t7marker' => $marker]))), Entity::countFromX(Status::class, ['t7marker' => $marker]));
        $this->assertSame($repository->findOneByUuid($second->getUUID())?->getUUID(), Idno::site()->db()->getObject($second->getUUID())->getUUID());
    }

    /**
     * Ruling P15: a record whose class cannot be loaded is left out of getObjects(), where the old
     * backend returned false in its place; counts still include it, as before.
     */
    public function testGetObjectsLeavesOutARecordWhoseClassCannotBeLoaded(): void
    {
        $db = Idno::site()->db();
        $marker = 't7-' . bin2hex(random_bytes(6));
        $this->status('Loadable ' . $marker, $marker, 1_700_000_000);
        $id = $db->saveRecord('entities', ['entity_subtype' => 'Tests\\Kernel\\NoSuchEntityClass', 'access' => 'PUBLIC', 't7marker' => $marker, 'created' => 1_700_000_100]);
        $this->assertIsString($id);
        $this->created[] = $db->getRecord($id)['uuid'];

        $objects = $db->getObjects('', ['t7marker' => $marker]);
        $this->assertCount(1, $objects);
        $this->assertNotContains(false, $objects, 'no false entries');
        $this->assertSame(2, $db->countObjects('', ['t7marker' => $marker]));
        $this->assertSame([], array_values(array_filter(Entity::getFromX('', ['t7marker' => $marker]), static fn ($e): bool => !$e instanceof Status)));
    }

    // ------------------------------------------------ queries the old backend could not run

    /**
     * Ruling P9: a search array the parser rejects matches nothing and is logged once as a warning
     * that names the method and the query's shape, never its values. The one rejected shape with a
     * plausible dynamic source: `$not => [$in => []]`, which the old backend ran as `!= 'Array'`
     * (in production: the records that have the field).
     */
    public function testAnUnreadableSearchMatchesNothingAndIsLogged(): void
    {
        $db = Idno::site()->db();
        $marker = 't7-' . bin2hex(random_bytes(6));
        $this->status('Has the field ' . $marker, $marker, 1_700_000_000);
        $search = ['t7marker' => ['$not' => ['$in' => []]], 'secret-value-key' => 'do-not-log-me'];

        $this->captureLog();
        $this->assertSame([], $db->getObjects('', $search));
        $this->assertFalse($db->countObjects('', $search));
        $this->assertFalse($db->getRecords([], $search, 10, 0));
        $this->assertFalse($db->countRecords($search));
        $log = $this->readLog();

        $this->assertCount(4, $log, implode("\n", $log));
        foreach (['getObjects', 'countObjects', 'getRecords', 'countRecords'] as $i => $method) {
            $this->assertStringContainsString('warning - ' . $method . '(): the search could not be read, so nothing matches it: {t7marker: {$not: {$in: []}}, secret-value-key: _}', $log[$i]);
            $this->assertStringNotContainsString('do-not-log-me', $log[$i]);
        }
    }

    public function testTheLoggedShapeHasNoValuesAndIsBounded(): void
    {
        $this->assertSame('{a: _, b: {$in: […]}, c: {$not: {$in: []}}, $search: […]}', RepositoryDataConcierge::shape(['a' => 'x', 'b' => ['$in' => ['secret']], 'c' => ['$not' => ['$in' => []]], '$search' => ['term']]));
        $this->assertSame('{a?b: _}', RepositoryDataConcierge::shape(["a\nb" => 1]));
        $long = RepositoryDataConcierge::shape(array_fill_keys(array_map(static fn (int $i): string => 'field' . $i, range(1, 100)), 'v'));
        $this->assertSame(200, strlen($long));
        // M4: a multi-byte key across the limit is not cut in half.
        $this->assertSame('{' . str_repeat('a', 197) . 'é', RepositoryDataConcierge::shape([str_repeat('a', 197) . 'éé' => 1]));
        $cut = RepositoryDataConcierge::shape([str_repeat('a', 198) . 'éé' => 1]);
        $this->assertSame('{' . str_repeat('a', 198), $cut, 'a character that does not fit whole is left out');
        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'));
    }

    /**
     * Ruling P6: a query the old backend sent to MySQL as invalid SQL (here the MongoDB list form of
     * $or) matches nothing; countRecords() gives 0 where the old backend gave false.
     */
    public function testAQueryTheOldBackendFailedCountsZero(): void
    {
        $db = Idno::site()->db();
        $search = ['$or' => [['slug' => 'a'], ['slug' => 'b']]];

        $this->assertFalse($db->getRecords([], $search, 10, 0));
        $this->assertSame(0, $db->countRecords($search));
        $this->assertSame([], $db->getObjects('', $search));
        $this->assertSame(0, $db->countObjects('', $search));
    }

    public function testFailureValuesAreTheOldBackends(): void
    {
        $db = Idno::site()->db();
        $unknown = 'http://unknown.test/view/' . bin2hex(random_bytes(8));

        $this->assertFalse($db->getRecordByUUID($unknown));
        $this->assertFalse($db->getRecord('no-such-id'));
        $this->assertFalse($db->getObject($unknown));
        $this->assertFalse($db->getRecords([], ['uuid' => $unknown], 10, 0), 'false, not [], when nothing matches');
        $this->assertSame(0, $db->countRecords(['uuid' => $unknown]));
        $this->assertSame([], $db->getObjects('', ['uuid' => $unknown]));
        $this->assertFalse($db->getAnyRecord('no_such_collection'));
        $this->assertFalse($db->saveRecord('no_such_collection', ['a' => 1]));
        $this->assertFalse($db->deleteAllRecords(''));
        $this->assertFalse($db->getRecord(['an array']));
        $this->assertFalse($db->getFilesystem());
        $this->assertFalse($db->saveObject(new \stdClass()));
        $this->assertSame(['$search' => ['term']], $db->createSearchArray('term'));
        $this->assertSame('2023-11-14 22:13:20', $db->formatDate(1_700_000_000));
        $this->assertSame('an-id', $db->processID('an-id'));
        $this->assertSame(['uuid', '_id', 'entity_subtype', 'owner', 'publish_status', 'created'], $db->getSchemaFields());
        $this->assertIsArray($db->getErrors());
    }

    public function testARecordThatCannotBeEncodedIsRefusedAndLogged(): void
    {
        $this->captureLog();
        $this->assertFalse(Idno::site()->db()->saveRecord('entities', ['title' => "\xB1\x31 not UTF-8"]));
        $log = $this->readLog();
        $this->assertCount(1, $log);
        $this->assertStringContainsString('error - Malformed UTF-8 characters, possibly incorrectly encoded', $log[0]);
    }

    // ------------------------------------------------ connection, schema and maintenance

    public function testSchemaUpgraderRunsForMysqlOnly(): void
    {
        $pdo = new RecordingPdo('sqlite::memory:');
        $pdo->exec("create table versions (label varchar(32) primary key, value varchar(10))");
        $pdo->exec("insert into versions values ('schema', '2020120201')");
        $this->captureLog();

        (new LegacySchemaUpgrader())->upgrade($pdo, 'sqlite');
        $this->assertSame([], $pdo->prepared, 'nothing runs on SQLite');

        (new LegacySchemaUpgrader())->upgrade($pdo, 'mysql');
        $this->assertSame('select * from versions', $pdo->prepared[0]);
        $this->assertGreaterThan(1, count($pdo->prepared), 'the upgrade newer than the schema version was attempted');
        $this->assertStringContainsString('Applying schema updates from', implode("\n", $this->readLog()));

        // On the rig the schema is current: the versions are what MySQL::getVersions() returns.
        $versions = Idno::site()->db()->getVersions();
        $this->assertIsArray($versions);
        $this->assertEquals((object) ['label' => 'schema', 'value' => (string) LegacySchemaUpgrader::UPGRADES[array_key_last(LegacySchemaUpgrader::UPGRADES)]], $versions[0]);
    }

    public function testMongoConfigStillSelectsTheMongoBackend(): void
    {
        foreach (['mongo', 'MongoDB', ' mongodb '] as $database) {
            $this->assertSame('mongo', ConnectionParameters::driverFamily(['database' => $database]), $database);
            $this->assertNull(ConnectionParameters::backendClass(['database' => $database]), $database);
        }
        // LegacyServiceFactory::db() returns `new \Idno\Data\Mongo()` for these names before anything
        // else (the case is unchanged from before Task 7).
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/Idno/Legacy/LegacyServiceFactory.php');
        $this->assertMatchesRegularExpression("/switch \\(trim\\(strtolower\\(\\\$config->database\\)\\)\\) \\{\\s*case 'mongo':\\s*case 'mongodb':\\s*return new \\\\Idno\\\\Data\\\\Mongo\\(\\);/", $source);
    }

    public function testAnUnknownBackendNameIsMysqlAndALoadableClassIsBuiltAsBefore(): void
    {
        // Ruling P16: the record store and the connection agree with db().
        $this->assertSame('mysql', ConnectionParameters::driverFamily(['database' => 'NoSuchBackend']));
        $factory = new \Idno\Legacy\LegacyRecordStoreFactory(
            static fn (): Connection => Idno::container()->get('database_connection'),
            new \Idno\Config\BootConfig(['database' => 'NoSuchBackend'], false),
            new LegacyRecordDefaults(),
        );
        $this->assertInstanceOf(DbalRecordStore::class, $factory->create());
        $this->assertSame('other', ConnectionParameters::driverFamily(['database' => \Tests\Unit\Storage\PluginBackendFixture::class]));
    }

    public function testOptimizeAndExportRunOnTheSharedConnection(): void
    {
        $db = Idno::site()->db();
        $this->trackExportTempFiles();
        $this->captureLog();
        $this->assertFalse($db->optimize(), 'optimize() returns false, as before');
        $marker = 't7-' . bin2hex(random_bytes(6));
        $status = $this->status('Export ' . $marker, $marker, 1_700_000_000);
        $total = $db->countRecords([]);

        $all = '';
        for ($offset = 0; $offset < $total + 10 && ($page = $db->exportRecords('entities', 10, $offset)); $offset += 10) {
            $all .= $page;
        }
        $this->assertStringContainsString("insert into entities (`uuid`,`_id`,", $all);
        $this->assertStringContainsString($db->getClient()->quote($status->getUUID()), $all);
        // OBSERVED: the metadata lookup binds the record's uuid as its _id, so it never matches and
        // no metadata row is ever exported (a legacy bug, moved unchanged).
        $this->assertStringNotContainsString('insert into entities_metadata', $all);
        $this->assertSame('', $db->exportRecords('entities', 10, $total + 10), 'an empty page is an empty string');
    }

    public function testReconnectIfNeededKeepsALiveConnection(): void
    {
        $db = Idno::site()->db();
        $client = $db->getClient();
        $db->reconnectIfNeeded();
        $this->assertSame($client, $db->getClient());
    }

    /**
     * The async queue's query shapes through the adapter: enqueue (save), the pending lookup
     * (`complete $not $in [true]`), dispatch (marks complete and saves), the garbage collector
     * (`completedTs $lt`, `complete` true).
     */
    public function testTheAsynchronousQueueRunsThroughTheAdapter(): void
    {
        $queue = new \Idno\Core\AsynchronousQueue();
        $name = 't7queue' . bin2hex(random_bytes(4));
        $event = 't7/event/' . bin2hex(random_bytes(4));
        $received = [];
        Idno::site()->events()->addListener($event, static function (\Idno\Core\Event $e) use (&$received): void {
            $received[] = $e->data();
        });

        $id = $queue->enqueue($name, $event, ['n' => 1]);
        $this->assertIsString($id);
        $pending = \Idno\Entities\AsynchronousQueuedEvent::getPendingFromQueue($name);
        $this->assertCount(1, $pending);
        $this->assertFalse($queue->isComplete($id));
        $this->created[] = $pending[0]->getUUID();

        $this->assertNotFalse($queue->dispatch($pending[0]));
        $this->assertSame([['n' => 1]], $received);
        $this->assertTrue($queue->isComplete($id));
        $this->assertSame([], \Idno\Entities\AsynchronousQueuedEvent::getPendingFromQueue($name));
        $this->assertCount(1, \Idno\Entities\AsynchronousQueuedEvent::getFromQueue($name));

        $queue->gc(-60, $name);
        $this->assertSame([], \Idno\Entities\AsynchronousQueuedEvent::getFromQueue($name));
    }

    // ------------------------------------------------ sessions

    public function testSessionRowIsWrittenThroughTheSharedConnection(): void
    {
        $response = $this->get('');
        $this->assertSame(200, $response['status']);
        $sessionId = $response['cookies'][Idno::site()->config()->sessionname] ?? null;
        $this->assertNotEmpty($sessionId, 'the page sets a session cookie');

        $statement = Idno::site()->db()->getClient()->prepare('select session_value, session_lifetime, session_time from session where session_id = ?');
        $statement->execute([$sessionId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $this->deleteSession($sessionId);

        $this->assertIsArray($row, 'a session row was written for the cookie');
        $this->assertStringStartsWith('secure|b:0;', $row['session_value']);
        $this->assertGreaterThan((int) $row['session_time'], (int) $row['session_lifetime']);
    }

    /**
     * A user logged in before the deploy stays logged in: a session row written by the old backend
     * (Idno\Data\MySQL::handleSession(), in a subprocess) is read by the adapter's handler when the
     * site serves the next request with that cookie.
     */
    public function testASessionWrittenByTheOldBackendIsReadByTheAdapter(): void
    {
        $this->requireMysql('the session is written by the old MySQL backend (Idno\\Data\\MySQL), in a subprocess.');
        $user = $this->user();
        $this->assertSame(403, $this->get('account/settings/')['status'], 'anonymous visitors have no account settings');

        $sessionId = $this->legacySession($user->getUUID());
        try {
            $response = $this->get('account/settings/', [Idno::site()->config()->sessionname => $sessionId]);
            $this->assertSame(200, $response['status'], 'the session the old backend wrote logs the user on');
            $this->assertStringContainsString($user->getHandle(), $response['body']);

            // The adapter's handler read the row and wrote it back, in the same format.
            $statement = Idno::site()->db()->getClient()->prepare('select session_value from session where session_id = ?');
            $statement->execute([$sessionId]);
            $this->assertStringStartsWith('user_uuid|s:' . strlen($user->getUUID()) . ':"' . $user->getUUID() . '";secure|b:0;', (string) $statement->fetchColumn());
        } finally {
            $this->deleteSession($sessionId);
        }
    }

    /**
     * A visitor who logs in through the login form stays logged in on the following requests: the
     * session the adapter's handler wrote is read back (on MySQL and on SQLite, where it is not locked).
     */
    public function testALoginSessionSurvivesAcrossRequests(): void
    {
        $user = $this->user();
        $previousPassword = $user->password;
        $password = bin2hex(random_bytes(12));
        $user->setPassword($password);
        $this->assertNotFalse($user->save(true));
        $name = Idno::site()->config()->sessionname;
        $sessions = [];
        try {
            $form = $this->get('session/login');
            $this->assertSame(200, $form['status']);
            $cookies = [$name => (string) ($form['cookies'][$name] ?? '')];
            $sessions[] = $cookies[$name];
            $fields = ['email' => $user->getHandle(), 'password' => $password, 'fwd' => Idno::site()->config()->getURL()];
            foreach (['__bTs', '__bTk', '__bTa'] as $token) {
                $this->assertSame(1, preg_match('/name="' . $token . '" value="([^"]*)"/', $form['body'], $m), $token);
                $fields[$token] = html_entity_decode($m[1]);
            }

            $login = self::request(Idno::site()->config()->getURL() . 'session/login', $cookies, $fields);
            $this->assertSame(302, $login['status'], 'logged in and forwarded');
            if (isset($login['cookies'][$name])) {
                $cookies[$name] = $login['cookies'][$name];
                $sessions[] = $cookies[$name];
            }

            foreach ([1, 2] as $request) {
                $page = $this->get('account/settings/', $cookies);
                $this->assertSame(200, $page['status'], 'request ' . $request . ' after logging in is logged in');
                $this->assertStringContainsString($user->getHandle(), $page['body']);
            }
            $statement = Idno::site()->db()->getClient()->prepare('select session_value from session where session_id = ?');
            $statement->execute([$cookies[$name]]);
            $this->assertStringContainsString('user_uuid|s:' . strlen($user->getUUID()) . ':"' . $user->getUUID() . '"', (string) $statement->fetchColumn());
        } finally {
            foreach (array_filter($sessions) as $sessionId) {
                $this->deleteSession($sessionId);
            }
            $user->password = $previousPassword;
            $user->save(true);
        }
    }

    // ------------------------------------------------ search through the site

    /**
     * The site's search page finds a post by a word of its body: whole words through MySQL's full-text
     * index, substrings on SQLite (ruling P8); a whole word is found on both.
     */
    public function testTheSearchPageFindsAPost(): void
    {
        $word = 'seawalrus' . bin2hex(random_bytes(5));
        $status = $this->status('Looking for the ' . $word . ' today', 't7-' . bin2hex(random_bytes(6)), time());

        $found = $this->get('content/all/?q=' . $word);
        $this->assertSame(200, $found['status']);
        $this->assertStringContainsString($word, $found['body']);
        $this->assertStringContainsString($status->getURL(), $found['body']);

        $missing = $this->get('content/all/?q=seawalrus' . bin2hex(random_bytes(5)));
        $this->assertSame(200, $missing['status']);
        $this->assertStringNotContainsString($status->getURL(), $missing['body']);
    }

    // ------------------------------------------------ an unreachable database

    /**
     * The trigger is the site's own database made unreachable: on MySQL a server on a closed port, on
     * SQLite a database file in a directory that does not exist (see unreachableDatabase()).
     */
    public function testUnreachableDatabaseShowsTheLegacyErrorPage(): void
    {
        [$database, $message, $connectionString] = $this->unreachableDatabase();
        $response = $this->serveUnreachable($database, []);

        $this->assertSame(500, $response['status']);
        $this->assertSame($this->legacyErrorPage($message, $connectionString), $response['body']);
    }

    public function testUnreachableDatabaseWithForwardOnEmptyRedirects(): void
    {
        [$database] = $this->unreachableDatabase();
        $response = $this->serveUnreachable($database, ['IDNO_FORWARD_ON_EMPTY' => 'http://forward.example.test/away']);

        $this->assertSame(302, $response['status']);
        $this->assertSame('http://forward.example.test/away', $response['headers']['location'] ?? null);
        $this->assertSame('', $response['body']);
    }

    /**
     * OBSERVED on the commit before Task 7 (MySQL): the console prints the error page on stdout, writes
     * the driver's message twice to the error log (stderr) and exits 0; with forward_on_empty set, it
     * prints nothing, writes the message once and exits 0. SQLite: the same, with its driver's message.
     */
    public function testUnreachableDatabaseOnTheConsole(): void
    {
        [$database, $message, $connectionString] = $this->unreachableDatabase();

        [$exit, $stdout, $stderr] = $this->console($database, []);
        $this->assertSame(0, $exit);
        $this->assertSame($this->legacyErrorPage($message, $connectionString), $stdout);
        $this->assertSame([$message, $message], self::lines($stderr));

        [$exit, $stdout, $stderr] = $this->console($database, ['IDNO_FORWARD_ON_EMPTY' => 'http://forward.example.test/away']);
        $this->assertSame(0, $exit);
        $this->assertSame('', $stdout);
        $this->assertSame([$message], self::lines($stderr));
    }

    // ------------------------------------------------ SQLite

    /**
     * The adapter over an SQLite database loaded with warmup/schemas/sqlite/sqlite.sql: records,
     * entities, counts, deletion, the session handler and the versions. The schema upgrade does
     * nothing there.
     */
    public function testTheAdapterWorksOnSqlite(): void
    {
        $path = sys_get_temp_dir() . '/idno-t7-' . bin2hex(random_bytes(6)) . '.db';
        array_push($this->files, $path, $path . '-wal', $path . '-shm');
        $this->trackExportTempFiles();
        $connection = SqliteTestDatabase::withSchema($path);
        $store = new DbalRecordStore($connection, new SqliteDialect($connection), new LegacyRecordDefaults(), static fn (string $text): string => (string) Idno::site()->language()->uncurlQuotes($text), true, false);
        $repository = new EntityRepository($store, new LegacyAccessContext(), new NullLogger());
        $logger = new RecordingLogger();
        $adapter = new RepositoryDataConcierge('sqlite', $connection, static fn (): RecordStore => $store, static fn (): EntityRepository => $repository, static fn (): \Psr\Log\LoggerInterface => $logger, $path);

        $this->assertInstanceOf(\PDO::class, $adapter->getClient());
        $this->assertSame($connection->getNativeConnection(), $adapter->getClient());
        $this->assertSame($path, $adapter->getDatabase());
        $this->assertEquals([(object) ['label' => 'schema', 'value' => '2020120301']], $adapter->getVersions());

        // Records
        $id = $adapter->saveRecord('entities', ['title' => 'A walrus', 'slug' => 'walrus', 'access' => 'PUBLIC', 'created' => 1_700_000_000]);
        $this->assertIsString($id);
        $this->assertSame('A walrus', $adapter->getRecord($id)['title']);
        $uuid = $adapter->getRecord($id)['uuid'];
        $this->assertSame($id, $adapter->getRecordByUUID($uuid)['_id']);
        $this->assertSame($id, $adapter->getAnyRecord()['_id']);
        $this->assertSame([$id], array_column($adapter->getRecords([], ['slug' => 'walrus'], 10, 0), '_id'));
        $this->assertSame(1, $adapter->countRecords(['$search' => ['walrus']]));

        // Entities
        $status = new Status();
        $status->setOwner($this->user());
        $status->body = 'An SQLite status about seals';
        $status->setAccess('PUBLIC');
        $status->created = 1_700_000_100;
        $statusId = $adapter->saveObject($status);
        $this->assertIsString($statusId);
        $objects = $adapter->getObjects(Status::class);
        $this->assertCount(1, $objects);
        $this->assertInstanceOf(Status::class, $objects[0]);
        $this->assertSame('An SQLite status about seals', $objects[0]->body);
        $this->assertSame(1, $adapter->countObjects(Status::class));
        $this->assertSame(2, $adapter->countObjects(''));
        $this->assertSame($objects[0]->getUUID(), $adapter->getObject($objects[0]->getUUID())->getUUID());

        // Deletion
        $this->assertTrue($adapter->deleteRecord($statusId));
        $this->assertFalse($adapter->getRecord($statusId));
        $this->assertSame(0, $adapter->countObjects(Status::class));

        // Sessions: the handler handleSession() installs, on the same PDO
        $handler = $adapter->sessionHandler();
        $sessionId = bin2hex(random_bytes(16));
        $this->assertTrue($handler->open('', 'idno'));
        $this->assertSame('', $handler->read($sessionId));
        $this->assertTrue($handler->write($sessionId, 'user_uuid|s:4:"abcd";'));
        $this->assertTrue($handler->close());
        $this->assertTrue($handler->open('', 'idno'));
        $this->assertSame('user_uuid|s:4:"abcd";', $handler->read($sessionId));
        $this->assertTrue($handler->close());
        $this->assertSame(1, (int) $connection->fetchOne('select count(*) from session where session_id = ?', [$sessionId]));

        // Maintenance: optimize() is MySQL's and fails (written to the error log, as on MySQL), export works.
        $this->captureLog();
        $this->assertFalse($adapter->optimize());
        $this->assertStringContainsString('near "optimize": syntax error', implode("\n", $this->readLog()));
        $this->assertStringContainsString('insert into entities (`uuid`,`_id`,', (string) $adapter->exportRecords('entities', 10, 0));
        $this->assertSame([], $logger->records, 'nothing was logged through the site logger');

        $connection->close();
    }

    // ------------------------------------------------ helpers

    private function status(string $body, string $marker, int $created): Status
    {
        $status = new Status();
        $status->setOwner($this->user());
        $status->body = $body;
        $status->t7marker = $marker;
        $status->setAccess('PUBLIC');
        $status->created = $created;
        $this->assertNotFalse($status->save(true));
        $this->created[] = $status->getUUID();

        return $status;
    }

    /**
     * @param list<Entity|false> $entities
     *
     * @return list<string>
     */
    private function uuids(array $entities): array
    {
        return array_map(static fn ($entity): string => $entity instanceof Entity ? $entity->getUUID() : 'false', array_values($entities));
    }

    /**
     * exportRecords() creates an empty temporary file on each call and never uses it (legacy, moved
     * unchanged): remove the ones the test makes.
     */
    private function trackExportTempFiles(): void
    {
        $pattern = rtrim((string) Idno::site()->config()->getTempDir(), '/') . '/sqldump*';
        $before = glob($pattern) ?: [];
        $this->exportTempFiles = static fn (): array => array_values(array_diff(glob($pattern) ?: [], $before));
    }

    private function captureLog(): void
    {
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'idno-t7-log');
        $this->previousErrorLog = ini_set('error_log', $this->logFile);
    }

    /**
     * @return list<string>
     */
    private function readLog(): array
    {
        return self::lines((string) file_get_contents((string) $this->logFile));
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return array_values(array_filter(explode("\n", $text), static fn (string $line): bool => trim($line) !== ''));
    }

    private function deleteSession(string $sessionId): void
    {
        Idno::site()->db()->getClient()->prepare('delete from session where session_id = ?')->execute([$sessionId]);
    }

    /**
     * GET a page of the rig's site.
     *
     * @param array<string, string> $cookies
     *
     * @return array{status: int, headers: array<string, string>, cookies: array<string, string>, body: string}
     */
    private function get(string $path, array $cookies = []): array
    {
        return self::request(Idno::site()->config()->getURL() . $path, $cookies);
    }

    /**
     * @param array<string, string>      $cookies
     * @param array<string, string>|null $post    form fields to POST (null: a GET)
     *
     * @return array{status: int, headers: array<string, string>, cookies: array<string, string>, body: string}
     */
    private static function request(string $url, array $cookies = [], ?array $post = null): array
    {
        $headers = [];
        $cookiesSet = [];
        $ch = curl_init($url);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_COOKIE => implode('; ', array_map(static fn (string $k, string $v): string => $k . '=' . $v, array_keys($cookies), $cookies)),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers, &$cookiesSet): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                    if (strtolower(trim($name)) === 'set-cookie' && preg_match('/^([^=]+)=([^;]*)/', trim($value), $m)) {
                        $cookiesSet[$m[1]] = $m[2];
                    }
                }

                return strlen($line);
            },
        ]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'headers' => $headers, 'cookies' => $cookiesSet, 'body' => $body];
    }

    /**
     * Runs Tests/Kernel/boot_legacy_session.php: the old backend writes a session for the user.
     */
    private function legacySession(string $userUuid): string
    {
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=stderr '
            . escapeshellarg(__DIR__ . '/boot_legacy_session.php') . ' ' . escapeshellarg($userUuid);
        [$exit, $stdout, $stderr] = self::runProcess($command, ['IDNO_DOMAIN' => '127.0.0.1', 'IDNO_PORT' => '8089']);
        $decoded = json_decode((string) trim($stdout), true);
        $this->assertIsArray($decoded, "boot_legacy_session.php did not print JSON (exit $exit).\nstdout: $stdout\nstderr: $stderr");
        $this->assertSame(\Idno\Data\MySQL::class, $decoded['writer']);

        return (string) $decoded['session_id'];
    }

    /**
     * A TCP port on 127.0.0.1 with nothing listening.
     */
    private static function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * The site's database made unreachable, by driver family: the environment that does it, the
     * message of the PDOException connecting raises (what the error log and the page show), and the
     * connection string the page shows in debug mode. MySQL: a server on a closed port. SQLite: a
     * database file in a directory that does not exist (pdo_sqlite cannot create it).
     *
     * @return array{0: array<string, string>, 1: string, 2: string}
     */
    private function unreachableDatabase(): array
    {
        $config = Idno::site()->config();
        if (ConnectionParameters::driverFamily(Idno::container()->get(\Idno\Config\BootConfig::class)->all()) === 'sqlite') {
            $path = sys_get_temp_dir() . '/idno-no-such-dir-' . bin2hex(random_bytes(6)) . '/site.sqlite';
            try {
                new \PDO('sqlite:' . $path);
                self::fail('The SQLite database ' . $path . ' could be opened');
            } catch (\PDOException $e) {
                $message = $e->getMessage();
            }

            return [['IDNO_DBNAME' => $path], $message, 'sqlite:' . $path];
        }
        $port = self::closedPort();

        return [
            ['IDNO_DBHOST' => '127.0.0.1', 'IDNO_DBPORT' => (string) $port],
            self::connectionError($port),
            'mysql:host=127.0.0.1;dbname=' . $config->dbname . ';charset=utf8;port=' . $port,
        ];
    }

    /**
     * The message of the PDOException a connection to the closed port raises (what the old backend logged).
     */
    private static function connectionError(int $port): string
    {
        try {
            new \PDO('mysql:host=127.0.0.1;port=' . $port, 'root', '');
        } catch (\PDOException $e) {
            return $e->getMessage();
        }
        self::fail('Something is listening on port ' . $port);
    }

    /**
     * statics/db.php as Idno\Data\MySQL::init() rendered it: in debug mode with the driver's message
     * and the connection string.
     */
    private function legacyErrorPage(string $driverMessage, string $connectionString): string
    {
        $config = Idno::site()->config();
        $message = '';
        if ($config->debug) {
            $message = '<p>' . $driverMessage . '</p><p>' . $connectionString . '</p>';
        }
        $code = 'http_response_code(500); $message = $argv[1]; include $argv[2];';
        [, $stdout] = self::runProcess(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' -- ' . escapeshellarg($message) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/statics/db.php'), []);

        return $stdout;
    }

    /**
     * Serves one GET / through Tests/Kernel/boot_db_unreachable.php (PHP's built-in web server) with
     * the database unreachable.
     *
     * @param array<string, string> $database what unreachableDatabase() returned
     * @param array<string, string> $env
     *
     * @return array{status: int, headers: array<string, string>, cookies: array<string, string>, body: string}
     */
    private function serveUnreachable(array $database, array $env): array
    {
        $env += $database + ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'PATH' => (string) getenv('PATH')];
        $script = __DIR__ . '/boot_db_unreachable.php';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(20000, 60000);
            $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -S 127.0.0.1:' . $port . ' ' . escapeshellarg($script);
            $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__, 2), $env);
            $this->assertIsResource($process);
            try {
                for ($i = 0; $i < 50; $i++) {
                    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                    if ($socket !== false) {
                        fclose($socket);

                        return self::request('http://127.0.0.1:' . $port . '/');
                    }
                    if (!proc_get_status($process)['running']) {
                        break; // Port taken: try another.
                    }
                    usleep(100_000);
                }
            } finally {
                proc_terminate($process);
                proc_close($process);
            }
        }
        $this->fail('Could not start PHP\'s built-in web server');
    }

    /**
     * Runs `bin/console list` with the database unreachable.
     *
     * @param array<string, string> $database what unreachableDatabase() returned
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function console(array $database, array $env): array
    {
        $env += $database + ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'IDNO_PROXY_STRING' => '127.0.0.1:9'];

        return self::runProcess(escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d log_errors=1 -d error_log= ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/console') . ' list', $env);
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function runProcess(string $command, array $env): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env + ['PATH' => (string) getenv('PATH')]);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}

/**
 * A PDO that records the statements it prepares (for the schema upgrader).
 */
final class RecordingPdo extends \PDO
{
    /** @var list<string> */
    public array $prepared = [];

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->prepared[] = $query;

        return parent::prepare($query, $options);
    }
}

/**
 * A logger that keeps what it is given.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{mixed, string}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message];
    }
}
