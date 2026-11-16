<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Connection;
use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Kernel;
use Idno\Legacy\LegacyAccessContext;
use Idno\Legacy\LegacyRecordDefaults;
use Idno\Legacy\LegacyRecordStoreFactory;
use Idno\Storage\AccessContext;
use Idno\Storage\AttributeTypeMap;
use Idno\Storage\DialectFactory;
use Idno\Storage\EntityRepository;
use Idno\Storage\IdentityTypeMap;
use Idno\Storage\IndexedFields;
use Idno\Storage\JsonRecordStore;
use Idno\Storage\Platform\MySqlDialect;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\RecordStore;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SettingsRecordStore;
use Idno\Storage\TypeMap;

/**
 * The storage services: RecordStore (a SettingsRecordStore over a JsonRecordStore, built by
 * LegacyRecordStoreFactory), AccessContext
 * (LegacyAccessContext) and EntityRepository. They resolve on the rig; the container compiles and
 * the kernel boots on any site's database without building them; LegacyAccessContext reads the
 * legacy session and ignore-access flag at call time.
 */
final class StorageServicesTest extends \Tests\IdnoTestCase
{
    private ?Kernel $kernel = null;

    private ?string $cacheDir = null;

    /** @var array<string, mixed> $_SERVER entries this test changed, with their previous values (null: unset) */
    private array $serverBackup = [];

    /** @var \Idno\Entities\User|false */
    private $previousUser = false;

