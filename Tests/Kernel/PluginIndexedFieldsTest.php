<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Core\Logging;
use Idno\Pages\Admin\Plugins;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\IndexedFields;
use Idno\Storage\SchemaCatalog;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Tests\Storage\PrivateDatabase;

/**
 * Enabling a plugin in the admin page (Idno\Pages\Admin\Plugins) creates the indexes its entity
 * classes declare, through the container's IndexedFields. Its method is called directly (on a page
 * built without its constructor, which would change the response headers and the exception
 * handler), with the fixture classes' directories as the plugin's directory. The indexes are made
 * in private databases, never on the site's tables.
 */
final class PluginIndexedFieldsTest extends TestCase
{
    private string $logFile;

    private string|false $previousErrorLog;

    private int $previousLevel;

    protected function setUp(): void
    {
        parent::setUp();
        $family = ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all());
        if (!in_array($family, ['mysql', 'sqlite'], true)) {
            self::markTestSkipped('The site\'s database is not SQL: it has no indexed fields, and the page skips them.');
        }
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'idno-log-');
        $this->previousErrorLog = ini_get('error_log');
        ini_set('error_log', $this->logFile);
        $this->previousLevel = Idno::site()->logging()->loglevel_filter;
        Idno::site()->logging()->setLogLevel(Logging::LEVEL_WARNING);
        Idno::site()->session()->flushMessages();
    }

    protected function tearDown(): void
    {
        if (isset($this->logFile)) {
            Idno::site()->logging()->setLogLevel($this->previousLevel);
            ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
            @unlink($this->logFile);
            Idno::site()->session()->flushMessages();
        }
        parent::tearDown();
    }

    private static function reconcile(string $plugin, string $directory): void
    {
        $page = (new \ReflectionClass(Plugins::class))->newInstanceWithoutConstructor();
        (new \ReflectionMethod(Plugins::class, 'reconcileIndexedFields'))->invoke($page, $plugin, $directory);
    }

    /**
     * On a site with the schema (the rig's, since the switch: its tables are made by idno:upgrade),
     * enabling a plugin creates the indexes its entity classes declare. The page is given an
     * IndexedFields on a private database with the schema migrations run, so that the site's own
     * tables are never changed.
     */
    public function testEnablingAPluginOnASiteWithTheSchemaCreatesItsIndexes(): void
    {
        $site = new SchemaCatalog(Idno::container()->get('database_connection'));
        self::assertTrue($site->hasTable('idno_entities'), 'the site has the schema');
        $siteIndexes = $site->indexedFields('entities');
        $private = PrivateDatabase::sqliteMemory();
        $catalog = new SchemaCatalog($private);
        self::assertSame([], $catalog->indexedFields('entities'));

        try {
            self::withContainer(
                [IndexedFields::class => static fn (): IndexedFields => new IndexedFields($private, PrivateDatabase::dialect($private), $catalog)],
                static fn () => self::reconcile('Fixtures', dirname(__DIR__) . '/Storage/Fixtures/IndexedFields'),
            );

            $catalog->forget();
            self::assertSame([], array_values(array_diff(['actor_id', 'code', 'f'], $catalog->indexedFields('entities'))), 'the plugin\'s indexes exist');
            self::assertContains('child_field', $catalog->indexedFields('reader'));
            self::assertSame([], Idno::site()->session()->getMessages());
            $site->forget();
            self::assertSame($siteIndexes, $site->indexedFields('entities'), 'the site\'s own tables are untouched');
        } finally {
            PrivateDatabase::drop($private);
        }
    }

    /**
     * A site without the tables of the schema (a 1.x site not yet upgraded) gets nothing, and no
     * message: here an IndexedFields on a private empty database.
     */
    public function testASiteWithoutTheNewTablesGetsNothingAndNoMessage(): void
    {
        $private = PrivateDatabase::sqliteMemory(migrate: false);
        $catalog = new SchemaCatalog($private);

        try {
            self::withContainer(
                [IndexedFields::class => static fn (): IndexedFields => new IndexedFields($private, PrivateDatabase::dialect($private), $catalog)],
                static fn () => self::reconcile('Fixtures', dirname(__DIR__) . '/Storage/Fixtures/IndexedFields'),
            );

            $catalog->forget();
            self::assertSame([], $catalog->tables());
            self::assertSame([], Idno::site()->session()->getMessages());
            self::assertSame('', (string) file_get_contents($this->logFile));
        } finally {
            PrivateDatabase::drop($private);
        }
    }

    public function testAFailureIsLoggedAndShown(): void
    {
        // The fixture declares an invalid field name: reconcile() throws before any DDL.
        self::reconcile('Broken', dirname(__DIR__) . '/Storage/Fixtures/IndexedFieldsInvalid');

        $messages = Idno::site()->session()->getMessages();
        self::assertCount(1, $messages);
        self::assertStringContainsString('could not be created', (string) json_encode($messages));
        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('Could not create the indexes of plugin Broken: InvalidArgumentException', $log);
    }

    /**
     * Runs $test with Idno::container() replaced by one that delegates to the site's container,
     * except for the services $overrides gives (a closure that throws, or returns the service).
     *
     * @param array<string, \Closure(): mixed> $overrides
     */
    private static function withContainer(array $overrides, \Closure $test): void
    {
        $original = Idno::container();
        $container = new class ($original, $overrides) implements ContainerInterface {
            /**
             * @param array<string, \Closure(): mixed> $overrides
             */
            public function __construct(private readonly ContainerInterface $inner, private readonly array $overrides)
            {
            }

            public function get(string $id): mixed
            {
                return isset($this->overrides[$id]) ? ($this->overrides[$id])() : $this->inner->get($id);
            }

            public function has(string $id): bool
            {
                return isset($this->overrides[$id]) || $this->inner->has($id);
            }
        };
        Idno::setContainer($container);
        try {
            $test();
        } finally {
            Idno::setContainer($original);
        }
    }

    public function testAMissingServiceIsLoggedAndShown(): void
    {
        self::withContainer(
            [IndexedFields::class => static fn () => throw new ServiceNotFoundException(IndexedFields::class)],
            static fn () => self::reconcile('Missing', dirname(__DIR__) . '/Storage/Fixtures/IndexedFields'),
        );

        self::assertCount(1, Idno::site()->session()->getMessages());
        self::assertStringContainsString('Could not create the indexes of plugin Missing: ' . ServiceNotFoundException::class, (string) file_get_contents($this->logFile));
    }

    public function testASiteWhoseDatabaseIsNotSqlIsSkippedSilently(): void
    {
        self::withContainer(
            [
                BootConfig::class => static fn (): BootConfig => new BootConfig(['database' => 'mongodb'], false),
                IndexedFields::class => static fn () => throw new \RuntimeException('IndexedFields was fetched on a MongoDB site'),
            ],
            static fn () => self::reconcile('Fixtures', dirname(__DIR__) . '/Storage/Fixtures/IndexedFields'),
        );

        self::assertSame([], Idno::site()->session()->getMessages());
        self::assertSame('', (string) file_get_contents($this->logFile));
    }
}
