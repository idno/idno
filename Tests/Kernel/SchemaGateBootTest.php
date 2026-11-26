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
use Idno\Storage\SchemaMigrator;
use Idno\Upgrade\PublicMessage;
use Idno\Upgrade\SchemaGate;
use PHPUnit\Framework\TestCase;
use Tests\Storage\PrivateDatabase;

/**
 * The schema gate at the entry points that cannot serve the upgrade page: Idno/start.php (through
 * Tests/Kernel/boot_schema_gate.php) and bin/console, each in a subprocess on a private database
 * chosen with IDNO_DBNAME (on MySQL or MariaDB `<dbname>_schema_gate` on the rig's server, on
 * SQLite a file in a temporary directory of the test's own), never the rig site's database. The
 * gate is closed on the empty database and open once the migrations have run. Skipped on a site
 * whose database is not SQL. A site configured for MongoDB is held closed whatever the private
 * database holds (IDNO_DATABASE in the subprocess).
 */
final class SchemaGateBootTest extends TestCase
{
    private ?Connection $connection = null;

    private string $dbname = '';

    private ?string $directory = null;

    protected function setUp(): void
    {
        $family = ConnectionParameters::driverFamily(Idno::container()->get(BootConfig::class)->all());
        if ($family === 'mysql') {
            $this->connection = PrivateDatabase::mysql('schema_gate', migrate: false);
            $this->dbname = (string) $this->connection->getDatabase();
        } elseif ($family === 'sqlite') {
            $this->directory = sys_get_temp_dir() . '/idno-schema-gate-' . bin2hex(random_bytes(6));
            mkdir($this->directory, 0700);
            $this->dbname = $this->directory . '/site.sqlite';
            $this->connection = DriverManager::getConnection(
                ['driver' => 'pdo_sqlite', 'path' => $this->dbname],
                (new Configuration())->setMiddlewares([new SqliteConnectionSetup()]),
            );
        } else {
            $this->markTestSkipped('The site\'s database is not SQL (MySQL, MariaDB or SQLite): there is no schema gate.');
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
            foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->directory);
            $this->directory = null;
        }
    }

    public function testStartRefusesToBootWhileTheGateIsClosed(): void
    {
        [$exit, $stdout, $stderr] = $this->script('Tests/Kernel/boot_schema_gate.php');

        $this->assertSame(1, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame('', $stdout);
        $this->assertSame(SchemaGate::CLOSED_MESSAGE . "\n", $stderr);
        $this->assertSame([], $this->tables(), 'nothing was created');
    }

    /**
     * The listener that routes a request by the legacy table asks nothing and builds nothing while the gate is closed: the gate answers first, so the
     * legacy router's dispatcher (its table, its configuration) is never built and the request is neither given a choice nor routed.
     */
    public function testTheRoutedPagesListenerAsksNothingWhileTheGateIsClosed(): void
    {
        [$exit, $stdout, $stderr] = $this->script('Tests/Kernel/gate_closed_routed_request.php');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame(['legacy' => false, 'status' => 503, 'dispatcher' => false, 'choice' => false, 'routed' => false], json_decode($stdout, true), $stderr);
        $this->assertSame([], $this->tables(), 'nothing was created');
    }

    public function testStartBootsTheLegacyApplicationOnceTheSchemaIsCurrent(): void
    {
        (new SchemaMigrator($this->db()))->migrate();

        [$exit, $stdout, $stderr] = $this->script('Tests/Kernel/boot_schema_gate.php');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame("booted with the legacy application\n", $stdout);
    }

    /**
     * With the gate closed the console still runs Symfony's commands and idno:upgrade, says on
     * stderr why the rest is missing, and loads no legacy console plugin (nor `version`). The queue
     * worker is a Symfony command and is listed; running it is refused (below).
     */
    public function testTheConsoleRunsWithoutTheLegacyApplicationWhileTheGateIsClosed(): void
    {
        [$exit, $stdout, $stderr] = $this->script('bin/console', 'list');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame(SchemaGate::CLOSED_MESSAGE . "\n", $stderr);
        $this->assertStringContainsString('idno:upgrade', $stdout);
        $this->assertStringContainsString('debug:router', $stdout);
        foreach (['version', 'export'] as $legacy) {
            $this->assertDoesNotMatchRegularExpression('/^\s+' . preg_quote($legacy, '/') . '\s/m', $stdout, $legacy);
        }
        $this->assertSame([], $this->tables(), 'nothing was created');
    }

    /**
     * The queue worker is a Symfony command, so it is registered while the gate is closed, but it needs
     * the legacy application (the events' listeners): the console says why on stderr as for every
     * command but idno:upgrade, and the command refuses with exit 1 and starts nothing.
     */
    public function testTheQueueWorkerRefusesWhileTheGateIsClosed(): void
    {
        [$exit, $stdout, $stderr] = $this->script('bin/console', 'service-event-queue', '--once');

        $this->assertSame(1, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertStringContainsString(SchemaGate::CLOSED_MESSAGE, $stderr);
        $this->assertStringContainsString('The event queue worker needs the site\'s database to be set up', $stdout . $stderr);
        $this->assertStringNotContainsString('Processing pending events', $stdout . $stderr);
        $this->assertSame([], $this->tables(), 'nothing was created');
    }

    /**
     * `messenger:failed:retry` runs the events again, and an event's listeners are legacy code: with the
     * gate closed the legacy application is not there, so the command refuses (exit 1) before it takes a
     * message, and the failed messages stay as they are.
     */
    public function testRetryingFailedEventsRefusesWhileTheGateIsClosed(): void
    {
        (new SchemaMigrator($this->db()))->migrate();
        // A migration that is pending again closes the gate; the queue's table is there, with a failed message in it.
        $this->db()->executeStatement("delete from idno_migrations where version like '%Version20261121000000'");
        $this->db()->executeStatement("insert into idno_messages (body, headers, queue_name, created_at, available_at, delivered_at) values ('b', '[]', 'failed', '2026-01-01 00:00:00', '2026-01-01 00:00:00', null)");

        [$exit, $stdout, $stderr] = $this->script('bin/console', 'messenger:failed:retry', '--force');

        $this->assertSame(1, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertStringContainsString(SchemaGate::CLOSED_MESSAGE, $stderr);
        $this->assertStringContainsString('needs the site\'s database to be set up', $stdout . $stderr);
        $this->assertSame(1, (int) $this->db()->fetchOne("select count(*) from idno_messages where queue_name = 'failed' and delivered_at is null"), 'the message was not taken');
    }

    /**
     * Which command runs no longer decides how the kernel boots: an abbreviated name and Symfony's
     * global options reach idno:upgrade as any other command does, and it runs without the line.
     */
    public function testAbbreviatedNamesAndGlobalOptionsReachIdnoUpgrade(): void
    {
        [$exit, $stdout, $stderr] = $this->script('bin/console', '-e', 'prod', 'idno:upg', '--status');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame('', $stderr);
        $this->assertStringStartsWith("Schema status: empty\n", $stdout);
    }

    public function testTheConsoleHasItsLegacyCommandsOnceTheSchemaIsCurrent(): void
    {
        (new SchemaMigrator($this->db()))->migrate();

        [$exit, $stdout, $stderr] = $this->script('bin/console', 'list');

        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertStringNotContainsString(SchemaGate::CLOSED_MESSAGE, $stderr);
        $this->assertMatchesRegularExpression('/^\s+version\s/m', $stdout);
        $this->assertMatchesRegularExpression('/^\s+service-event-queue\s/m', $stdout);
    }

    /**
     * A site configured for MongoDB is held closed, whatever its database holds, with the message
     * that names the import command; nothing connects to the private database.
     */
    public function testStartRefusesAMongoSiteWithTheMessageThatNamesTheImportCommand(): void
    {
        [$exit, $stdout, $stderr] = $this->scriptWith(['IDNO_DATABASE' => 'mongodb'], 'Tests/Kernel/boot_schema_gate.php');

        $this->assertSame(1, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame('', $stdout);
        $this->assertSame(PublicMessage::mongoRetired()->text . "\n", $stderr);
        $this->assertSame([], $this->tables(), 'nothing was created');
    }

    public function testTheConsoleSaysItOnceAndStillRunsTheImportCommandAndTheUpgradeStatus(): void
    {
        $mongo = ['IDNO_DATABASE' => 'mongo'];
        $message = PublicMessage::mongoRetired()->text . "\n";

        [$exit, $stdout, $stderr] = $this->scriptWith($mongo, 'bin/console', 'list');
        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame($message, $stderr, 'once');
        $this->assertStringContainsString('idno:import:mongo', $stdout);
        $this->assertDoesNotMatchRegularExpression('/^\s+version\s/m', $stdout);

        [$exit, $stdout, $stderr] = $this->scriptWith($mongo, 'bin/console', 'idno:import:mongo', '--help');
        $this->assertSame(0, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame('', $stderr, 'the import command runs with the gate closed, without the line');
        $this->assertStringContainsString('idno:import:mongo', $stdout);

        [$exit, $stdout, $stderr] = $this->scriptWith($mongo, 'bin/console', 'idno:upgrade', '--status');
        $this->assertSame(1, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertStringStartsWith('Schema status: ', $stdout);
        $this->assertSame($message, $stderr);

        [$exit, $stdout, $stderr] = $this->scriptWith($mongo, 'bin/console', 'idno:upgrade');
        $this->assertSame(1, $exit, "stdout: $stdout\nstderr: $stderr");
        $this->assertSame($message, $stderr);
        $this->assertSame([], $this->tables(), 'nothing was created');
    }

    private function db(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return (new \Idno\Storage\SchemaCatalog($this->db()))->tables();
    }

    /**
     * Runs a PHP script of the checkout with the arguments, on the private database, in the rig's
     * environment.
     *
     * @return array{0: int, 1: string, 2: string} the exit code, stdout and stderr
     */
    private function script(string $script, string ...$arguments): array
    {
        return $this->scriptWith([], $script, ...$arguments);
    }

    /**
     * Like script(), with more environment variables.
     *
     * @param array<string, string> $environment
     *
     * @return array{0: int, 1: string, 2: string} the exit code, stdout and stderr
     */
    private function scriptWith(array $environment, string $script, string ...$arguments): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=22527', '-d', 'display_errors=stderr', '-d', 'log_errors=0', $root . '/' . $script, ...$arguments],
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
            ] + $environment,
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
