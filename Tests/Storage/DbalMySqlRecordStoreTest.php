<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Config\BootConfig;
use Idno\Kernel;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\Criteria;
use Idno\Storage\Criterion\AllOf;
use Idno\Storage\Criterion\AnyOf;
use Idno\Storage\Criterion\Equals;
use Idno\Storage\Criterion\In;
use Idno\Storage\Criterion\NotIn;
use Idno\Storage\Criterion\Search;
use Idno\Storage\DbalRecordStore;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\Platform\MySqlDialect;
use Idno\Storage\RecordStore;
use Tests\Storage\Contract\RecordStoreContract;

/**
 * The record storage contract against DbalRecordStore on the rig's MySQL database, through the
 * container's DBAL connection (the `database_connection` service), plus the connection service
 * itself: built at runtime from the boot configuration, lazy, never compiled into the container.
 *
 * The store shares the rig's database (`known_unittest`, the tables the running site uses), so
 * deleteAll() stays skipped (ownsPrivateDatabase() is false) and the contract cleans up by marker.
 *
 * It overrides three expectation hooks; the contract's other defaults are this binding's values
 * (MySQL: whole-word boolean-mode full-text search, four-byte characters stored in the utf8mb4
 * search and metadata columns, text ordered by utf8mb4_unicode_520_ci, text compared ignoring the
 * case of non-ASCII letters):
 *  - expectLongFieldNamesToBeRefused (true): the metadata `name` column is a varchar(64);
 *  - expectUuidClashOnResaveToOverwrite (true): `on duplicate key update` takes the uuid key;
 *  - expectFourByteCharactersInFieldNamesToBeRefused (true): the metadata `name` column is utf8mb3.
 * It writes to `entities` only (the contract's default collections()): a record in `site` or
 * `config` would be read by the running site.
 */
final class DbalMySqlRecordStoreTest extends RecordStoreContract
{
    private ?DbalRecordStore $store = null;

    private ?Kernel $kernel = null;

    private ?string $cacheDir = null;

    /** @var array<string, mixed> $_SERVER entries this test changed, with their previous values (null: unset) */
    private array $serverBackup = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // MySQL-bound: on a site whose database is not MySQL (the CI MongoDB job) there is nothing to test.
        $config = \Idno\Core\Idno::container()->get(\Idno\Config\BootConfig::class)->all();
        if (\Idno\Storage\ConnectionParameters::driverFamily($config) !== 'mysql') {
            self::markTestSkipped('The site\'s database is not MySQL.');
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
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
    }

    protected function expectLongFieldNamesToBeRefused(): bool
    {
        return true;
    }

    protected function expectUuidClashOnResaveToOverwrite(): bool
    {
        return true;
    }

    protected function expectFourByteCharactersInFieldNamesToBeRefused(): bool
    {
        return true;
    }

    protected function store(): RecordStore
    {
        return $this->store ??= new DbalRecordStore(
            self::connection(),
            new MySqlDialect(),
            new FixedRecordDefaults('http://contract.test/', $this->currentSiteId(), fn (): string => $this->newId()),
            \Idno\Core\Idno::site()->language()->uncurlQuotes(...),
            (bool) \Idno\Core\Idno::site()->config()->boolean_search,
            (bool) \Idno\Core\Idno::site()->config()->bypass_fulltext_search,
        );
    }