    private bool $previousIgnoreAccess = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousUser = Idno::site()->session()->currentUser();
        $this->previousIgnoreAccess = (bool) Idno::site()->db()->getIgnoreAccess();
    }

    protected function tearDown(): void
    {
        Idno::site()->db()->setIgnoreAccess($this->previousIgnoreAccess);
        $this->swapUser($this->previousUser ?: false);
        $this->kernel?->shutdown();
        $this->kernel = null;
        if ($this->cacheDir !== null) {
            self::removeDir($this->cacheDir);
            $this->cacheDir = null;
        }
        foreach ($this->serverBackup as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        $this->serverBackup = [];
        parent::tearDown();
    }

    private function setServer(string $key, ?string $value): void
    {
        if (!array_key_exists($key, $this->serverBackup)) {
            $this->serverBackup[$key] = $_SERVER[$key] ?? null;
        }
        if ($value === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $value;
        }
    }

    private static function removeDir(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeDir($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    private static function property(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }

    // ------------------------------------------------ on the rig

    public function testTheServicesResolveOnTheSite(): void
    {
        $family = \Idno\Storage\ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all());
        $dialects = ['mysql' => MySqlDialect::class, 'sqlite' => SqliteDialect::class];
        if (!isset($dialects[$family])) {
            self::markTestSkipped('The site\'s database is not SQL (MySQL or SQLite).');
        }
        $container = Idno::container();

        $store = $container->get(RecordStore::class);
        $this->assertInstanceOf(SettingsRecordStore::class, $store);
        $documents = self::property($store, 'inner');
        $this->assertInstanceOf(JsonRecordStore::class, $documents);
        foreach ([$store, $documents] as $layer) {
            $this->assertInstanceOf($dialects[$family], self::property($layer, 'dialect'));
            $this->assertSame($container->get('database_connection'), self::property($layer, 'connection'));
            $this->assertInstanceOf(LegacyRecordDefaults::class, self::property($layer, 'defaults'));
        }
        $this->assertSame($container->get(TypeMap::class), self::property($documents, 'types'));
        $this->assertInstanceOf(AttributeTypeMap::class, self::property($documents, 'types'));
        $this->assertSame($container->get(SchemaCatalog::class), self::property($documents, 'catalog'), 'the catalog IndexedFields refreshes');
        $this->assertSame($container->get(SchemaCatalog::class), self::property($container->get(IndexedFields::class), 'catalog'));
        // Read from the legacy config each time a search is built, as the old backend read it (not once).
        $this->assertSame((bool) Idno::site()->config()->boolean_search, self::property($documents, 'booleanSearch')());
        $this->assertSame((bool) Idno::site()->config()->bypass_fulltext_search, self::property($documents, 'bypassFulltext')());
        $uncurl = self::property($documents, 'uncurlQuotes');
        $this->assertSame(Idno::site()->language()->uncurlQuotes("\u{201C}a\u{2019}"), $uncurl("\u{201C}a\u{2019}"));

        $this->assertInstanceOf(LegacyAccessContext::class, $container->get(AccessContext::class));

        $repository = $container->get(EntityRepository::class);
        $this->assertInstanceOf(EntityRepository::class, $repository);
        $this->assertSame($store, self::property($repository, 'store'));
        $this->assertSame($container->get(AccessContext::class), self::property($repository, 'access'));
        $this->assertSame($container->get(\Idno\Core\Logging::class), self::property($repository, 'logger'));
    }

    public function testLegacyRecordDefaultsAreWhatSaveRecordFillsIn(): void
    {
        $defaults = new LegacyRecordDefaults();
        $this->assertSame(Idno::site()->config()->getURL(), $defaults->siteUrl());
        $site = Idno::site()->site_details();
        $this->assertSame($site ? $site->uuid() : null, $defaults->currentSiteId());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $defaults->generateId());
        $this->assertNotSame($defaults->generateId(), $defaults->generateId());
    }

    public function testLegacyAccessContextReadsTheSessionAtCallTime(): void
    {
        $context = new LegacyAccessContext();

        $this->swapUser(false);
        $this->assertNull($context->currentUserUuid());
        $this->assertFalse($context->isAdmin());
        $this->assertSame(['PUBLIC'], $context->readAccessGroupIds());

        $user = $this->user();
        $this->swapUser($user);
        $this->assertSame($user->getUUID(), $context->currentUserUuid());
        $this->assertFalse($context->isAdmin());
        $this->assertSame(Idno::site()->session()->getReadAccessGroupIDs(), $context->readAccessGroupIds());
        $this->assertSame(['PUBLIC', 'SITE', $user->getUUID()], array_slice($context->readAccessGroupIds(), 0, 3));

        $admin = $this->admin();
        $this->swapUser($admin);
        $this->assertSame($admin->getUUID(), $context->currentUserUuid());
        $this->assertTrue($context->isAdmin());

        $this->assertFalse($context->ignoreAccess());
        Idno::site()->db()->setIgnoreAccess(true);
        $this->assertTrue($context->ignoreAccess());
        Idno::site()->db()->setIgnoreAccess(false);
        $this->assertFalse($context->ignoreAccess());
    }

    public function testLegacyAccessContextWithoutASessionObject(): void
    {
        $context = new LegacyAccessContext();
        $property = new \ReflectionProperty(Idno::class, 'session');
        $session = $property->getValue(Idno::site());
        $property->setValue(Idno::site(), null);
        try {
            $values = [$context->currentUserUuid(), $context->isAdmin(), $context->readAccessGroupIds()];
        } finally {
            $property->setValue(Idno::site(), $session);
        }
        $this->assertSame([null, false, ['PUBLIC']], $values);
    }

    // ------------------------------------------------ the factory

    public function testTheFactoryGivesAnSqliteSiteTheSqliteDialectOnTheStoreConnection(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $catalog = new SchemaCatalog($connection);
        $types = new IdentityTypeMap();
        $factory = new LegacyRecordStoreFactory(static fn (): Connection => $connection, static fn (): SchemaCatalog => $catalog, new BootConfig(['database' => 'SQLite', 'dbname' => ':memory:'], false), new LegacyRecordDefaults(), $types);

        $store = $factory->create();
        $this->assertInstanceOf(SettingsRecordStore::class, $store);
        $documents = self::property($store, 'inner');
        $this->assertInstanceOf(JsonRecordStore::class, $documents);
        $this->assertInstanceOf(SqliteDialect::class, self::property($documents, 'dialect'));
        $this->assertSame(self::property($documents, 'dialect'), self::property($store, 'dialect'));
        $this->assertSame($connection, self::property($store, 'connection'));
        $this->assertSame($connection, self::property($documents, 'connection'));
        $this->assertSame($catalog, self::property($documents, 'catalog'));
        $this->assertSame($types, self::property($documents, 'types'));
        $connection->close();
    }

    public function testTheFactoryGivesAMysqlSiteTheMysqlDialect(): void
    {
        // A MySQL connection of its own that is never opened (DBAL connects at the first query), so
        // the test runs on any site's database, MongoDB included (whose container has no connection).
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_mysql', 'host' => '127.0.0.1', 'dbname' => 'never_connected']);
        $factory = new LegacyRecordStoreFactory(static fn (): Connection => $connection, static fn (): SchemaCatalog => new SchemaCatalog($connection), new BootConfig(['database' => 'beanstalk-mysql'], false), new LegacyRecordDefaults(), new IdentityTypeMap());

        $store = $factory->create();
        $this->assertInstanceOf(MySqlDialect::class, self::property($store, 'dialect'));
        $this->assertInstanceOf(MySqlDialect::class, self::property(self::property($store, 'inner'), 'dialect'));
        $this->assertSame($connection, self::property($store, 'connection'));
        $this->assertFalse($connection->isConnected(), 'building the store does not connect');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function databasesWithoutAStore(): array
    {
        return ['mongo' => ['mongo'], 'mongodb' => ['MongoDB'], 'a plugin backend' => [\Tests\Unit\Storage\PluginBackendFixture::class]];
    }

    /**
     * @dataProvider databasesWithoutAStore
     */
    public function testTheFactoryRefusesOtherDatabasesWithoutTouchingTheConnection(string $database): void
    {
        $factory = new LegacyRecordStoreFactory(
            static fn (): Connection => throw new \RuntimeException('the connection was fetched'),
            static fn (): SchemaCatalog => throw new \RuntimeException('the catalog was fetched'),
            new BootConfig(['database' => $database], false),
            new LegacyRecordDefaults(),
            new IdentityTypeMap(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has no record store');
        $factory->create();
    }

    // ------------------------------------------------ the container on any database

    /**
     * @return array<string, array{string}>
     */
    public static function databases(): array
    {
        return ['mysql' => ['mysql'], 'sqlite' => ['sqlite'], 'mongo' => ['mongo'], 'an unknown backend' => ['SomePluginBackend']];
    }

    /**
     * A kernel without the legacy application, compiled into a fresh cache directory: the container
     * compiles and boots, the services are defined, and none of them (nor the connection) is built.
     *
     * @dataProvider databases
     */
    public function testTheContainerCompilesAndBootsWithoutBuildingThem(string $database): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/idno-test-cache-' . bin2hex(random_bytes(6));
        $this->setServer('IDNO_CACHE_DIR', $this->cacheDir);
        $this->setServer('IDNO_DATABASE', $database);
        $this->setServer('IDNO_DBNAME', $database === 'sqlite' ? $this->cacheDir . '/site.db' : null);
        $this->kernel = new Kernel('test', true, bootLegacy: false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        $this->assertSame($database, $container->get(BootConfig::class)->get('database'));
        foreach ([RecordStore::class, AccessContext::class, EntityRepository::class, IndexedFields::class] as $id) {
            $this->assertTrue($container->has($id), $id);
            $this->assertFalse($container->initialized($id), $id . ' was built while the kernel booted');
        }
        $this->assertFalse($container->initialized('database_connection'));
        $this->assertFileDoesNotExist($this->cacheDir . '/site.db');
    }

    /**
     * IndexedFields is built when first fetched: on an SQL site with the dialect of its database and
     * without connecting; on any other site fetching it throws a \LogicException (the admin page
     * then skips reconciling).
     *
     * @dataProvider databases
     */
    public function testIndexedFieldsAreBuiltOnAnSqlSiteOnlyAndWithoutConnecting(string $database): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/idno-test-cache-' . bin2hex(random_bytes(6));
        $this->setServer('IDNO_CACHE_DIR', $this->cacheDir);
        $this->setServer('IDNO_DATABASE', $database);
        $this->setServer('IDNO_DBNAME', $database === 'sqlite' ? $this->cacheDir . '/site.db' : null);
        $this->kernel = new Kernel('test', true, bootLegacy: false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();

        $dialects = ['mysql' => MySqlDialect::class, 'sqlite' => SqliteDialect::class, 'SomePluginBackend' => MySqlDialect::class];
        if ($database === 'mongo') {
            $this->expectException(\LogicException::class);
            $container->get(IndexedFields::class);

            return;
        }
        $indexes = $container->get(IndexedFields::class);
        $this->assertInstanceOf(IndexedFields::class, $indexes);
        $this->assertInstanceOf($dialects[$database], self::property($indexes, 'dialect'));
        $this->assertSame($container->get('database_connection'), self::property($indexes, 'connection'));
        $this->assertFalse($container->get('database_connection')->isConnected(), 'building it does not connect');
        $this->assertFileDoesNotExist($this->cacheDir . '/site.db');
    }

    public function testTheDialectFactoryRefusesADatabaseThatIsNotSql(): void
    {
        $this->assertInstanceOf(MySqlDialect::class, (new DialectFactory(new BootConfig(['database' => 'beanstalk-mysql'], false)))->create());
        $this->assertInstanceOf(SqliteDialect::class, (new DialectFactory(new BootConfig(['database' => 'sqlite'], false)))->create());

        $this->expectException(\LogicException::class);
        (new DialectFactory(new BootConfig(['database' => 'mongodb'], false)))->create();
    }
}
