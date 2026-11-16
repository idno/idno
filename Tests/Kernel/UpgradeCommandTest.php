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
 * `idno:upgrade` creates the schema on an EMPTY database, where the schema gate is closed and the
 * legacy application does not boot; once the schema is current the gate is open and the command
 * runs with the legacy application booted, as every command does. Each case runs the real console
 * in a subprocess, on a private database chosen with IDNO_DBNAME (on MySQL or MariaDB the empty
 * database `<dbname>_upgrade_command` on the rig's server, on SQLite an empty file in a temporary
 * directory of the test's own), never the rig site's database. Skipped on a MongoDB site.
 */
final class UpgradeCommandTest extends TestCase
{
    private const BASELINE = '20261115000000';

    /** The marker migration, recorded once the declared indexes exist. */
    private const MARKER = '20261115000001';

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
        'entities' => ['access', 'canonical', 'code', 'email', 'handle', 'queue', 'shorturl', 'slug', 'source_url', 'url'],
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
        $this->assertSame(self::MARKER, (new SchemaMigrator($this->connection()))->latest());
        $this->assertStringContainsString('Ran the migration ' . self::BASELINE . '.', $stdout);
        // The marker is recorded after the indexes, never before.
        $this->assertGreaterThan(strpos($stdout, 'Created the index '), strpos($stdout, 'Ran the migration ' . self::MARKER . '.'));
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

        // The gate is open now: the legacy application booted (and logs what it logs at boot).
        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame("Nothing to do: the schema is up to date.\n", $stdout);
        $this->assertStringNotContainsString(\Idno\Upgrade\SchemaGate::CLOSED_MESSAGE, $stderr);
        $this->assertStringNotContainsString('error', strtolower($stderr));
        $catalog = $this->catalog();
        $this->assertSame($indexes, array_map(static fn (string $collection): array => $catalog->indexedFields($collection), ['entities', 'reader', 'site']));
    }

    public function testStatusListsThePendingMigrationsAndChangesNothing(): void
    {
        [$exit, $stdout, $stderr] = $this->console('idno:upgrade', '--status');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame("Schema status: empty\nPending migration: " . self::BASELINE . "\nPending migration: " . self::MARKER . "\n", $stdout);
        $this->assertSame('', $stderr);
        $this->assertSame([], $this->catalog()->tables(), '--status creates nothing');

        $this->assertSame(0, $this->console('idno:upgrade')[0]);
        [$exit, $stdout] = $this->console('idno:upgrade', '--status');

        $this->assertSame(0, $exit);
        $this->assertSame("Schema status: current\nNo migration is pending.\n", $stdout);
    }

    /**
     * `--drop-legacy` deletes only legacy tables (there are none on a database that was never 1.x)
     * and does nothing else: it creates no schema, even on an empty database. `--yes` alone does
     * nothing.
     */
    public function testDropLegacyWithNothingToDeleteDoesNothingElse(): void
    {
        foreach ([['--drop-legacy'], ['--drop-legacy', '--yes']] as $options) {
            [$exit, $stdout, $stderr] = $this->console('idno:upgrade', ...$options);

            $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
            $this->assertSame("Nothing to delete: there are no legacy tables.\n", $stdout);
            $this->assertSame([], $this->catalog()->tables(), 'no schema was created');
        }

        [$exit, $stdout, $stderr] = $this->console('idno:upgrade', '--yes');
        $this->assertSame(1, $exit);
        $this->assertSame('', $stdout);
        $this->assertSame("--yes is only for --drop-legacy: nothing was done.\n", $stderr);
        $this->assertSame([], $this->catalog()->tables());

        [$exit, , $stderr] = $this->console('idno:upgrade', '--drop-legacy', '--status');
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not both', $stderr);
    }

    /**
     * While the gate is closed the commands that need the legacy application (its console plugins,
     * `version`) are not there, and the console says why; idno:upgrade opens the gate.
     */
    public function testOtherCommandsWaitForTheUpgrade(): void
    {
        [$exit, , $stderr] = $this->console('version');

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString(\Idno\Upgrade\SchemaGate::CLOSED_MESSAGE, $stderr);
        $this->assertStringContainsString('Command "version" is not defined', $stderr);
        $this->assertSame([], $this->catalog()->tables(), 'nothing was created');

        $this->assertSame(0, $this->console('idno:upgrade')[0]);
        [$exit, $stdout, $stderr] = $this->console('version');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertStringNotContainsString(\Idno\Upgrade\SchemaGate::CLOSED_MESSAGE, $stderr);
    }

    /**
     * With the database down (on MySQL a closed port, on SQLite a file in a directory that does not
     * exist), `idno:upgrade` and `idno:upgrade --status` are never reached: the gate is open for an
     * unreachable database, so the console boots the legacy application, whose database adapter
     * prints its database error page on stdout and ends the process with exit 0, as for every other
     * command. No exception, no trace, no `Schema status` line, nothing created.
     */
    public function testWithTheDatabaseDownTheLegacyErrorPageEndsTheRun(): void
    {
        if ($this->directory === null) {
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            self::assertNotFalse($socket);
            $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);
            $environment = ['IDNO_DBHOST' => '127.0.0.1', 'IDNO_DBPORT' => (string) $port];
        } else {
            $environment = ['IDNO_DBNAME' => $this->directory . '/no-such-directory/site.sqlite'];
        }

        foreach ([['idno:upgrade'], ['idno:upgrade', '--status']] as $arguments) {
            [$exit, $stdout, $stderr] = $this->consoleWith($environment, ...$arguments);

            $this->assertSame(0, $exit, implode(' ', $arguments) . "\nstdout: $stdout\nstderr: $stderr");
            $this->assertStringContainsString('We couldn\'t connect to the database', $stdout, implode(' ', $arguments));
            $this->assertStringNotContainsString('Schema status', $stdout);
            foreach (['Exception', 'Stack trace', '#0 '] as $trace) {
                $this->assertStringNotContainsString($trace, $stdout . $stderr, implode(' ', $arguments));
            }
        }
        $this->assertSame([], $this->catalog()->tables(), 'nothing was created');
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
        return $this->consoleWith([], ...$arguments);
    }

    /**
     * console() with more environment variables.
     *
     * @param array<string, string> $environment
     *
     * @return array{0: int, 1: string, 2: string} the exit code, stdout and stderr
     */
    private function consoleWith(array $environment, string ...$arguments): array
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
                ...$environment,
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