    /**
     * The connection service of the kernel that booted the legacy application (the test bootstrap's).
     */
    private static function connection(): Connection
    {
        $connection = \Idno\Core\Idno::container()->get('database_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    // ------------------------------------------------ the connection service

    private function setServer(string $key, mixed $value): void
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

    /**
     * A kernel without the legacy application, compiled into a fresh cache directory.
     */
    private function bootIsolatedKernel(): Kernel
    {
        $this->cacheDir = sys_get_temp_dir() . '/idno-test-cache-' . bin2hex(random_bytes(6));
        $this->setServer('IDNO_CACHE_DIR', $this->cacheDir);
        $this->kernel = new Kernel('test', true, bootLegacy: false);
        $this->kernel->boot();

        return $this->kernel;
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

    public function testNoCredentialInCompiledContainer(): void
    {
        $sentinel = 'dbpass-sentinel-' . bin2hex(random_bytes(6));
        $this->setServer('IDNO_DBPASS', $sentinel);
        $kernel = $this->bootIsolatedKernel();

        // The password reaches the connection at runtime...
        $connection = $kernel->getContainer()->get('database_connection');
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame($sentinel, $connection->getParams()['password']);
        $this->assertFalse($connection->isConnected());

        // ...and is nowhere in the cache directory.
        $files = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($kernel->getCacheDir(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files++;
                $this->assertStringNotContainsString($sentinel, (string) file_get_contents($file->getPathname()), $file->getPathname());
            }
        }
        $this->assertGreaterThan(0, $files, 'expected a compiled container in the cache directory');
    }

    public function testFetchingTheConnectionServiceDoesNotConnect(): void
    {
        // An address nothing listens on: connecting would fail, so the test also proves nothing tries.
        $this->setServer('IDNO_DBHOST', '127.0.0.1');
        $this->setServer('IDNO_DBPORT', '9');
        $container = $this->bootIsolatedKernel()->getContainer();

        $connection = $container->get('database_connection');
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame($connection, $container->get('doctrine')->getConnection());
        $this->assertFalse($connection->isConnected());
        $this->assertSame(9, $connection->getParams()['port']);
    }

    public function testTheConnectionParametersComeFromTheBootConfig(): void
    {
        $container = $this->bootIsolatedKernel()->getContainer();
        $boot = $container->get(BootConfig::class);
        $expected = ConnectionParameters::fromLegacyConfig($boot->all(), []);

        $params = $container->get('database_connection')->getParams();
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $params[$key] ?? null, $key);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonSqlDatabases(): array
    {
        return ['mongo' => ['mongo'], 'mongodb' => ['MongoDB'], 'a plugin backend' => [\Tests\Unit\Storage\PluginBackendFixture::class]];
    }

    /**
     * The kernel boots with `bootLegacy: false`: this proves that the container compiles and the
     * kernel boots on a non-SQL site without touching the connection, not a full legacy boot on a
     * MongoDB site (which needs a MongoDB server).
     *
     * @dataProvider nonSqlDatabases
     */
    public function testANonSqlDatabaseCompilesAndBootsButHasNoConnection(string $database): void
    {
        $this->setServer('IDNO_DATABASE', $database);
        $container = $this->bootIsolatedKernel()->getContainer();
        $this->assertSame($database, $container->get(BootConfig::class)->get('database'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not an SQL database');
        $container->get('database_connection');
    }

    public function testTheSessionMatchesTheLegacyConnection(): void
    {
        $query = 'SELECT @@session.sql_mode, @@session.time_zone, @@character_set_client, @@collation_connection, @@character_set_connection, @@character_set_results';
        // What a connection of the legacy backend (Idno\Data\MySQL, since removed) answered on
        // the rig's MySQL 8, observed on the commit before its removal, with the character set its
        // DSN asked for last, utf8mb4 (observed on the rig's MySQL 8).
        $legacy = [
            'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION',
            'SYSTEM',
            'utf8mb4',
            'utf8mb4_general_ci',
            'utf8mb4',
            'utf8mb4',
        ];

        $dbal = self::connection()->fetchNumeric($query);

        $this->assertSame($legacy, $dbal);
        $this->assertSame(['utf8mb4', 'utf8mb4_general_ci'], [$dbal[2], $dbal[3]]);
        $native = self::connection()->getNativeConnection();
        $this->assertSame(1, $native->getAttribute(\PDO::ATTR_EMULATE_PREPARES) ? 1 : 0, 'prepared statements are emulated, as legacy');
    }

    // ------------------------------------------------ failures

    public function testAFailedQueryLeavesTheConnectionUsable(): void
    {
        $record = $this->put($this->r(['slug' => 'usable']));
        $connection = self::connection();
        $level = $connection->getTransactionNestingLevel();

        // MySQL rejects the datetime literal (error 1525): no records, not an exception.
        $this->assertSame([], $this->store()->find('entities', Criteria::fromLegacyArray(['created' => '1686700100']), 10, 0));
        $this->assertSame(0, $this->store()->count('entities', Criteria::fromLegacyArray(['created' => '1686700100'])));

        $this->assertSame($level, $connection->getTransactionNestingLevel());
        $this->assertSame([$record['_id']], $this->ids(['slug' => 'usable']));
    }

    public function testAFailedSaveRollsBackAndLeavesTheConnectionUsable(): void
    {
        // `name` is varchar(64): the metadata row is refused after the main and search rows are written.
        $record = $this->r(['note' => 'refused', str_repeat('n', 65) => 'v']);
        $this->track($record['_id']);
        $connection = self::connection();
        $level = $connection->getTransactionNestingLevel();

        $this->assertFalse($this->store()->save('entities', $record));

        $this->assertSame($level, $connection->getTransactionNestingLevel());
        $this->assertSame(0, (int) $connection->fetchOne('select count(*) from entities_search where _id = ?', [$record['_id']]), 'the search row was rolled back');
        $this->assertNull($this->store()->findById('entities', $record['_id']));
        $this->put($this->r(['slug' => 'after']));
    }

    public function testConnectionFailuresAreNotNoResults(): void
    {
        $params = ConnectionParameters::fromLegacyConfig(\Idno\Core\Idno::container()->get(BootConfig::class)->all(), []);
        $params['dbname'] = 'known_unittest_no_such_database_' . bin2hex(random_bytes(4));
        $store = new DbalRecordStore(DriverManager::getConnection($params), new MySqlDialect(), new FixedRecordDefaults(), static fn (string $s): string => $s, true, false);

        foreach ([
            'find' => static fn () => $store->find('entities', Criteria::all(), 10, 0),
            'count' => static fn () => $store->count('entities', Criteria::all()),
            'findById' => static fn () => $store->findById('entities', 'x'),
            'save' => static fn () => $store->save('entities', ['_id' => 'x']),
            'delete' => static fn () => $store->delete('entities', 'x'),
        ] as $operation => $call) {
            try {
                $call();
                $this->fail($operation . ' should throw');
            } catch (\Doctrine\DBAL\Exception $e) {
                $this->assertInstanceOf(\Doctrine\DBAL\Exception\ConnectionException::class, $e, $operation);
            }
        }
    }

    // ------------------------------------------------ the SQL the store runs

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

    public function testATableThatIsNotACollectionIsRejected(): void
    {
        // `session` and `versions` exist but have no _metadata and _search tables.
        foreach (['session', 'versions'] as $table) {
            $this->assertFalse($this->store()->save($table, $this->r()), $table);
            $this->assertSame([], $this->store()->find($table, Criteria::all(), 10, 0), $table);
            $this->assertSame(0, $this->store()->count($table, Criteria::all()), $table);
            $this->assertNull($this->store()->findLatest($table), $table);
            $this->assertFalse($this->store()->delete($table, 'x'), $table);
            $this->assertFalse($this->store()->deleteAll($table), $table);
        }
    }

    public function testCollectionNamesKeepTheirCaseOnACaseSensitiveServer(): void
    {
        // The rig's server has lower_case_table_names = 0 (as Linux MySQL): `ENTITIES` is no table,
        // as for the legacy backend. (The case-insensitive branch: DbalRecordStoreCollectionsTest.)
        $this->assertSame('0', (string) self::connection()->fetchOne('select @@lower_case_table_names'));
        $record = $this->put($this->r(['slug' => 'case']));

        $this->assertNull($this->store()->findById('ENTITIES', $record['_id']));
        $this->assertFalse($this->store()->save('Entities', $this->r()));
        $this->assertNotNull($this->store()->findById('entities', $record['_id']));
    }

    /**
     * The rows the store writes for a record are, but for the ids, the rows the legacy backend
     * (Idno\Data\MySQL::saveRecord(), since removed) wrote for the same record: observed on the
     * commit before its removal by saving this record with both and reading the rows back (ids and
     * the test's marker replaced). The note is 120 characters (240 bytes): the legacy backend lost a
     * record whose indexed value was cut in the middle of a character, so the observed record has
     * none.
     */
    public function testWhatTheStoreWritesEqualsWhatTheLegacyBackendWrites(): void
    {
        $record = $this->r([
            'title' => "It\u{2019}s a <b>title</b>",
            'body' => "<p>Body #tag\r\nline</p>",
            'labels' => ['red', '', ['k' => 'v']],
            'pair' => ['a' => 'alpha'],
            'note' => str_repeat('é', 120),
            'flag' => true,
            'nothing' => null,
        ]);
        $this->put($record);

        $connection = self::connection();
        $replace = [$record['_id'] => '<id>', $this->marker => '<marker>'];
        $main = $connection->fetchAssociative('select * from entities where _id = ?', [$record['_id']]);
        $main['contents'] = json_decode($main['contents'], true);
        $written = array_map(
            static fn (mixed $value): mixed => is_string($value) ? strtr($value, $replace) : $value,
            [
                'main' => json_encode($main),
                'search' => $connection->fetchOne('select search from entities_search where _id = ?', [$record['_id']]),
                'metadata' => json_encode($connection->fetchAllNumeric('select name, value from entities_metadata where _id = ?', [$record['_id']])),
            ],
        );

        $note = str_repeat('\u00e9', 120);
        $this->assertSame([
            'main' => '{"uuid":"http:\/\/contract.test\/view\/<id>","_id":"<id>","siteid":"contract-site","owner":"http:\/\/contract.test\/profile\/owner","entity_subtype":"Tests\\\\Storage\\\\Contract\\\\Record","created":"2023-06-13 23:47:40","contents":{"title":"It\u2019s a <b>title<\/b>","body":"<p>Body #tag\r\nline<\/p>","labels":["red","",{"k":"v"}],"pair":{"a":"alpha"},"note":"' . $note . '","flag":true,"nothing":null,"_id":"<id>","uuid":"http:\/\/contract.test\/view\/<id>","siteid":"contract-site","owner":"http:\/\/contract.test\/profile\/owner","entity_subtype":"Tests\\\\Storage\\\\Contract\\\\Record","publish_status":"published","created":1686700060,"contract_marker":"<marker>"},"publish_status":"published"}',
            'search' => "it's a <b>title</b> body  #tag \n line",
            'metadata' => '[["title","It\u2019s a <b>title<\/b>"],["body","<p>Body #tag\r\nline<\/p>"],["labels","red"],["labels","0"],["labels","{\"k\":\"v\"}"],["pair","alpha"],["note","' . $note . '"],["flag","1"],["nothing","0"],["_id","<id>"],["uuid","http:\/\/contract.test\/view\/<id>"],["siteid","contract-site"],["owner","http:\/\/contract.test\/profile\/owner"],["entity_subtype","Tests\\\\Storage\\\\Contract\\\\Record"],["publish_status","published"],["created","2023-06-13 23:47:40"],["contract_marker","<marker>"]]',
        ], $written);
    }
}
