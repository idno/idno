<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Doctrine\DBAL\Connection;
use Idno\Common\Entity;
use Idno\Core\Idno;
use Idno\Legacy\LegacyAccessContext;
use Idno\Legacy\LegacyRecordDefaults;
use Idno\Legacy\RepositoryDataConcierge;
use Idno\Storage\AccessContext;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\Criteria;
use Idno\Storage\EntityQuery;
use Idno\Storage\EntityRepository;
use Idno\Storage\FixedAccessContext;
use Idno\Storage\IdentityTypeMap;
use Idno\Storage\JsonRecordStore;
use Idno\Storage\RecordStore;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use Idno\Storage\SettingsRecordStore;
use IdnoPlugins\Status\Status;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Tests\IdnoTestCase;
use Tests\Storage\PrivateDatabase;

/**
 * The legacy db() service on an SQL site is Idno\Legacy\RepositoryDataConcierge: the old backend's
 * API (Idno\Data\MySQL / AbstractSQL) served by the storage layer, on the shared DBAL connection.
 * Rig tests on the rig's database (MySQL, MariaDB or SQLite; the tests that need MySQL skip on SQLite
 * and say why), plus the adapter built over an SQLite database of its own. The site's tables are
 * those of the schema migrations (`idno_<collection>`, `idno_settings`, `idno_sessions`).
 */
