<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Config\BootConfig;
use Idno\Storage\IdentityTypeMap;
use Idno\Storage\IndexedFields;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use Idno\Upgrade\EnabledPlugins;
use Idno\Upgrade\LegacyDataCopier;
use Idno\Upgrade\PublicMessage;
use Idno\Upgrade\SchemaState;
use Idno\Upgrade\SchemaStatus;
use Idno\Upgrade\UpgradeLock;
use Idno\Upgrade\UpgradeRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tests\Storage\StatementRecorder;
use Tests\Unit\Storage\FailingStatementMiddleware;

/**
 * UpgradeRunner on an SQLite database file of the test's own (in a temporary directory it removes):
 * the real migrator, catalog, field indexes and lock. The statuses SQLite never has (a 1.x database,
 * an unsupported or unreachable server) come from a SchemaState whose closures return fakes.
 * Needs nothing but PHP.
 */
final class UpgradeRunnerTest extends TestCase
{
    private const BASELINE = '20261115000000';

    private const MARKER = '20261115000001';

    private string $directory = '';

    private ?Connection $connection = null;

    private ?StatementRecorder $recorder = null;

    /** @var list<string> */
    private array $logged = [];

    /** @var list<Connection> */
    private array $others = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/idno-upgrade-runner-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->recorder = new StatementRecorder();
        $this->connection = $this->connect($this->recorder);
    }

    protected function tearDown(): void
    {
        foreach ([...$this->others, $this->connection] as $connection) {
            $connection?->close();
        }
        $this->others = [];
        $this->connection = null;
        foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->directory);
    }

    public function testAnEmptyDatabaseIsSetUpInOneStep(): void
    {
        $result = $this->runner()->step(microtime(true) + 5);

        self::assertSame('current', $result['status'], (string) $result['message']);
        self::assertNull($result['message']);
        self::assertFalse($result['locked']);
        self::assertSame([], (new SchemaMigrator($this->db()))->pending());
        $catalog = new SchemaCatalog($this->db());
        self::assertContains('access', $catalog->indexedFields('entities'), 'the core indexes are made in the same step');
        self::assertContains('feed_url', $catalog->indexedFields('reader'));
        self::assertTrue((new UpgradeLock($this->other()))->acquire(), 'the lock is released');
    }

    public function testPendingMigrationsAreRunInOneStep(): void
    {
        $this->runner()->step(microtime(true) + 5);
        $this->db()->executeStatement('delete from idno_migrations');

        $result = $this->runner()->step(microtime(true) + 5);

        self::assertSame('current', $result['status']);
        self::assertSame(2, (int) $this->db()->fetchOne('select count(*) from idno_migrations'), 'the baseline and the indexed-fields marker');
    }

    /**
     * On a current database a step does nothing but look: no lock, no migration, no index. It
     * still answers when another process holds the lock.
     */
    public function testACurrentDatabaseIsLeftAlone(): void
    {
        $this->runner()->step(microtime(true) + 5);
        $lock = new UpgradeLock($this->other());
        self::assertTrue($lock->acquire());
        $this->recorder()->statements = [];

        $result = $this->runner()->step(microtime(true) + 5);

        self::assertSame('current', $result['status']);
        self::assertFalse($result['locked']);
        // The catalog and the migration check: no lock, no DDL, and no reconcile (which would read
        // the catalog again for every declared field).
        self::assertSame(
            ["select type, name, tbl_name from sqlite_master where type in ('table', 'index')", "select version from idno_migrations union all select case when completed_at is null then '#incomplete' else '#complete' end from idno_upgrade_progress"],
            array_column($this->recorder()->statements, 'sql'),
        );
    }

    public function testAStepThatCannotGetTheLockDoesNothing(): void
    {
        $lock = new UpgradeLock($this->other());
        self::assertTrue($lock->acquire());

        $result = $this->runner()->step(microtime(true) + 5);

        self::assertSame('working', $result['status']);
        self::assertTrue($result['locked']);
        self::assertSame([], (new SchemaCatalog($this->db()))->tables(), 'nothing was created');

        $lock->release();
        self::assertSame('current', $this->runner()->step(microtime(true) + 5)['status']);
    }

    /**
     * A step killed after the baseline and before the indexes (here: the first index's DDL fails)
     * leaves the marker migration pending: the gate stays closed, and the next step carries on.
     */
    public function testAStepKilledBeforeTheIndexesLeavesTheGateClosed(): void
    {
        $failing = new FailingStatementMiddleware();
        $failing->failOn('/^create (unique )?index if not exists "?idno_\w+_ix_/i');
        $this->connection?->close();
        $this->connection = $this->connect($this->recorder, $failing);

        $result = $this->runner()->step(microtime(true) + 5);

        self::assertSame('error', $result['status']);
        $migrator = new SchemaMigrator($this->other());
        self::assertSame([self::MARKER], $migrator->pending(), 'the marker is not recorded');
        self::assertSame(SchemaStatus::PendingMigrations, $this->state($this->other())->status(), 'the gate stays closed');

        $this->connection->close();
        $this->connection = $this->connect($this->recorder);
        self::assertSame('current', $this->runner()->step(microtime(true) + 5)['status']);
        self::assertSame([], (new SchemaMigrator($this->db()))->pending());
        $catalog = new SchemaCatalog($this->db());
        foreach (['access', 'admin', 'handle', 'email', 'slug'] as $field) {
            self::assertContains($field, $catalog->indexedFields('entities'), $field);
        }
    }

    /**
     * A web step's reconcile is bounded: with a deadline already past it creates exactly one index
     * and reports `working`, the marker still pending; repeated, it finishes.
     */
    public function testAWebStepCreatesOneIndexPastItsDeadline(): void
    {
        $result = $this->runner()->step(microtime(true) - 1);

        self::assertSame('working', $result['status']);
        self::assertSame([self::MARKER], (new SchemaMigrator($this->db()))->pending());
        self::assertSame(1, $this->indexCount());

        for ($i = 0; $i < 100 && $result['status'] === 'working'; ++$i) {
            $before = $this->indexCount();
            $result = $this->runner()->step(microtime(true) - 1);
            if ($result['status'] === 'working') {
                self::assertSame($before + 1, $this->indexCount(), 'one index a step');
            }
        }
        self::assertSame('current', $result['status']);
        self::assertSame([], (new SchemaMigrator($this->db()))->pending());
    }

    public function testANonSqlSiteIsNotTheRunnersBusiness(): void
    {
        $forbidden = static fn () => throw new \LogicException('nothing is fetched');
        $state = new SchemaState(new BootConfig(['database' => 'mongodb'], false), $forbidden, $forbidden, $forbidden, $forbidden);

        $result = $this->runner($state)->step(microtime(true) + 5);

        self::assertSame('current', $result['status']);
        self::assertSame([], (new SchemaCatalog($this->db()))->tables());
    }

    /**
     * When another process holds the lock and taking the copy's progress fails (it may be renaming
     * the tables), the step is `working`, never an error that would stop the page.
     */
    public function testALostLockRaceIsWorking(): void
    {
        $lock = new UpgradeLock($this->other());
        self::assertTrue($lock->acquire());

        $result = $this->runner($this->fakeState(['entities', 'entities_metadata', 'entities_search', 'versions'], legacyVersion: 2020120301))->step(microtime(true) + 5);

        self::assertSame('working', $result['status']);
        self::assertTrue($result['locked']);
        self::assertNull($result['progress']);
    }

    /**
     * run() whose first attempt finds the lock held says it waits, and still reconciles once it has
     * the lock (the declared indexes of a current site).
     */
    public function testRunWaitsForTheLockAndStillReconciles(): void
    {
        $this->runner()->run(static function (string $line): void {
        });
        $this->db()->executeStatement('drop index idno_entities_ix_access');
        $lock = new UpgradeLock($this->other());
        self::assertTrue($lock->acquire());

        $lines = [];
        $result = $this->runner()->run(static function (string $line) use (&$lines, $lock): void {
            $lines[] = $line;
            $lock->release();
        });

        self::assertSame('current', $result['status']);
        self::assertStringStartsWith('Waiting: another process is upgrading the database', $lines[0]);
        self::assertContains('Created the index entities.access.', $lines);
    }

    public function testRunGivesUpWhenTheLockIsHeldTooLong(): void
    {
        $lock = new UpgradeLock($this->other());
        self::assertTrue($lock->acquire());
        $lines = [];
        $started = microtime(true);

        $result = $this->runner(lockWait: 1.0)->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertSame('error', $result['status']);
        self::assertSame(PublicMessage::lockHeld(1)->text, $result['message']);
        self::assertCount(1, $lines, 'it says once that it waits');
        self::assertLessThan(5.0, microtime(true) - $started);
    }

    /**
     * run() stops when steps change nothing, whatever the status says (here a state that stays
     * Empty on a database that has its schema: nothing to migrate, nothing moves).
     */
    public function testRunStopsWhenNothingMoves(): void
    {
        $this->runner()->step(microtime(true) + 5);

        $result = $this->runner($this->fakeState([]))->run(static function (string $line): void {
        });

        self::assertSame('error', $result['status']);
        self::assertSame(PublicMessage::noProgress(UpgradeRunner::STALLED_STEPS)->text, $result['message']);
    }

    /**
     * A web step reconciles the core declarations only: it never reads IdnoPlugins/, so an enabled
     * plugin with a bad declaration cannot keep the site closed.
     */
    public function testAWebUpgradeNeverReadsPlugins(): void
    {
        $result = $this->runner(plugins: ['BadDeclaration', 'UniqueClash', 'GoodPlugin'])->step(microtime(true) + 5);

        self::assertSame('current', $result['status'], (string) $result['message']);
        self::assertSame([], (new SchemaMigrator($this->db()))->pending());
        $catalog = new SchemaCatalog($this->db());
        self::assertContains('access', $catalog->indexedFields('entities'));
        self::assertNotContains('upgrade_good_field', $catalog->indexedFields('entities'));
    }

    /**
     * idno:upgrade reconciles core, then each ENABLED plugin on its own: a plugin whose reconcile
     * throws (an invalid declaration; a unique index over duplicate values) is skipped with one
     * warning line and one log line, and the run goes on and ends `current`. A plugin that is present
     * but not enabled gets nothing.
     */
    public function testRunReconcilesEnabledPluginsAndSkipsTheOnesThatFail(): void
    {
        $this->runner()->step(microtime(true) + 5);
        foreach (['a', 'b'] as $id) {
            $this->db()->insert('idno_entities', ['id' => $id, 'uuid' => 'https://upgrade.example/' . $id, 'type' => 'upgrade_fixture_unique', 'owner' => '', 'created' => 1, 'publish_status' => 'published', 'data' => '{"upgrade_clash_field":"same"}', 'search' => '']);
        }
        $this->logged = [];
        $lines = [];

        $result = $this->runner(plugins: ['BadDeclaration', 'UniqueClash', 'GoodPlugin'])->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertSame('current', $result['status'], (string) $result['message']);
        self::assertContains('Created the index entities.upgrade_good_field.', $lines);
        $warnings = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'Warning: ')));
        self::assertCount(2, $warnings, implode("\n", $lines));
        self::assertStringContainsString('BadDeclaration', $warnings[0]);
        self::assertStringContainsString('UniqueClash', $warnings[1]);
        self::assertCount(2, array_filter($this->logged, static fn (string $line): bool => str_starts_with($line, 'warning: ')));
        $catalog = new SchemaCatalog($this->db());
        self::assertContains('upgrade_good_field', $catalog->indexedFields('entities'));
        self::assertNotContains('upgrade_clash_field', $catalog->indexedFields('entities'));
        self::assertNotContains('upgrade_disabled_field', $catalog->indexedFields('entities'), 'a disabled plugin gets no index');
    }

    /**
     * The plugin phase never turns a successful upgrade into a failure: when another process takes
     * the lock after the schema became current and keeps it for the whole wait, the run is still
     * `current` (exit 0), says once that the plugins' indexes were not checked and that the command
     * can be run again, logs one warning, and reconciles no plugin.
     */
    public function testALockHeldDuringThePluginPhaseLeavesTheRunCurrent(): void
    {
        $this->runner()->step(microtime(true) + 5);
        $connection = $this->db();
        $catalog = new SchemaCatalog($connection);
        $migrator = new SchemaMigrator($connection);
        $copier = new LegacyDataCopier($connection, new SqliteDialect(), new IdentityTypeMap(), new UpgradeLock($connection));
        $other = new UpgradeLock($this->other());
        $calls = 0;
        // The status is read once before the run's step and once after it; the other process takes
        // the lock between the step and the plugin phase.
        $state = new SchemaState(
            new BootConfig(['database' => 'sqlite'], false),
            static function () use ($connection, $other, &$calls): Connection {
                if (++$calls === 2) {
                    self::assertTrue($other->acquire());
                }

                return $connection;
            },
            static fn (): SchemaCatalog => $catalog,
            static fn (): SchemaMigrator => $migrator,
            static fn (): LegacyDataCopier => $copier,
        );
        $this->logged = [];
        $lines = [];

        $result = $this->runner($state, lockWait: 1.0, plugins: ['GoodPlugin'])->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertSame('current', $result['status'], (string) $result['message']);
        self::assertNull($result['message']);
        $notes = array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, 'plugins were not checked')));
        self::assertCount(1, $notes, implode("\n", $lines));
        self::assertStringContainsString('another upgrade process holds the lock', $notes[0]);
        self::assertStringContainsString('php bin/console idno:upgrade', $notes[0]);
        self::assertCount(1, array_filter($this->logged, static fn (string $line): bool => str_starts_with($line, 'warning: ')));
        self::assertNotContains('upgrade_good_field', (new SchemaCatalog($this->db()))->indexedFields('entities'), 'no plugin was reconciled');
    }

    /**
     * Settings that cannot be read (here a settings table of another shape) are a warning for the
     * plugin phase, never "no plugins" silently, and never a failure of the run.
     */
    public function testUnreadableSettingsAreAWarningForThePluginPhase(): void
    {
        $this->runner()->step(microtime(true) + 5);
        $this->db()->executeStatement('drop table idno_settings');
        $this->db()->executeStatement('create table idno_settings (something text)');
        $lines = [];

        $result = $this->runner(plugins: ['GoodPlugin'])->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertSame('current', $result['status']);
        self::assertCount(1, array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'Warning: the enabled plugins could not be read')), implode("\n", $lines));
        self::assertNotContains('upgrade_good_field', (new SchemaCatalog($this->db()))->indexedFields('entities'));
    }

    /**
     * A core declaration that fails holds the marker: the gate stays closed, with the public
     * sentence (the detail is private).
     */
    public function testAFailingCoreDeclarationKeepsTheGateClosed(): void
    {
        $result = $this->runner(core: __DIR__ . '/Fixtures/BadCore')->step(microtime(true) + 5);

        self::assertSame('error', $result['status']);
        self::assertSame(PublicMessage::stopped()->text, $result['message']);
        self::assertStringContainsString('InvalidArgumentException', (string) $result['detail']);
        self::assertSame([self::MARKER], (new SchemaMigrator($this->db()))->pending());
        self::assertSame(SchemaStatus::PendingMigrations, $this->state($this->other())->status());
    }

    /**
     * Renamed 1.x tables with no upgrade on record: the step does nothing at all (not even the
     * baseline on an empty database) and says what to do.
     */
    public function testRenamedOneXTablesWithoutARecordStopEverything(): void
    {
        $result = $this->runner($this->fakeState(['legacy_entities', 'legacy_entities_metadata', 'legacy_entities_search']))->step(microtime(true) + 5);

        self::assertSame('error', $result['status']);
        self::assertSame(PublicMessage::renamedWithoutRecord()->text, $result['message']);
        self::assertSame([], (new SchemaCatalog($this->db()))->tables(), 'nothing was created');
        self::assertSame('error', $this->runner($this->fakeState(['legacy_entities', 'legacy_entities_metadata', 'legacy_entities_search']))->run(static function (string $line): void {
        })['status']);
    }

    public function testATooOldOneXDatabaseIsRefusedAndNothingIsWritten(): void
    {
        $result = $this->runner($this->fakeState(['entities', 'entities_metadata', 'entities_search', 'versions'], legacyVersion: 2019121401))->step(microtime(true) + 5);

        self::assertSame('too_old', $result['status']);
        self::assertSame(PublicMessage::tooOld()->text, $result['message']);
        self::assertStringContainsString('1.6.4', (string) $result['message']);
        self::assertSame([], (new SchemaCatalog($this->db()))->tables());
    }

    public function testAnUnsupportedServerIsRefusedAndNothingIsWritten(): void
    {
        $result = $this->runner($this->fakeState([], server: '5.5.5-10.4.32-MariaDB'))->step(microtime(true) + 5);

        self::assertSame('unsupported', $result['status']);
        self::assertSame('Idno needs MySQL 8.0 or later, or MariaDB 10.6 or later. This database server is MariaDB 10.4.32.', $result['message']);
        self::assertSame([], (new SchemaCatalog($this->db()))->tables());
    }

    public function testAnUnreachableDatabaseIsAnError(): void
    {
        $state = new SchemaState(
            new BootConfig(['database' => 'mysql'], false),
            static fn (): object => new class () {
                public function getServerVersion(): string
                {
                    throw new \RuntimeException('SQLSTATE[HY000] [1045] Access denied for user \'secret\'@\'host\'');
                }

                public function isConnected(): bool
                {
                    return false;
                }
            },
            static fn () => throw new \LogicException('not asked'),
            static fn () => throw new \LogicException('not asked'),
            static fn () => throw new \LogicException('not asked'),
        );

        $result = $this->runner($state)->step(microtime(true) + 5);

        self::assertSame('error', $result['status']);
        self::assertSame(PublicMessage::unreachable()->text, $result['message']);
        self::assertStringNotContainsString('secret', (string) $result['message']);
    }

    /**
     * A step never throws: the failure is logged on the upgrade channel and reported with a message
     * that names the exception's class only (no message, trace or connection detail). Here a 1.x
     * database on SQLite, which the copier refuses.
     */
    public function testAFailureIsReportedNotThrown(): void
    {
        $result = $this->runner($this->fakeState(['entities', 'entities_metadata', 'entities_search', 'versions'], legacyVersion: 2020120301))->step(microtime(true) + 5);

        self::assertSame('error', $result['status']);
        self::assertSame(PublicMessage::stopped()->text, $result['message'], 'nothing of the exception is public');
        self::assertNotNull($result['detail']);
        self::assertStringContainsString('Exception', (string) $result['detail'], 'the private detail names it');
        self::assertCount(1, $this->logged);
        self::assertStringStartsWith('error: The upgrade step failed: ', $this->logged[0]);
        self::assertTrue((new UpgradeLock($this->other()))->acquire(), 'the lock is released after a failure');
    }

    public function testRunReportsWhatItDidAndASecondRunNothing(): void
    {
        $lines = [];
        $result = $this->runner()->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertSame('current', $result['status']);
        self::assertSame('Ran the migration ' . self::BASELINE . '.', $lines[0]);
        self::assertContains('Created the index entities.access.', $lines);
        self::assertContains('Created the index reader.feed_url.', $lines);

        $lines = [];
        $result = $this->runner()->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        self::assertSame('current', $result['status']);
        self::assertSame([], $lines);
    }

    /**
     * run() reconciles the declared indexes even on a current database (a plugin that declares a
     * new indexed field gets its index from idno:upgrade); step() does not.
     */
    public function testRunCreatesAMissingIndexOnACurrentDatabase(): void
    {
        $this->runner()->run(static function (string $line): void {
        });
        $this->db()->executeStatement('drop index idno_entities_ix_access');
        (new SchemaCatalog($this->db()))->forget();

        self::assertSame('current', $this->runner()->step(microtime(true) + 5)['status']);
        self::assertNotContains('access', (new SchemaCatalog($this->db()))->indexedFields('entities'), 'a step leaves a current schema alone');

        $lines = [];
        $this->runner()->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        self::assertSame(['Created the index entities.access.'], $lines);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The real SchemaState of an SQLite site on the connection.
     */
    private function state(Connection $connection): SchemaState
    {
        $catalog = new SchemaCatalog($connection);
        $migrator = new SchemaMigrator($connection);
        $copier = new LegacyDataCopier($connection, new SqliteDialect(), new IdentityTypeMap(), new UpgradeLock($connection));

        return new SchemaState(
            new BootConfig(['database' => 'sqlite'], false),
            static fn (): Connection => $connection,
            static fn (): SchemaCatalog => $catalog,
            static fn (): SchemaMigrator => $migrator,
            static fn (): LegacyDataCopier => $copier,
        );
    }

    /**
     * The number of declared-field indexes on the three collections.
     */
    private function indexCount(): int
    {
        $catalog = new SchemaCatalog($this->db());

        return count($catalog->indexedFields('entities')) + count($catalog->indexedFields('reader')) + count($catalog->indexedFields('site'));
    }

    /**
     * @param list<string> $plugins the enabled plugins (always loaded), of the fixture plugin directory
     */
    private function runner(?SchemaState $state = null, float $lockWait = 60.0, array $plugins = [], ?string $core = null): UpgradeRunner
    {
        $connection = $this->db();
        $catalog = new SchemaCatalog($connection);
        $migrator = new SchemaMigrator($connection);
        $lock = new UpgradeLock($connection);
        $copier = new LegacyDataCopier($connection, new SqliteDialect(), new IdentityTypeMap(), $lock);
        $state ??= new SchemaState(
            new BootConfig(['database' => 'sqlite'], false),
            static fn (): Connection => $connection,
            static fn (): SchemaCatalog => $catalog,
            static fn (): SchemaMigrator => $migrator,
            static fn (): LegacyDataCopier => $copier,
        );
        $logged = &$this->logged;
        $logger = new class ($logged) extends AbstractLogger {
            /** @param list<string> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = $level . ': ' . $message;
            }
        };

        return new UpgradeRunner(
            $state,
            $migrator,
            new IndexedFields($connection, new SqliteDialect(), $catalog),
            $catalog,
            $copier,
            $lock,
            new EnabledPlugins(new BootConfig(['database' => 'sqlite', 'alwaysplugins' => $plugins], false), $connection),
            $logger,
            dirname(__DIR__, 3),
            $lockWait,
            $core,
            __DIR__ . '/Fixtures/Plugins',
        );
    }

    /**
     * A SchemaState for a MySQL site whose server, tables and 1.x version are fakes.
     *
     * @param list<string> $tables
     */
    private function fakeState(array $tables, ?int $legacyVersion = null, string $server = '8.0.46'): SchemaState
    {
        return new SchemaState(
            new BootConfig(['database' => 'mysql'], false),
            static fn (): object => new class ($server) {
                public function __construct(private readonly string $server)
                {
                }

                public function getServerVersion(): string
                {
                    return $this->server;
                }
            },
            static fn (): object => new class ($tables) {
                /** @param list<string> $tables */
                public function __construct(private readonly array $tables)
                {
                }

                /** @return list<string> */
                public function tables(): array
                {
                    return $this->tables;
                }
            },
            static fn (): object => new class () {
                /** @return array{pending: bool, incomplete: bool, complete: bool} */
                public function probe(?string $progressTable = null): array
                {
                    return ['pending' => true, 'incomplete' => false, 'complete' => false];
                }
            },
            static fn (): object => new class ($legacyVersion) {
                public function __construct(private readonly ?int $version)
                {
                }

                public function legacySchemaVersion(): ?int
                {
                    return $this->version;
                }

                public function completion(): ?bool
                {
                    return null;
                }
            },
        );
    }

    private function connect(?StatementRecorder $recorder = null, ?FailingStatementMiddleware $failing = null): Connection
    {
        $middlewares = [new SqliteConnectionSetup()];
        if ($recorder !== null) {
            $middlewares[] = $recorder;
        }
        if ($failing !== null) {
            $middlewares[] = $failing;
        }

        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->directory . '/site.sqlite'], (new Configuration())->setMiddlewares($middlewares));
    }

    private function db(): Connection
    {
        self::assertNotNull($this->connection);

        return $this->connection;
    }

    private function recorder(): StatementRecorder
    {
        self::assertNotNull($this->recorder);

        return $this->recorder;
    }

    /**
     * Another connection to the same database file (another process, as far as the lock goes).
     */
    private function other(): Connection
    {
        return $this->others[] = $this->connect();
    }
}
