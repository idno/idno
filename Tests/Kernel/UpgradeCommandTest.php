<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use PHPUnit\Framework\TestCase;
use Tests\Storage\PrivateDatabase;

/**
 * `idno:upgrade` creates the schema on an EMPTY database, where the legacy application cannot
 * boot: bin/console builds the kernel without booting it for that command. Each case runs the real
 * console in a subprocess, on a private database chosen with IDNO_DBNAME (on MySQL or MariaDB the
 * empty database `<dbname>_upgrade_command` on the rig's server, on SQLite an empty file in a
 * temporary directory of the test's own), never the rig site's database. Skipped on a MongoDB site.
 */
final class UpgradeCommandTest extends TestCase
{
    private const BASELINE = '20261115000000';

    private const TABLES = [
        'idno_entities',
        'idno_migrations',
        'idno_reader',
        'idno_sessions',
        'idno_settings',
        'idno_site',
        'idno_upgrade_progress',
        'idno_upgrade_rejects',
    ];

    /** The indexes the classes under Idno/ declare (#[IndexedField]), per collection. */
    private const CORE_INDEXES = [
        'entities' => ['access', 'admin', 'canonical', 'code', 'email', 'handle', 'queue', 'shorturl', 'slug', 'source_url', 'url'],
        'reader' => ['access', 'canonical', 'feed_url', 'shorturl', 'slug', 'url'],
        'site' => ['access', 'canonical', 'domain', 'shorturl', 'slug', 'url'],
    ];

    private ?Connection $connection = null;

    /** The value of IDNO_DBNAME for the subprocess. */
    private string $dbname = '';

    private ?string $directory = null;

    protected function setUp(): void
    {
        $config = Idno::container()->get(BootConfig::class)->all();
        $family = ConnectionParameters::driverFamily($config);
        if ($family === 'mysql') {
            $this->connection = PrivateDatabase::mysql('upgrade_command', migrate: false);
            $this->dbname = (string) $this->connection->getDatabase();
        } elseif ($family === 'sqlite') {
            $this->directory = sys_get_temp_dir() . '/idno-upgrade-command-' . bin2hex(random_bytes(6));
            mkdir($this->directory, 0700);
            $this->dbname = $this->directory . '/site.sqlite';
            $this->connection = DriverManager::getConnection(
                ['driver' => 'pdo_sqlite', 'path' => $this->dbname],
                (new Configuration())->setMiddlewares([new SqliteConnectionSetup()]),
            );
        } else {
            $this->markTestSkipped('The site\'s database is not SQL (MySQL, MariaDB or SQLite): there is no schema to create.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            if ($this->directory === null) {
                PrivateDatabase::drop($this->connection);
            } else {
                $this->connection->close();
            }
            $this->connection = null;
        }
        if ($this->directory !== null) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
            $this->directory = null;
        }
    }

    public function testItCreatesTheSchemaAndTheCoreIndexesOnAnEmptyDatabase(): void
    {
        $this->assertSame([], $this->catalog()->tables(), 'the database is empty');

        [$exit, $stdout, $stderr] = $this->console('idno:upgrade');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame('', $stderr);
        $catalog = $this->catalog();
        foreach (self::TABLES as $table) {
            $this->assertTrue($catalog->hasTable($table), $table);
        }
        $this->assertSame(self::BASELINE, (new SchemaMigrator($this->connection()))->latest());
        $this->assertStringContainsString('Ran the migration ' . self::BASELINE . '.', $stdout);
        foreach (self::CORE_INDEXES as $collection => $fields) {
            $this->assertSame([], array_values(array_diff($fields, $catalog->indexedFields($collection))), $collection . ': every core index exists');
            foreach ($fields as $field) {
                $this->assertStringContainsString('Created the index ' . $collection . '.' . $field . '.', $stdout);
            }
        }
    }

    public function testASecondRunSaysThatNothingIsPending(): void
    {
        [$exit, $first] = $this->console('idno:upgrade');
        $this->assertSame(0, $exit, $first);
        $catalog = $this->catalog();
        $indexes = array_map(static fn (string $collection): array => $catalog->indexedFields($collection), ['entities', 'reader', 'site']);

        [$exit, $stdout, $stderr] = $this->console('idno:upgrade');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame("Nothing to do: the schema is up to date.\n", $stdout);
        $this->assertSame('', $stderr);
        $catalog = $this->catalog();
        $this->assertSame($indexes, array_map(static fn (string $collection): array => $catalog->indexedFields($collection), ['entities', 'reader', 'site']));
    }

    public function testStatusListsThePendingMigrationsAndChangesNothing(): void
    {
        [$exit, $stdout, $stderr] = $this->console('idno:upgrade', '--status');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame('Pending migration: ' . self::BASELINE . "\n", $stdout);
        $this->assertSame([], $this->catalog()->tables(), '--status creates nothing');

        $this->assertSame(0, $this->console('idno:upgrade')[0]);
        [$exit, $stdout] = $this->console('idno:upgrade', '--status');

        $this->assertSame(0, $exit);
        $this->assertSame("No migration is pending.\n", $stdout);
    }

    /**
     * Every other command boots the legacy application, which cannot boot on an empty database:
     * the control that shows idno:upgrade is the one that does not need it.
     */
    public function testOtherCommandsStillBootTheLegacyApplication(): void
    {
        [$exit, , $stderr] = $this->console('version');

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Idno could not start', $stderr);
    }

    private function connection(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }

    private function catalog(): SchemaCatalog
    {
        return new SchemaCatalog($this->connection());
    }

    /**
     * Runs bin/console with the arguments, on the private database, in the rig's environment.
     *
     * @return array{0: int, 1: string, 2: string} the exit code, stdout and stderr
     */
    private function console(string ...$arguments): array
    {
        $root = dirname(__DIR__, 2);
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=stderr -d log_errors=0 '
            . escapeshellarg($root . '/bin/console')
            . implode('', array_map(static fn (string $a): string => ' ' . escapeshellarg($a), $arguments));
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            [
                'APP_ENV' => 'test',
                'APP_DEBUG' => '1',
                'IDNO_DOMAIN' => '127.0.0.1',
                'IDNO_PORT' => '8089',
                'IDNO_DBNAME' => $this->dbname,
                // Any outbound request goes to a closed local port.
                'IDNO_PROXY_STRING' => '127.0.0.1:9',
                'PATH' => (string) getenv('PATH'),
            ],
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