final class RepositoryDataConciergeTest extends IdnoTestCase
{
    /**
     * Every public method of the old SQL backend, Idno\Data\MySQL (with those it inherits from
     * AbstractSQL, DataConcierge and Component), captured from the class before it was replaced,
     * with its parameters (name => default, or no default when required) and whether it is static.
     * The class has since been removed; this list is what it had (checked against it until then).
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
     * old backend's SQL compiler (Criteria::fromLegacyArray() and the record store replace it); nothing
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

    /**
     * Skips a test that needs the site's database to be SQLite (the rig's default mode), saying why.
     */
    private function requireSqlite(string $why): void
    {
        $config = Idno::container()->get(\Idno\Config\BootConfig::class)->all();
        if (ConnectionParameters::driverFamily($config) !== 'sqlite') {
            $this->markTestSkipped('Needs an SQLite site: ' . $why);
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
     * The shared connection has the session and the PDO attributes the legacy backend's own
     * connection had. On every server it answers as a control connection to the same server opened
     * the way Idno\Data\MySQL (since removed) opened its own (a PDO with the DSN charset utf8mb4 and
     * ERRMODE_EXCEPTION, nothing else), so the server's own defaults (MariaDB's sql_mode differs from
     * MySQL 8's) are what both have. On MySQL 8 it also answers exactly what a connection of the old
     * backend answered on the rig's MySQL 8, observed on the commit before its removal.
     */
    public function testTheConnectionMatchesTheLegacyBackendsConnection(): void
    {
        $this->requireMysql('it compares the shared connection\'s MySQL session with what a connection of the old MySQL backend had.');
        $query = 'SELECT @@session.sql_mode, @@session.time_zone, @@character_set_client, @@collation_connection, @@character_set_connection, @@character_set_results, @@session.autocommit';
        $legacy = [
            'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
            'SYSTEM',
            'utf8mb4',
            'utf8mb4_general_ci',
            'utf8mb4',
            'utf8mb4',
            1,
        ];
        $legacyAttributes = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => true,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_BOTH,
            \PDO::ATTR_CASE => \PDO::CASE_NATURAL,
            \PDO::ATTR_ORACLE_NULLS => \PDO::NULL_NATURAL,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        $config = Idno::site()->config();
        $dsn = 'mysql:host=' . $config->dbhost . ';dbname=' . $config->dbname . ';charset=utf8mb4' . (empty($config->dbport) ? '' : ';port=' . $config->dbport);
        $control = new \PDO($dsn, $config->dbuser, $config->dbpass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $actual = Idno::site()->db()->getClient()->query($query)->fetch(\PDO::FETCH_NUM);

        $this->assertSame($control->query($query)->fetch(\PDO::FETCH_NUM), $actual, 'the session of a connection the old backend opened on this server');
        if (!str_contains((string) $control->query('select version()')->fetchColumn(), 'MariaDB')) {
            $this->assertSame($legacy, $actual, 'what the old backend\'s connection answered on MySQL 8');
        }
        foreach ($legacyAttributes as $attribute => $value) {
            $this->assertSame($value, Idno::site()->db()->getClient()->getAttribute($attribute), 'PDO attribute ' . $attribute);
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
     * before the adapter replaced the old backend (reflection on the container's services, in the order they were instantiated).
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

    // ------------------------------------------------ refused saves, read groups, search settings, backend names

    /**
     * A deliberate difference: a save the database refuses returns false and stores nothing. The refused
     * value is another record's uuid (the uuid is unique), which every database refuses: a new record
     * with it, and an existing record re-saved with it (which leaves the previous version, and the
     * other record, intact). (The old backend returned the id of a save it had rolled back when a
     * metadata row was refused; observed before the adapter replaced it, no longer testable now it is gone.)
     */
    public function testARefusedSaveReturnsFalseAndStoresNothing(): void
    {
        $db = Idno::site()->db();
        $marker = 'adapter-test-' . bin2hex(random_bytes(6));
        $other = $this->saveTestRecord(['testmarker' => $marker, 'testnote' => 'the other record']);
        $otherUuid = $db->getRecord($other)['uuid'];
        $refused = ['testmarker' => $marker, 'testnote' => 'refused version', 'uuid' => $otherUuid, 'access' => 'PUBLIC', 'created' => 1_700_000_000];
        $owner = $this->user();

        $this->captureLog();
        $this->assertFalse($db->saveRecord('entities', $refused));
        $this->assertSame(1, $db->countRecords(['testmarker' => $marker]));

        // An update the database refuses leaves the previous version intact.
        $id = $db->saveRecord('entities', ['testmarker' => $marker, 'testnote' => 'first version', 'access' => 'PUBLIC', 'created' => 1_700_000_000]);
        $this->assertIsString($id);
        $this->created[] = $db->getRecord($id)['uuid'];
        $this->assertFalse($db->saveRecord('entities', ['_id' => $id] + $refused));
        $this->assertSame('first version', $db->getRecord($id)['testnote']);
        $this->assertSame('the other record', $db->getRecordByUUID($otherUuid)['testnote']);
        $this->assertSame(2, $db->countRecords(['testmarker' => $marker]));

        $status = new Status();
        $status->setOwner($owner);
        $status->body = 'Refused ' . $marker;
        $status->uuid = $otherUuid;
        $status->setAccess('PUBLIC');
        $this->assertFalse($db->saveObject($status));
        $this->assertSame([], $this->readLog(), 'a refusal is no error');
    }

    /**
     * The same, through Entity::save(): an existing entity re-saved with another record's uuid
     * returns false and fires no `updated` (nor `saved`) event, and both records are left as they
     * were; the old backend reported success and fired them for a record it had not stored.
     */
    public function testEntitySaveOfARefusedRecordReturnsFalseAndFiresNoEvent(): void
    {
        $marker = 'adapter-test-' . bin2hex(random_bytes(6));
        $fired = [];
        $listener = static function (\Idno\Core\Event $event) use (&$fired, $marker): void {
            $object = $event->data()['object'] ?? null;
            if ($object instanceof Entity && $object->testmarker === $marker) {
                $fired[] = $object->testnote;
            }
        };
        $dispatcher = (new \ReflectionProperty(\Idno\Core\EventDispatcher::class, 'dispatcher'))->getValue(Idno::site()->events());
        $dispatcher->addListener('saved', $listener);
        $dispatcher->addListener('updated', $listener);
        try {
            $other = $this->status('Other ' . $marker, $marker, 1_700_000_000);
            $existing = $this->status('Existing ' . $marker, $marker, 1_700_000_060);
            $this->assertSame([null, null], $fired, 'the control: saving a storable entity fires saved');
            $fired = [];
            $uuid = $existing->getUUID();
            $existing->testnote = 'updated';
            $existing->uuid = $other->getUUID();
            $this->assertFalse($existing->save(true));
            $this->assertSame([], $fired, 'no saved or updated event for a refused save');
            $this->assertSame(2, Idno::site()->db()->countRecords(['testmarker' => $marker]));
            $this->assertArrayNotHasKey('testnote', Idno::site()->db()->getRecordByUUID($uuid));
            $this->assertSame('Other ' . $marker, Idno::site()->db()->getRecordByUUID($other->getUUID())['body']);
        } finally {
            $dispatcher->removeListener('saved', $listener);
            $dispatcher->removeListener('updated', $listener);
        }
    }

    /**
     * The same, through Entity::save(), with a refusal both databases make: a NEW entity whose
     * uuid is another record's (the uuid is unique) returns false and fires no `saved` event, and the
     * other record is left as it was. (The field-name case above needs MySQL.)
     */
    public function testEntitySaveOfANewRecordWithAnExistingUuidReturnsFalseAndFiresNoEvent(): void
    {
        $marker = 'adapter-test-' . bin2hex(random_bytes(6));
        $fired = [];
        $listener = static function (\Idno\Core\Event $event, string $name) use (&$fired, $marker): void {
            $object = $event->data()['object'] ?? null;
            if ($object instanceof Entity && $object->testmarker === $marker) {
                $fired[] = $name;
            }
        };
        $dispatcher = (new \ReflectionProperty(\Idno\Core\EventDispatcher::class, 'dispatcher'))->getValue(Idno::site()->events());
        $dispatcher->addListener('saved', $listener);
        $dispatcher->addListener('updated', $listener);
        try {
            $existing = $this->status('Existing ' . $marker, $marker, 1_700_000_000);
            $this->assertSame(['saved'], $fired, 'the control: the listeners see a successful save');
            $fired = [];

            $clash = new Status();
            $clash->setOwner($this->user());
            $clash->body = 'Clashing ' . $marker;
            $clash->testmarker = $marker;
            $clash->uuid = $existing->getUUID();
            $clash->setAccess('PUBLIC');

            $this->assertFalse($clash->save(true));
            $this->assertSame([], $fired, 'no saved or updated event for a refused save');
            $this->assertSame(1, Idno::site()->db()->countRecords(['testmarker' => $marker]));
            $this->assertSame('Existing ' . $marker, Idno::site()->db()->getRecordByUUID($existing->getUUID())['body']);
        } finally {
            $dispatcher->removeListener('saved', $listener);
            $dispatcher->removeListener('updated', $listener);
        }
    }

    // ------------------------------------------------ multi-byte and four-byte characters

    /**
     * Values whose 255th byte falls inside a multi-byte character, with what the old backend's
     * indexed copy of them was: everything up to the last whole character that fits.
     *
     * @return array<string, array{string, string}>
     */
    public static function straddlingValues(): array
    {
        return [
            'two-byte characters' => [str_repeat('é', 200), str_repeat('é', 127)],
            'three-byte characters' => ['a' . str_repeat('日', 100), 'a' . str_repeat('日', 84)],
            'four-byte character' => [str_repeat('a', 253) . "\u{1F600} and the rest", str_repeat('a', 253)],
        ];
    }

    /**
     * A record with a multi-byte character across the old 255-byte limit of an indexed value is
     * stored byte for byte and found by its whole value; a query for the old backend's indexed copy
     * (cut on a character boundary) no longer finds it: whole values are compared.
     *
     * @dataProvider straddlingValues
     */
    public function testAMultiByteValueAcrossTheOldIndexLimitIsStoredWholeAndFoundWhole(string $value, string $oldIndexedCopy): void
    {
        $db = Idno::site()->db();
        $id = $this->saveTestRecord(['testnote' => $value]);

        $this->assertSame($value, $db->getRecord($id)['testnote']);
        $this->assertContains($id, array_column($db->getRecords([], ['testnote' => $value], 10, 0) ?: [], '_id'));
        $this->assertNotContains($id, array_column($db->getRecords([], ['testnote' => $oldIndexedCopy], 10, 0) ?: [], '_id'));
    }

    public function testMultiByteValuesInAListAreEachFoundWhole(): void
    {
        $values = [str_repeat('é', 200), 'short', 'a' . str_repeat('日', 100)];
        $id = $this->saveTestRecord(['testnotes' => $values]);

        $this->assertSame($values, Idno::site()->db()->getRecord($id)['testnotes']);
        foreach ($values as $value) {
            $this->assertContains($id, array_column(Idno::site()->db()->getRecords([], ['testnotes' => $value], 10, 0) ?: [], '_id'), $value);
        }
        $this->assertFalse(Idno::site()->db()->getRecords([], ['testnotes' => str_repeat('é', 127), 'uuid' => Idno::site()->db()->getRecord($id)['uuid']], 10, 0), 'the old indexed copy is no value');
    }

    /**
     * MySQL's SQL modes: strict (the default since MySQL 5.7) refuses a value a column cannot hold,
     * non-strict stores what it can. SQLite has no SQL mode: both cases run the same there.
     *
     * @return array<string, array{string}>
     */
    public static function sqlModes(): array
    {
        return [
            'strict mode' => ['STRICT_TRANS_TABLES'],
            'non-strict mode' => [''],
        ];
    }

    /**
     * An entity with an emoji in its title, body and tags is saved and read back unchanged; its
     * search text holds the whole text, a query for each of its fields finds it, and so does a
     * full-text search for a word after the emoji.
     *
     * @dataProvider sqlModes
     */
    public function testAnEntityWithAnEmojiIsStoredWholeAndFound(string $sqlMode): void
    {
        $title = "Launch day \u{1F680} retrospective";
        $body = "We shipped it \u{1F389} and nobody quokkaspotted the difference";
        $tags = "#party\u{1F389}";
        $entry = $this->saveEntry($sqlMode, $title, $body, $tags);
        $id = $entry->getID();

        $loaded = \IdnoPlugins\Text\Entry::getByID($id);
        $this->assertInstanceOf(\IdnoPlugins\Text\Entry::class, $loaded);
        $this->assertSame([$title, $body, $tags], [$loaded->title, $loaded->body, $loaded->tags]);

        $statement = Idno::site()->db()->getClient()->prepare('select search from idno_entities where id = ?');
        $statement->execute([$id]);
        $search = (string) $statement->fetchColumn();
        foreach ([$title, $tags, $body] as $text) {
            $this->assertStringContainsString(strtolower($text), $search);
        }
        foreach (['title' => $title, 'body' => $body] as $field => $value) {
            $this->assertContains($id, array_column(Idno::site()->db()->getRecords([], [$field => $value], 10, 0) ?: [], '_id'), $field);
        }

        $this->assertContains($id, array_column(Idno::site()->db()->getRecords([], ['tags' => $tags], 10, 0), '_id'));
        $found = \IdnoPlugins\Text\Entry::getOne(['tags' => $tags]);
        $this->assertInstanceOf(\IdnoPlugins\Text\Entry::class, $found);
        $this->assertSame($id, $found->getID());
        // Longer than five bytes: a full-text query on MySQL.
        $this->assertContains($id, array_column(Idno::site()->db()->getRecords([], ['$search' => ['quokkaspotted']], 10, 0), '_id'));
    }

    /**
     * Two values that differ only in an emoji are different values (MySQL's utf8mb4_general_ci and
     * utf8mb4_unicode_ci would give every four-byte character the same weight).
     */
    public function testValuesThatDifferOnlyInAnEmojiAreDifferentValues(): void
    {
        $party = $this->saveEntry('STRICT_TRANS_TABLES', 'One', 'Party', "#party\u{1F389}");
        $balloon = $this->saveEntry('STRICT_TRANS_TABLES', 'Two', 'Balloon', "#party\u{1F388}");

        $ids = array_column(Idno::site()->db()->getRecords([], ['tags' => "#party\u{1F388}"], 10, 0), '_id');
        $this->assertContains($balloon->getID(), $ids);
        $this->assertNotContains($party->getID(), $ids);
    }

    /**
     * A search for an emoji (four bytes: a substring match) finds the records that contain it and
     * no others.
     */
    public function testASearchForAnEmojiFindsOnlyThatEmoji(): void
    {
        $party = $this->saveEntry('STRICT_TRANS_TABLES', 'One', "A party \u{1F389}", '');
        $balloon = $this->saveEntry('STRICT_TRANS_TABLES', 'Two', "A balloon \u{1F388}", '');

        $ids = array_column(Idno::site()->db()->getRecords([], ['$search' => ["\u{1F388}"]], 10, 0), '_id');
        $this->assertContains($balloon->getID(), $ids);
        $this->assertNotContains($party->getID(), $ids);
    }

    /**
     * A four-byte character compared with an identifier column (uuid, owner) finds nothing and is
     * no error, on every database. (On MySQL the old backend's identifier columns were utf8mb3: such
     * a comparison was an SQL error, 1267, an illegal mix of collations, which it logged.)
     */
    public function testAFourByteCharacterComparedWithAnIdentifierColumnFindsNothing(): void
    {
        $db = Idno::site()->db();
        $this->captureLog();

        $this->assertFalse($db->getRecordByUUID("x\u{1F600}"));
        $this->assertFalse($db->getRecords([], ['owner' => "x\u{1F600}"], 10, 0));
        $this->assertSame([], $db->getObjects('', ['owner' => "x\u{1F600}"], [], 10, 0));
        $this->assertSame([], $this->readLog());
    }

    /**
     * A string $readGroups is one read group. OBSERVED reasoning, not runnable on the rig: the old
     * backend ran `'$in' => 'PUBLIC'` into foreach (a warning, an exception here) and found nothing.
     */
    public function testAStringReadGroupIsHonoured(): void
    {
        $db = Idno::site()->db();
        $marker = 'adapter-test-' . bin2hex(random_bytes(6));
        $status = $this->status('Read group ' . $marker, $marker, 1_700_000_000);

        $this->assertSame([$status->getUUID()], $this->uuids($db->getObjects('', ['testmarker' => $marker], [], 10, 0, 'entities', 'PUBLIC')));
        $this->assertSame([], $db->getObjects('', ['testmarker' => $marker], [], 10, 0, 'entities', 'SITE'));
    }

    /**
     * The record store reads `bypass_fulltext_search` and `boolean_search` from the legacy config
     * each time a search runs, as the old backend did. A term of more than 5 bytes uses the full-text
     * index (whole words) unless bypassed, when it is a substring match.
     */
    public function testSearchSettingsAreReadAtQueryTime(): void
    {
        $this->requireMysql('bypass_fulltext_search changes MySQL\'s full-text search only; SQLite always matches substrings.');
        $config = Idno::site()->config();
        $previous = [$config->bypass_fulltext_search, $config->boolean_search];
        $store = (new \Idno\Legacy\LegacyRecordStoreFactory(
            static fn (): Connection => Idno::container()->get('database_connection'),
            static fn (): SchemaCatalog => Idno::container()->get(SchemaCatalog::class),
            Idno::container()->get(\Idno\Config\BootConfig::class),
            new LegacyRecordDefaults(),
            new IdentityTypeMap(),
        ))->create();
        $documents = (new \ReflectionProperty(SettingsRecordStore::class, 'inner'))->getValue($store);
        $marker = 'adapter-test-' . bin2hex(random_bytes(6));
        $id = Idno::site()->db()->saveRecord('entities', ['title' => 'Seawalruses ahoy', 'testmarker' => $marker, 'access' => 'PUBLIC', 'created' => 1_700_000_000]);
        $this->assertIsString($id);
        $this->created[] = Idno::site()->db()->getRecord($id)['uuid'];
        $search = Criteria::fromLegacyArray(['$search' => ['walruses'], 'testmarker' => $marker]);
        try {
            $config->bypass_fulltext_search = false;
            $this->assertSame(0, $store->count('entities', $search), 'full text: no whole word "walruses"');
            $config->bypass_fulltext_search = true;
            $this->assertSame(1, $store->count('entities', $search), 'bypassed: substring match, same store object');

            $config->boolean_search = false;
            $this->assertFalse((new \ReflectionProperty(JsonRecordStore::class, 'booleanSearch'))->getValue($documents)());
            $config->boolean_search = true;
            $this->assertTrue((new \ReflectionProperty(JsonRecordStore::class, 'booleanSearch'))->getValue($documents)());
        } finally {
            [$config->bypass_fulltext_search, $config->boolean_search] = $previous;
        }
    }

    /**
     * What db() builds for a `database` value that is not a known name, in a fresh process: a
     * loadable backend class is built by componentFactory() as before.
     */
    public function testDbBuildsALoadableBackendClass(): void
    {
        $this->assertSame(['class' => FixtureDataBackend::class, 'family' => 'other'], $this->bootWithDatabase(FixtureDataBackend::class));
    }

    /**
     * A `database` name that is no class gets the adapter on MySQL (componentFactory() fell back
     * to the old MySQL backend, since removed).
     */
    public function testDbFallsBackToTheAdapterOnMysqlForANameThatIsNoClass(): void
    {
        $this->requireMysql('a `database` name that is no class means MySQL, so the subprocess connects to the rig\'s MySQL server.');
        $this->assertSame(['class' => RepositoryDataConcierge::class, 'family' => 'mysql'], $this->bootWithDatabase('NoSuchBackend'));
    }

    /**
     * The `database` values sites have been configured with keep working now that the old SQL backend
     * (Idno\Data\MySQL, which componentFactory() built or fell back to) is gone: every MySQL name,
     * the old backend's own class name (no class now: the fallback, MySQL), a name that is no class,
     * and a loadable plugin backend class, each booted in a fresh process. (MongoDB names: see
     * testMongoConfigStillSelectsTheMongoBackend, which needs no MongoDB server.)
     */
    public function testLegacyDriverNamesStillResolve(): void
    {
        $this->requireMysql('the MySQL names connect to the rig\'s MySQL server in a subprocess.');
        $config = Idno::site()->config();
        $rds = [
            'RDS_HOSTNAME' => (string) $config->dbhost,
            'RDS_PORT' => (string) $config->dbport,
            'RDS_USERNAME' => (string) $config->dbuser,
            'RDS_PASSWORD' => (string) $config->dbpass,
            'RDS_DB_NAME' => (string) $config->dbname,
        ];
        $adapterOnMysql = ['class' => RepositoryDataConcierge::class, 'family' => 'mysql'];
        foreach (['MySQL', 'mysql', ' MYSQL ', 'Idno\\Data\\MySQL', '\\Idno\\Data\\MySQL', 'NoSuchBackend'] as $database) {
            $this->assertSame($adapterOnMysql, $this->bootWithDatabase($database), $database);
        }
        $this->assertSame($adapterOnMysql, $this->bootWithDatabase('beanstalk-mysql', $rds), 'beanstalk-mysql');
        $this->assertSame(['class' => FixtureDataBackend::class, 'family' => 'other'], $this->bootWithDatabase(FixtureDataBackend::class));
        $this->assertFalse(class_exists('Idno\\Data\\MySQL') || class_exists('Idno\\Data\\AbstractSQL'), 'the old SQL backend is gone');
    }

    /**
     * A site configured with the old SQL backend's class name is on MySQL, served by the adapter,
     * even where a class of that name still exists (an upgrade that overwrote files and left the old
     * class file behind): ConnectionParameters counts that name as no class, so componentFactory()
     * never builds it. In a fresh process with a stand-in class Idno\Data\MySQL defined before boot.
     */
    public function testALeftBehindOldBackendClassIsNeverBuilt(): void
    {
        $this->requireMysql('the old backend\'s name means MySQL, so the subprocess connects to the rig\'s MySQL server.');
        foreach (['Idno\\Data\\MySQL', '\\Idno\\Data\\MySQL', 'idno\\data\\mysql'] as $database) {
            $this->assertSame(
                ['class' => RepositoryDataConcierge::class, 'family' => 'mysql', 'standInConstructed' => false],
                $this->bootWithDatabase($database, ['IDNO_TEST_OLD_BACKEND_STAND_IN' => '1']),
                $database,
            );
        }
    }

    /**
     * @param array<string, string> $env more environment variables for the subprocess
     *
     * @return array<string, mixed>
     */
    private function bootWithDatabase(string $database, array $env = []): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=stderr ' . escapeshellarg(__DIR__ . '/boot_db_backend.php');
        [$exit, $stdout, $stderr] = self::runProcess($command, ['IDNO_DOMAIN' => '127.0.0.1', 'IDNO_PORT' => '8089', 'IDNO_DATABASE' => $database, 'IDNO_PROXY_STRING' => '127.0.0.1:9'] + $env);
        $lines = self::lines($stdout);
        $decoded = json_decode((string) end($lines), true);
        $this->assertIsArray($decoded, "boot_db_backend.php did not print JSON (exit $exit).\nstdout: $stdout\nstderr: $stderr");

        return $decoded;
    }

    // ------------------------------------------------ entities through the repository

    public function testStaticFindersGoThroughTheRepository(): void
    {
        $repository = Idno::container()->get(EntityRepository::class);
        $marker = 'adapter-test-' . bin2hex(random_bytes(6));
        $first = $this->status('First ' . $marker, $marker, 1_700_000_000);
        $second = $this->status('Second ' . $marker, $marker, 1_700_000_060);

        $byUuid = Entity::getByUUID($first->getUUID(), false);
        $found = $repository->find(new EntityQuery([], Criteria::fromLegacyArray(['uuid' => $first->getUUID()]), 1));
        $this->assertInstanceOf(Status::class, $byUuid);
        $this->assertSame([$first->getUUID()], $this->uuids($found));
        $this->assertSame($found[0]->getUUID(), $byUuid->getUUID());

        $this->assertSame(
            $this->uuids($repository->find(new EntityQuery([Status::class], Criteria::fromLegacyArray(['testmarker' => $marker])))),
            $this->uuids(Status::get(['testmarker' => $marker])),
        );
        $this->assertSame([$second->getUUID(), $first->getUUID()], $this->uuids(Status::get(['testmarker' => $marker])));

        $slug = $first->getSlug();
        $this->assertNotEmpty($slug);
        $this->assertSame(
            $this->uuids($repository->find(new EntityQuery([], Criteria::fromLegacyArray(['slug' => $slug]), 1))),
            [Entity::getBySlug($slug)->getUUID()],
        );

        $this->assertSame(2, Entity::countFromX(Status::class, ['testmarker' => $marker]));
        $this->assertSame($repository->count(new EntityQuery([Status::class], Criteria::fromLegacyArray(['testmarker' => $marker]))), Entity::countFromX(Status::class, ['testmarker' => $marker]));
        $this->assertSame($repository->findOneByUuid($second->getUUID())?->getUUID(), Idno::site()->db()->getObject($second->getUUID())->getUUID());
    }

    /**
     * A deliberate difference: a record whose class cannot be loaded is left out of getObjects(), where the old
     * backend returned false in its place; counts still include it, as before.
     */
    public function testGetObjectsLeavesOutARecordWhoseClassCannotBeLoaded(): void
    {
        $db = Idno::site()->db();
        $marker = 'adapter-test-' . bin2hex(random_bytes(6));
        $this->status('Loadable ' . $marker, $marker, 1_700_000_000);
        $id = $db->saveRecord('entities', ['entity_subtype' => 'Tests\\Kernel\\NoSuchEntityClass', 'access' => 'PUBLIC', 'testmarker' => $marker, 'created' => 1_700_000_100]);
        $this->assertIsString($id);
        $this->created[] = $db->getRecord($id)['uuid'];

        $objects = $db->getObjects('', ['testmarker' => $marker]);
        $this->assertCount(1, $objects);
        $this->assertNotContains(false, $objects, 'no false entries');
        $this->assertSame(2, $db->countObjects('', ['testmarker' => $marker]));
        $this->assertSame([], array_values(array_filter(Entity::getFromX('', ['testmarker' => $marker]), static fn ($e): bool => !$e instanceof Status)));
    }

    // ------------------------------------------------ queries the old backend could not run

    /**
     * A search array the parser rejects matches nothing and is logged once as a warning
     * that names the method and the query's shape, never its values. The one rejected shape with a
     * plausible dynamic source: `$not => [$in => []]`, which the old backend ran as `!= 'Array'`
     * (in production: the records that have the field).
     */
    public function testAnUnreadableSearchMatchesNothingAndIsLogged(): void
    {
        $db = Idno::site()->db();
        $marker = 'adapter-test-' . bin2hex(random_bytes(6));
        $this->status('Has the field ' . $marker, $marker, 1_700_000_000);
        $search = ['testmarker' => ['$not' => ['$in' => []]], 'secret-value-key' => 'do-not-log-me'];

        $this->captureLog();
        $this->assertSame([], $db->getObjects('', $search));
        $this->assertFalse($db->countObjects('', $search));
        $this->assertFalse($db->getRecords([], $search, 10, 0));
        $this->assertFalse($db->countRecords($search));
        $log = $this->readLog();

        $this->assertCount(4, $log, implode("\n", $log));
        foreach (['getObjects', 'countObjects', 'getRecords', 'countRecords'] as $i => $method) {
            $this->assertStringContainsString('warning - ' . $method . '(): the search could not be read, so nothing matches it: {testmarker: {$not: {$in: []}}, secret-value-key: _}', $log[$i]);
            $this->assertStringNotContainsString('do-not-log-me', $log[$i]);
        }
    }

    public function testTheLoggedShapeHasNoValuesAndIsBounded(): void
    {
        $this->assertSame('{a: _, b: {$in: […]}, c: {$not: {$in: []}}, $search: […]}', RepositoryDataConcierge::shape(['a' => 'x', 'b' => ['$in' => ['secret']], 'c' => ['$not' => ['$in' => []]], '$search' => ['term']]));
        $this->assertSame('{a?b: _}', RepositoryDataConcierge::shape(["a\nb" => 1]));
        $long = RepositoryDataConcierge::shape(array_fill_keys(array_map(static fn (int $i): string => 'field' . $i, range(1, 100)), 'v'));
        $this->assertSame(200, strlen($long));
        // A multi-byte key across the limit is not cut in half.
        $this->assertSame('{' . str_repeat('a', 197) . 'é', RepositoryDataConcierge::shape([str_repeat('a', 197) . 'éé' => 1]));
        $cut = RepositoryDataConcierge::shape([str_repeat('a', 198) . 'éé' => 1]);
        $this->assertSame('{' . str_repeat('a', 198), $cut, 'a character that does not fit whole is left out');
        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'));
    }

    /**
     * A deliberate difference: a query the old backend sent to MySQL as invalid SQL (here the MongoDB list form of
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
        $id = Idno::site()->db()->generateID();
        $this->assertFalse(Idno::site()->db()->saveRecord('entities', ['_id' => $id, 'title' => "\xB1\x31 not UTF-8"]));
        $this->assertFalse(Idno::site()->db()->getRecord($id), 'nothing was stored');
        $log = $this->readLog();
        $this->assertCount(1, $log);
        $this->assertStringContainsString('error - Malformed UTF-8 characters, possibly incorrectly encoded', $log[0]);
    }

    // ------------------------------------------------ connection, schema and maintenance

    /**
     * The schema is the schema migrations', created by the `idno:upgrade` command: getVersions() is
     * one object, label `schema`, whose value is the newest migration executed (the old backend's
     * `versions` row held the date of its last upgrade file), and connecting (init(),
     * checkAndUpgradeSchema()) creates or changes nothing.
     */
    public function testTheVersionIsTheNewestSchemaMigrationAndNothingIsUpgradedOnConnect(): void
    {
        /** @var Connection $connection */
        $connection = Idno::container()->get('database_connection');
        $latest = (new SchemaMigrator($connection))->latest();
        $this->assertSame('20261115000001', $latest, 'the rig\'s database has every migration (the newest is the indexed-fields marker)');
        $this->assertEquals([(object) ['label' => 'schema', 'value' => $latest]], Idno::site()->db()->getVersions());

        $empty = PrivateDatabase::sqliteMemory(migrate: false);
        $adapter = $this->adapterOn(PrivateDatabase::recordStore($empty), new FixedAccessContext(), new RecordingLogger(), 'sqlite', $empty);
        $adapter->init();
        $adapter->checkAndUpgradeSchema();
        $this->assertSame([], (new SchemaCatalog($empty))->tables(), 'the adapter creates no table');
        $this->assertEquals([(object) ['label' => 'schema', 'value' => '0']], $adapter->getVersions());
        $empty->close();
    }

    public function testMongoConfigStillSelectsTheMongoBackend(): void
    {
        foreach (['mongo', 'MongoDB', ' mongodb '] as $database) {
            $this->assertSame('mongo', ConnectionParameters::driverFamily(['database' => $database]), $database);
            $this->assertNull(ConnectionParameters::backendClass(['database' => $database]), $database);
        }
        // LegacyServiceFactory::db() returns `new \Idno\Data\Mongo()` for these names before anything
        // else (the case is unchanged from before the adapter replaced the old backend).
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/Idno/Legacy/LegacyServiceFactory.php');
        $this->assertMatchesRegularExpression("/switch \\(trim\\(strtolower\\(\\\$config->database\\)\\)\\) \\{\\s*case 'mongo':\\s*case 'mongodb':\\s*return new \\\\Idno\\\\Data\\\\Mongo\\(\\);/", $source);
    }

    public function testAnUnknownBackendNameIsMysqlAndALoadableClassIsBuiltAsBefore(): void
    {
        // The record store and the connection agree with db().
        $this->assertSame('mysql', ConnectionParameters::driverFamily(['database' => 'NoSuchBackend']));
        $factory = new \Idno\Legacy\LegacyRecordStoreFactory(
            static fn (): Connection => Idno::container()->get('database_connection'),
            static fn (): SchemaCatalog => Idno::container()->get(SchemaCatalog::class),
            new \Idno\Config\BootConfig(['database' => 'NoSuchBackend'], false),
            new LegacyRecordDefaults(),
            new IdentityTypeMap(),
        );
        $this->assertInstanceOf(SettingsRecordStore::class, $factory->create());
        $this->assertSame('other', ConnectionParameters::driverFamily(['database' => \Tests\Unit\Storage\PluginBackendFixture::class]));
    }

    /**
     * optimize() refreshes the statistics of the tables of the schema migrations (MySQL's and
     * MariaDB's `analyze table`, SQLite's `pragma optimize`; the statements are pinned in
     * Tests/Unit/Legacy/LegacySqlMaintenanceTest) and returns false, as before; exportRecords() exports a page of a
     * collection's table as `insert` statements.
     */
    public function testOptimizeAndExportRunOnTheSharedConnection(): void
    {
        $db = Idno::site()->db();
        $this->trackExportTempFiles();
        $marker = 'adapter-test-' . bin2hex(random_bytes(6));
        $status = $this->status('Export ' . $marker, $marker, 1_700_000_000);
        $this->captureLog();
        $this->assertFalse($db->optimize(), 'optimize() returns false, as before');
        $total = $db->countRecords([]);

        $all = '';
        for ($offset = 0; $offset < $total + 10 && ($page = $db->exportRecords('entities', 10, $offset)); $offset += 10) {
            $all .= $page;
        }
        $this->assertStringContainsString('insert into idno_entities (`id`,`uuid`,`type`,`owner`,`created`,`publish_status`,`data`,`search`) values (', $all);
        $this->assertStringContainsString($db->getClient()->quote($status->getUUID()), $all);
        $this->assertSame('', $db->exportRecords('entities', 10, $total + 10), 'an empty page is an empty string');
        $this->assertStringStartsWith('insert into idno_settings (`name`,`value`,`position`) values (', (string) $db->exportRecords('config', 10, 0));
        $this->assertSame([], $this->readLog(), 'nothing failed');
        $this->assertFalse($db->exportRecords('sessions'), 'not a collection');
    }

    public function testReconnectIfNeededKeepsALiveConnection(): void
    {
        $db = Idno::site()->db();
        $client = $db->getClient();
        $db->reconnectIfNeeded();
        $this->assertSame($client, $db->getClient());
    }

    /**
     * After the connection is lost, the record store's next query fails (a database error: logged,
     * the legacy failure value) and DBAL closes the connection; the query after that runs on a new
     * connection, and getClient() is that connection's PDO, not the dead one. The session handler,
     * built on the PDO it was given, is not recovered. On a connection of the test's own (killed
     * with KILL CONNECTION_ID(), which ends only that connection), never the site's.
     */
    public function testGetClientFollowsTheConnectionAfterItIsLost(): void
    {
        $this->requireMysql('it ends a MySQL connection with KILL CONNECTION_ID().');
        /** @var Connection $shared */
        $shared = Idno::container()->get('database_connection');
        $connection = new Connection($shared->getParams(), $shared->getDriver(), $shared->getConfiguration());
        $store = PrivateDatabase::recordStore($connection, new LegacyRecordDefaults());
        $logger = new RecordingLogger();
        $db = $this->adapterOn($store, new FixedAccessContext(), $logger, 'mysql', $connection);

        $dead = $db->getClient();
        $this->assertNotSame($shared->getNativeConnection(), $dead, 'a connection of the test\'s own');
        $this->assertIsInt($db->countRecords([]));
        try {
            $dead->query('KILL CONNECTION_ID()');
        } catch (\PDOException) {
            // MySQL interrupts the statement that ends its own connection.
        }

        $this->assertFalse($db->countRecords([]), 'the first query on the lost connection fails');
        $this->assertCount(1, $logger->records);
        $this->assertIsInt($db->countRecords([]), 'the next one runs on a new connection');
        $client = $db->getClient();
        $this->assertNotSame($dead, $client, 'getClient() is not the dead PDO');
        $this->assertSame([1], $client->query('SELECT 1')->fetch(\PDO::FETCH_NUM));
        $connection->close();
    }

    /**
     * The async queue's query shapes through the adapter: enqueue (save), the pending lookup
     * (`complete $not $in [true]`), dispatch (marks complete and saves), the garbage collector
     * (`completedTs $lt`, `complete` true).
     */
    public function testTheAsynchronousQueueRunsThroughTheAdapter(): void
    {
        $queue = new \Idno\Core\AsynchronousQueue();
        $name = 'adapterqueue' . bin2hex(random_bytes(4));
        $event = 'adapter/event/' . bin2hex(random_bytes(4));
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

    /**
     * SQLite cannot lock a session row (PdoSessionHandler refuses advisory locks there, and an
     * unlocked handler lets parallel requests of one session overwrite each other), so an SQLite
     * site's handleSession() installs nothing: Idno\Core\Session falls back to PHP's file sessions,
     * which PHP locks. sessionHandler() refuses to build one.
     */
    public function testAnSqliteSiteInstallsNoSessionHandler(): void
    {
        $this->requireSqlite('MySQL and MariaDB sites keep their sessions in the database.');
        $adapter = Idno::site()->db();
        $this->assertInstanceOf(RepositoryDataConcierge::class, $adapter);

        $this->assertNull($adapter->handleSession());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SQLite');
        $adapter->sessionHandler();
    }

    public function testAnSqliteSitesSessionsAreFilesInAFreshProcess(): void
    {
        $this->requireSqlite('MySQL and MariaDB sites keep their sessions in the database.');
        $result = $this->bootWithSessionSettings([], bin2hex(random_bytes(16)));

        $this->assertSame('files', $result['save_handler']);
        $this->assertFalse($result['row'], 'nothing is written to the `idno_sessions` table');
    }


    public function testSessionRowIsWrittenThroughTheSharedConnection(): void
    {
        $this->requireMysql('an SQLite site\'s sessions are files, not rows (testAnSqliteSiteInstallsNoSessionHandler).');
        $response = $this->get('');
        $this->assertSame(200, $response['status']);
        $sessionId = $response['cookies'][Idno::site()->config()->sessionname] ?? null;
        $this->assertNotEmpty($sessionId, 'the page sets a session cookie');

        $statement = Idno::site()->db()->getClient()->prepare('select session_value, session_lifetime, session_time from idno_sessions where session_id = ?');
        $statement->execute([$sessionId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $this->deleteSession($sessionId);

        $this->assertIsArray($row, 'a session row was written for the cookie');
        $this->assertStringStartsWith('secure|b:0;', $row['session_value']);
        $this->assertGreaterThan((int) $row['session_time'], (int) $row['session_lifetime']);
    }

    /**
     * A session row in `idno_sessions`, in the format the old backend's handler wrote to its
     * `session` table (Idno\Data\MySQL::handleSession(), since removed: the same handler and options),
     * is read by the adapter's handler when the site serves the next request with that cookie, and
     * written back in the same format. (Rows of the old `session` table are not read: users log in
     * again after the upgrade.)
     */
    public function testASessionRowInTheOldBackendsFormatIsReadByTheAdapter(): void
    {
        $this->requireMysql('an SQLite site\'s sessions are files, which no old backend wrote rows for.');
        $user = $this->user();
        $this->assertSame(403, $this->get('account/settings/')['status'], 'anonymous visitors have no account settings');

        $sessionId = $this->legacySession($user->getUUID());
        try {
            $response = $this->get('account/settings/', [Idno::site()->config()->sessionname => $sessionId]);
            $this->assertSame(200, $response['status'], 'the session the old backend wrote logs the user on');
            $this->assertStringContainsString($user->getHandle(), $response['body']);

            // The adapter's handler read the row and wrote it back, in the same format.
            $statement = Idno::site()->db()->getClient()->prepare('select session_value from idno_sessions where session_id = ?');
            $statement->execute([$sessionId]);
            $this->assertStringStartsWith('user_uuid|s:' . strlen($user->getUUID()) . ':"' . $user->getUUID() . '";secure|b:0;', (string) $statement->fetchColumn());
        } finally {
            $this->deleteSession($sessionId);
        }
    }

    /**
     * A site whose `sessions_storage` names the removed old backend (Idno\Data\MySQL, with
     * in any case, with or without a leading backslash) keeps its database sessions: the db() service's handler (the
     * `idno_sessions` table, the same handler and options) on the shared connection, even with
     * `sessions_database` off, and a session row in the old backend's format is read. Before the old backend
     * was removed the setting built a second connection (Idno\Data\MySQL) whose handler replaced the adapter's; any
     * other `sessions_storage` value, and `sessions_database` alone, behave as before. Each case boots
     * in a fresh process.
     */
    public function testASessionsStorageSettingNamingTheOldBackendKeepsDatabaseSessions(): void
    {
        $mysql = ConnectionParameters::driverFamily(Idno::container()->get(\Idno\Config\BootConfig::class)->all()) === 'mysql';
        $uuid = $this->user()->getUUID();
        // An SQLite site's sessions are files whatever these settings say: its db() installs no handler.
        $database = $mysql ? ['save_handler' => 'user', 'shared_lock' => true] : ['save_handler' => 'files', 'shared_lock' => null];
        $files = ['save_handler' => 'files', 'shared_lock' => null];
        $cases = [
            'the old backend, sessions_database off' => [['IDNO_SESSIONS_DATABASE' => '0', 'IDNO_SESSIONS_STORAGE' => 'Idno\\Data\\MySQL'], $database],
            'the old backend with a leading backslash' => [['IDNO_SESSIONS_DATABASE' => '0', 'IDNO_SESSIONS_STORAGE' => '\\Idno\\Data\\MySQL'], $database],
            // PHP's class names ignore case, so the old backend was built for these too.
            'the old backend, mis-cased' => [['IDNO_SESSIONS_DATABASE' => '0', 'IDNO_SESSIONS_STORAGE' => 'idno\\data\\MYSQL'], $database],
            'the old backend, mis-cased with a leading backslash' => [['IDNO_SESSIONS_DATABASE' => '0', 'IDNO_SESSIONS_STORAGE' => '\\IDNO\\Data\\mysql'], $database],
            'the old backend, sessions_database on' => [['IDNO_SESSIONS_STORAGE' => 'Idno\\Data\\MySQL'], $database],
            'sessions_database on, nothing else' => [[], $database],
            'sessions_database off, nothing else' => [['IDNO_SESSIONS_DATABASE' => '0'], $files],
            'sessions_database off, a class that does not exist' => [['IDNO_SESSIONS_DATABASE' => '0', 'IDNO_SESSIONS_STORAGE' => 'No\\Such\\Handler'], $files],
            'sessions_database off, a class that is no session storage' => [['IDNO_SESSIONS_DATABASE' => '0', 'IDNO_SESSIONS_STORAGE' => 'stdClass'], $files],
        ];
        foreach ($cases as $case => [$env, $expected]) {
            $sessionId = $this->legacySession($uuid);
            $sessions = [$sessionId];
            try {
                $result = $this->bootWithSessionSettings($env, $sessionId);
                $sessions[] = (string) $result['session_id'];
                $this->assertSame($expected['save_handler'], $result['save_handler'], $case);
                $this->assertSame($expected['shared_lock'], $result['shared_lock'], $case . ': the advisory lock is the shared connection\'s');
                if ($expected['save_handler'] === 'user') {
                    $this->assertSame($sessionId, $result['session_id'], $case);
                    $this->assertSame($uuid, $result['read'], $case . ': the row in the old backend\'s format is read');
                    $this->assertTrue($result['row'], $case . ': the session is written to the `idno_sessions` table');
                } else {
                    $this->assertNull($result['read'], $case);
                    $this->assertFalse($result['row'], $case . ': nothing is written to the `idno_sessions` table');
                }
            } finally {
                foreach (array_unique($sessions) as $id) {
                    $this->deleteSession($id);
                }
            }
        }
    }

    /**
     * Runs Tests/Kernel/boot_session_storage.php with the given environment and session cookie.
     *
     * @param array<string, string> $env
     *
     * @return array<string, mixed>
     */
    private function bootWithSessionSettings(array $env, string $sessionId): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=stderr '
            . escapeshellarg(__DIR__ . '/boot_session_storage.php') . ' '
            . escapeshellarg((string) Idno::site()->config()->sessionname) . ' ' . escapeshellarg($sessionId);
        [$exit, $stdout, $stderr] = self::runProcess($command, ['IDNO_DOMAIN' => '127.0.0.1', 'IDNO_PORT' => '8089', 'IDNO_PROXY_STRING' => '127.0.0.1:9'] + $env);
        $lines = self::lines($stdout);
        $decoded = json_decode((string) end($lines), true);
        $this->assertIsArray($decoded, "boot_session_storage.php did not print JSON (exit $exit).\nstdout: $stdout\nstderr: $stderr");

        return $decoded;
    }

    /**
     * A visitor who logs in through the login form stays logged in on the following requests: the
     * session the adapter's handler wrote is read back (a database row on MySQL and MariaDB, a file on SQLite).
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
            if (ConnectionParameters::driverFamily(Idno::container()->get(\Idno\Config\BootConfig::class)->all()) === 'mysql') {
                $statement = Idno::site()->db()->getClient()->prepare('select session_value from idno_sessions where session_id = ?');
                $statement->execute([$cookies[$name]]);
                $this->assertStringContainsString('user_uuid|s:' . strlen($user->getUUID()) . ':"' . $user->getUUID() . '"', (string) $statement->fetchColumn());
            }
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
     * index, substrings on SQLite; a whole word is found on both.
     */
    public function testTheSearchPageFindsAPost(): void
    {
        $word = 'seawalrus' . bin2hex(random_bytes(5));
        $status = $this->status('Looking for the ' . $word . ' today', 'adapter-test-' . bin2hex(random_bytes(6)), time());

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
     * OBSERVED on the commit before the adapter replaced the old backend (MySQL): the console prints the error page on stdout, writes
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
     * The adapter over an SQLite database file of its own with the schema migrations run: records,
     * entities, counts, deletion, no session handler, the versions and maintenance.
     */
    public function testTheAdapterWorksOnSqlite(): void
    {
        $path = sys_get_temp_dir() . '/idno-adapter-test-' . bin2hex(random_bytes(6)) . '.db';
        array_push($this->files, $path, $path . '-wal', $path . '-shm');
        $this->trackExportTempFiles();
        $connection = PrivateDatabase::sqliteFile($path);
        $store = PrivateDatabase::recordStore($connection, new LegacyRecordDefaults(), Idno::container()->get(\Idno\Storage\TypeMap::class), static fn (string $text): string => (string) Idno::site()->language()->uncurlQuotes($text));
        $repository = new EntityRepository($store, new LegacyAccessContext(), new NullLogger());
        $logger = new RecordingLogger();
        $adapter = new RepositoryDataConcierge('sqlite', $connection, static fn (): RecordStore => $store, static fn (): EntityRepository => $repository, static fn (): \Psr\Log\LoggerInterface => $logger, $path);

        $this->assertInstanceOf(\PDO::class, $adapter->getClient());
        $this->assertSame($connection->getNativeConnection(), $adapter->getClient());
        $this->assertSame($path, $adapter->getDatabase());
        $this->assertEquals([(object) ['label' => 'schema', 'value' => '20261115000001']], $adapter->getVersions());

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

        // Sessions: SQLite cannot lock them, so the adapter installs no handler (they are files)
        $this->assertNull($adapter->handleSession());
        try {
            $adapter->sessionHandler();
            $this->fail('An SQLite adapter builds no session handler.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('SQLite', $e->getMessage());
        }

        // Maintenance: optimize() runs SQLite's `pragma optimize` and returns false, as before; export works.
        $this->captureLog();
        $this->assertFalse($adapter->optimize());
        $this->assertSame([], $this->readLog());
        $this->assertStringContainsString('insert into idno_entities (`id`,`uuid`,', (string) $adapter->exportRecords('entities', 10, 0));
        $this->assertSame([], $logger->records, 'nothing was logged through the site logger');

        $connection->close();
    }

    // ------------------------------------------------ which failures become a failure value

    /**
     * Each method's call on a store that fails, and what the old backend returned when its
     * database statement failed.
     *
     * @return array<string, array{\Closure(RepositoryDataConcierge): mixed, mixed}>
     */
    public static function failingCalls(): array
    {
        return [
            'saveRecord' => [static fn (RepositoryDataConcierge $db): mixed => $db->saveRecord('entities', ['title' => 'x', 'created' => 1_700_000_000]), false],
            'saveObject' => [static function (RepositoryDataConcierge $db): mixed {
                $status = new Status();
                $status->body = 'x';
                $status->created = 1_700_000_000;

                return $db->saveObject($status);
            }, false],
            'getRecords' => [static fn (RepositoryDataConcierge $db): mixed => $db->getRecords([], ['slug' => 'x'], 10, 0), false],
            'countRecords' => [static fn (RepositoryDataConcierge $db): mixed => $db->countRecords(['slug' => 'x']), false],
            'getRecord' => [static fn (RepositoryDataConcierge $db): mixed => $db->getRecord('an-id'), false],
            'getRecordByUUID' => [static fn (RepositoryDataConcierge $db): mixed => $db->getRecordByUUID('http://example.test/view/an-id'), false],
            'deleteRecord' => [static fn (RepositoryDataConcierge $db): mixed => $db->deleteRecord('an-id'), false],
            'deleteAllRecords' => [static fn (RepositoryDataConcierge $db): mixed => $db->deleteAllRecords('entities'), false],
            'getObjects' => [static fn (RepositoryDataConcierge $db): mixed => $db->getObjects('', ['slug' => 'x']), []],
            'countObjects' => [static fn (RepositoryDataConcierge $db): mixed => $db->countObjects('', ['slug' => 'x']), false],
            'getObject' => [static fn (RepositoryDataConcierge $db): mixed => $db->getObject('http://example.test/view/an-id'), false],
        ];
    }

    /**
     * A failure that is not the database's (here a \LogicException from the store) propagates out
     * of every method, as it did out of the old backend, whose `try` held only its statements; it
     * is not logged.
     *
     * @dataProvider failingCalls
     */
    public function testAFailureThatIsNotTheDatabasesPropagates(\Closure $call): void
    {
        $logger = new RecordingLogger();
        $db = $this->adapterOn(new FailingStore(new \LogicException('not the database')), new FixedAccessContext(), $logger);

        $thrown = self::thrown(static fn () => $call($db));
        $this->assertInstanceOf(\LogicException::class, $thrown, 'the \LogicException propagates');
        $this->assertSame('not the database', $thrown->getMessage());
        $this->assertSame([], $logger->records);
    }

    /**
     * getAnyRecord() too: only a database failure is turned into false (and thrown when there is no
     * session, as before).
     */
    public function testAFailureThatIsNotTheDatabasesPropagatesFromGetAnyRecord(): void
    {
        $db = $this->adapterOn(new FailingStore(new \LogicException('not the database')), new FixedAccessContext(), new RecordingLogger());

        $this->expectException(\LogicException::class);
        $db->getAnyRecord();
    }

    /**
     * A failure of the database statement (a DBAL exception, here wrapping the driver's
     * PDOException) gives the old backend's failure value and one error line: the PDOException's
     * message, as the old backend logged it.
     *
     * @dataProvider failingCalls
     */
    public function testADatabaseFailureGivesTheOldFailureValueAndOneLogLine(\Closure $call, mixed $failureValue): void
    {
        $dbalException = self::databaseException();
        $logger = new RecordingLogger();
        $db = $this->adapterOn(new FailingStore($dbalException), new FixedAccessContext(), $logger);

        $this->assertSame($failureValue, $call($db));
        $this->assertCount(1, $logger->records);
        $this->assertSame(['error', self::pdoException($dbalException)->getMessage()], $logger->records[0]);
    }

    /**
     * The same for a bare \PDOException.
     *
     * @dataProvider failingCalls
     */
    public function testABarePdoExceptionGivesTheOldFailureValueAndOneLogLine(\Closure $call, mixed $failureValue): void
    {
        $logger = new RecordingLogger();
        $db = $this->adapterOn(new FailingStore(new \PDOException('SQLSTATE[HY000]: General error: a test failure')), new FixedAccessContext(), $logger);

        $this->assertSame($failureValue, $call($db));
        $this->assertSame([['error', 'SQLSTATE[HY000]: General error: a test failure']], $logger->records);
    }

    /**
     * The read-group lookup (the session and its `permission:read:ids` event) sat outside the old
     * backend's `try`: its failure propagates from getObjects() and countObjects(), it is not
     * "nothing found".
     */
    public function testAFailingReadGroupLookupPropagates(): void
    {
        $access = new class () implements AccessContext {
            public function currentUserUuid(): ?string
            {
                return null;
            }

            public function isAdmin(): bool
            {
                return false;
            }

            public function readAccessGroupIds(): array
            {
                throw new \RuntimeException('the read groups could not be found');
            }

            public function ignoreAccess(): bool
            {
                return false;
            }
        };
        $connection = PrivateDatabase::sqliteMemory();
        $logger = new RecordingLogger();
        $db = $this->adapterOn(self::sqliteStore($connection), $access, $logger);

        foreach (['getObjects' => static fn () => $db->getObjects(), 'countObjects' => static fn () => $db->countObjects()] as $method => $call) {
            $thrown = self::thrown($call);
            $this->assertInstanceOf(\RuntimeException::class, $thrown, $method . '(): the \RuntimeException propagates');
            $this->assertSame('the read groups could not be found', $thrown->getMessage(), $method);
        }
        $this->assertSame([], $logger->records);
        $connection->close();
    }

    /**
     * Hydration (DataConcierge::rowToEntity()) sat outside the old backend's `try`, and caught only
     * an \Error: an \Exception from an entity's loadFromArray() propagates from getObject() and
     * getObjects().
     */
    public function testAnEntityThatFailsToLoadPropagates(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $store = self::sqliteStore($connection);
        $id = $store->save('entities', ['entity_subtype' => FailingToLoadEntity::class, 'access' => 'PUBLIC', 'created' => 1_700_000_000]);
        $this->assertIsString($id);
        $uuid = $store->findById('entities', $id)['uuid'];
        $logger = new RecordingLogger();
        $db = $this->adapterOn($store, new FixedAccessContext(), $logger);

        foreach (['getObject' => static fn () => $db->getObject($uuid), 'getObjects' => static fn () => $db->getObjects()] as $method => $call) {
            $thrown = self::thrown($call);
            $this->assertInstanceOf(\RuntimeException::class, $thrown, $method . '(): the \RuntimeException propagates');
            $this->assertSame('this entity cannot be loaded', $thrown->getMessage(), $method);
        }
        $this->assertSame([], $logger->records);
        $connection->close();
    }

    /**
     * A record RecordIndex cannot index (`created` that is not a timestamp) is refused: false, and
     * the reason logged as an error.
     */
    public function testARecordThatCannotBeIndexedIsRefusedAndLogged(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $logger = new RecordingLogger();
        $db = $this->adapterOn(self::sqliteStore($connection), new FixedAccessContext(), $logger);

        $this->assertFalse($db->saveRecord('entities', ['title' => 'x', 'created' => 'yesterday']));
        $this->assertSame([['error', '`created` must be a timestamp, string given.']], $logger->records);
        $this->assertSame(0, (int) $connection->fetchOne('select count(*) from idno_entities'));
        $connection->close();
    }

    // ------------------------------------------------ helpers

    private function adapterOn(RecordStore $store, AccessContext $access, RecordingLogger $logger, string $family = 'sqlite', ?Connection $connection = null): RepositoryDataConcierge
    {
        $repository = new EntityRepository($store, $access, new NullLogger());

        return new RepositoryDataConcierge($family, $connection ?? PrivateDatabase::sqliteMemory(migrate: false), static fn (): RecordStore => $store, static fn (): EntityRepository => $repository, static fn (): \Psr\Log\LoggerInterface => $logger, null);
    }

    private static function sqliteStore(Connection $connection): RecordStore
    {
        return PrivateDatabase::recordStore($connection);
    }

    /**
     * A real DBAL exception from a failing statement, wrapping the driver's PDOException.
     */
    private static function databaseException(): \Doctrine\DBAL\Exception\DriverException
    {
        $connection = PrivateDatabase::sqliteMemory(migrate: false);
        try {
            $connection->executeQuery('select * from no_such_table');
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            $connection->close();
            self::pdoException($e);

            return $e;
        }
        self::fail('the statement fails');
    }

    /**
     * What the call throws, or null.
     */
    private static function thrown(\Closure $call): ?\Throwable
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    /**
     * The driver's PDOException that the DBAL exception wraps (somewhere in its chain).
     */
    private static function pdoException(\Throwable $e): \PDOException
    {
        for ($previous = $e; $previous !== null; $previous = $previous->getPrevious()) {
            if ($previous instanceof \PDOException) {
                return $previous;
            }
        }
        self::fail('a PDOException in the chain of ' . get_class($e));
    }

    /**
     * Save a record with the test marker to the entities collection, and remember to remove it.
     *
     * @param array<string, mixed> $fields
     */
    private function saveTestRecord(array $fields): string
    {
        $db = Idno::site()->db();
        $id = $db->saveRecord('entities', $fields + ['testmarker' => 'adapter-test-' . bin2hex(random_bytes(6)), 'access' => 'PUBLIC', 'created' => 1_700_000_000]);
        $this->assertIsString($id);
        $this->created[] = $db->getRecord($id)['uuid'];

        return $id;
    }

    /**
     * Save a Text entry in the given MySQL SQL mode (restored afterwards; nothing on SQLite), and
     * remember to remove it.
     */
    private function saveEntry(string $sqlMode, string $title, string $body, string $tags): \IdnoPlugins\Text\Entry
    {
        $client = Idno::site()->db()->getClient();
        $mysql = $client->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $previous = $mysql ? (string) $client->query('select @@session.sql_mode')->fetchColumn() : '';
        try {
            if ($mysql) {
                $client->exec('set session sql_mode = ' . $client->quote($sqlMode));
            }
            $entry = new \IdnoPlugins\Text\Entry();
            $entry->setOwner($this->user());
            $entry->setTitle($title);
            $entry->body = $body;
            $entry->tags = $tags;
            $entry->setAccess('PUBLIC');
            $this->assertNotFalse($entry->save(true));
        } finally {
            if ($mysql) {
                $client->exec('set session sql_mode = ' . $client->quote($previous));
            }
        }
        $this->created[] = $entry->getUUID();
        $this->assertNotEmpty($entry->getID());

        return $entry;
    }

    private function status(string $body, string $marker, int $created): Status
    {
        $status = new Status();
        $status->setOwner($this->user());
        $status->body = $body;
        $status->testmarker = $marker;
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
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'idno-adapter-test-log');
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
        Idno::site()->db()->getClient()->prepare('delete from idno_sessions where session_id = ?')->execute([$sessionId]);
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
     * Writes a session for the user to `idno_sessions` as the old backend's handler wrote it to its
     * `session` table, and returns its id. The row is the one Idno\Data\MySQL::handleSession() (a
     * PdoSessionHandler with advisory locks, the handler the adapter installs) wrote for a
     * session holding only `user_uuid`, observed on the commit before its removal: a 32-character
     * hexadecimal id, the PHP-serialised session (`user_uuid|s:<length>:"<uuid>";`), the time of the
     * write in `session_time` and that time plus the session lifetime (604800 seconds on the rig) in
     * `session_lifetime`.
     */
    private function legacySession(string $userUuid): string
    {
        $sessionId = bin2hex(random_bytes(16));
        $time = time();
        $statement = Idno::site()->db()->getClient()->prepare('insert into idno_sessions (session_id, session_value, session_lifetime, session_time) values (?, ?, ?, ?)');
        $statement->execute([$sessionId, 'user_uuid|s:' . strlen($userUuid) . ':"' . $userUuid . '";', $time + 604800, $time]);

        return $sessionId;
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
            'mysql:host=127.0.0.1;dbname=' . $config->dbname . ';charset=utf8mb4;port=' . $port,
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

/**
 * A RecordStore every method of which throws the given exception.
 */
final class FailingStore implements RecordStore
{
    public function __construct(private readonly \Throwable $failure)
    {
    }

    public function save(string $collection, array $record): string|false
    {
        throw $this->failure;
    }

    public function findById(string $collection, string $id): ?array
    {
        throw $this->failure;
    }

    public function findByUuid(string $collection, string $uuid): ?array
    {
        throw $this->failure;
    }

    public function findLatest(string $collection): ?array
    {
        throw $this->failure;
    }

    public function find(string $collection, Criteria $criteria, int $limit, int $offset): array
    {
        throw $this->failure;
    }

    public function count(string $collection, Criteria $criteria): int
    {
        throw $this->failure;
    }

    public function delete(string $collection, string $id): bool
    {
        throw $this->failure;
    }

    public function deleteAll(string $collection): bool
    {
        throw $this->failure;
    }
}

/**
 * An entity whose loadFromArray() throws an \Exception (not an \Error).
 */
final class FailingToLoadEntity extends Entity
{
    function loadFromArray($array)
    {
        throw new \RuntimeException('this entity cannot be loaded');
    }
}
