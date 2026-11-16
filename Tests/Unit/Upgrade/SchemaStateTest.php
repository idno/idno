<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use Idno\Config\BootConfig;
use Idno\Upgrade\LegacyDataCopier;
use Idno\Upgrade\SchemaGate;
use Idno\Upgrade\SchemaState;
use Idno\Upgrade\SchemaStatus;
use Idno\Upgrade\ServerVersion;
use PHPUnit\Framework\TestCase;

/**
 * What SchemaState makes of a database, with fakes for the services its closures return (they
 * answer only what SchemaState asks: whether connecting failed, the server version, the tables, the
 * migrator's probe (whether a migration is pending and whether an upgrade is on record: complete or
 * not, in one statement) and the 1.x schema version). Counts what was asked, so a test can say that
 * something was NOT asked.
 */
final class SchemaStateTest extends TestCase
{
    /** @var array<string, int> */
    private array $asked = [];

    // ------------------------------------------------------------------ one test per status

    public function testAMongoSiteFetchesNothing(): void
    {
        foreach (['mongo', 'MongoDB', 'mongodb'] as $database) {
            $state = new SchemaState(
                new BootConfig(['database' => $database], false),
                self::forbidden('connection'),
                self::forbidden('catalog'),
                self::forbidden('migrator'),
                self::forbidden('copier'),
            );

            self::assertSame(SchemaStatus::NotApplicable, $state->status(), $database);
        }
    }

    public function testAnotherBackendClassIsNotApplicable(): void
    {
        $state = new SchemaState(
            new BootConfig(['database' => \Tests\Unit\Storage\PluginBackendFixture::class], false),
            self::forbidden('connection'),
            self::forbidden('catalog'),
            self::forbidden('migrator'),
            self::forbidden('copier'),
        );

        self::assertSame(SchemaStatus::NotApplicable, $state->status());
    }

    public function testAnUnreachableDatabaseIsNotTheGatesBusiness(): void
    {
        foreach (['mysql', 'sqlite'] as $database) {
            $connection = static function (): object {
                return new class () {
                    public function getServerVersion(): string
                    {
                        throw new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
                    }

                    public function isConnected(): bool
                    {
                        return false;
                    }
                };
            };
            $state = new SchemaState(new BootConfig(['database' => $database], false), $connection, self::forbidden('catalog'), self::forbidden('migrator'), self::forbidden('copier'));

            self::assertSame(SchemaStatus::Unreachable, $state->status(), $database);
            self::assertFalse($state->status()->closesTheGate());
        }
    }

    public function testAnEmptyDatabase(): void
    {
        foreach (['mysql', 'sqlite'] as $database) {
            self::assertSame(SchemaStatus::Empty, $this->state($database, [])->status(), $database);
        }
        self::assertSame(SchemaStatus::Empty, $this->state('mysql', ['wp_posts', 'session', 'config'])->status(), 'another application\'s tables');
    }

    public function testACurrentDatabase(): void
    {
        foreach (['mysql', 'sqlite'] as $database) {
            $this->asked = [];
            $state = $this->state($database, self::NEW_SCHEMA);

            self::assertSame(SchemaStatus::Current, $state->status(), $database);
            self::assertSame(['version' => 1, 'tables' => 1, 'probe' => 1], $this->asked, 'a current database: the catalog and the one probe');
        }
    }

    public function testPendingMigrations(): void
    {
        foreach (['mysql', 'sqlite'] as $database) {
            self::assertSame(SchemaStatus::PendingMigrations, $this->state($database, self::NEW_SCHEMA, pending: true)->status(), $database);
        }
    }

    public function testAOneXDatabase(): void
    {
        $state = $this->state('mysql', ['config', 'entities', 'entities_metadata', 'entities_search', 'session', 'versions'], legacyVersion: 2020120301);
        self::assertSame(SchemaStatus::Legacy, $state->status());

        $state = $this->state('mysql', ['entities', 'entities_metadata', 'entities_search', 'versions', ...self::NEW_SCHEMA], legacyVersion: 2026111401);
        self::assertSame(SchemaStatus::Legacy, $state->status(), 'the new schema made, the copy not begun');
    }

    public function testATooOldOneXDatabase(): void
    {
        foreach ([2019121401, null] as $version) {
            $state = $this->state('mysql', ['entities', 'entities_metadata', 'entities_search', 'versions'], legacyVersion: $version);

            self::assertSame(SchemaStatus::LegacyTooOld, $state->status(), (string) $version);
            self::assertTrue($state->status()->closesTheGate());
        }
    }

    public function testAnUpgradeThatHasBegunStaysLegacyWhateverTheVersionSays(): void
    {
        $state = $this->state('mysql', ['entities', 'entities_metadata', 'entities_search', ...self::NEW_SCHEMA], legacyVersion: null, completion: false);

        self::assertSame(SchemaStatus::Legacy, $state->status());
    }

    /**
     * An upgrade on record and not complete keeps the gate closed whatever the tables look like: in
     * the verify phase with the renamed tables, with one of them or all of them dropped by hand, in
     * the copy phase with a 1.x table dropped. The probe says so, with no question beyond it.
     */
    public function testAnUpgradeOnRecordAndNotCompleteIsAlwaysClosed(): void
    {
        $renamed = ['legacy_config', 'legacy_entities', 'legacy_entities_metadata', 'legacy_entities_search', 'legacy_versions'];
        foreach ([
            'verify phase' => $renamed,
            'verify phase, legacy_entities_search dropped' => array_values(array_diff($renamed, ['legacy_entities_search'])),
            'verify phase, every legacy_ table dropped' => [],
            'copy phase, entities_metadata dropped' => ['entities', 'entities_search', 'config', 'versions'],
        ] as $case => $tables) {
            $this->asked = [];
            self::assertSame(SchemaStatus::Legacy, $this->state('mysql', [...$tables, ...self::NEW_SCHEMA], completion: false)->status(), $case);
            self::assertSame(['version' => 1, 'tables' => 1, 'probe' => 1], $this->asked, $case . ': the probe alone');
        }
    }

    /**
     * A complete upgrade: its legacy_ tables, or none (the admin deleted them), or 1.x tables an old
     * worker recreated: open, and nothing beyond the probe is asked. A 1.x process booting after the
     * rename re-creates `reader`, `versions`, `entities_search`, `entities_metadata`, `site`,
     * `site_metadata` and `site_search`, never `entities`: two of the three `entities` tables, so the
     * rule must need all three (see the next test).
     */
    public function testACompleteUpgradeIsOpenWhateverItsLegacyTables(): void
    {
        $reappeared = ['reader', 'versions', 'entities_search', 'entities_metadata', 'site', 'site_metadata', 'site_search'];
        foreach ([
            'legacy_ tables kept' => ['legacy_entities', 'legacy_entities_metadata', 'legacy_entities_search', 'legacy_versions'],
            'legacy_ tables deleted' => [],
            'what an old worker creates after completion' => $reappeared,
            'another two of the three, and other 1.x names' => ['entities', 'entities_metadata', 'reader_metadata', 'reader_search', 'config', 'session'],
        ] as $case => $tables) {
            $this->asked = [];
            self::assertSame(SchemaStatus::Current, $this->state('mysql', [...$tables, ...self::NEW_SCHEMA], completion: true)->status(), $case);
            self::assertSame(['version' => 1, 'tables' => 1, 'probe' => 1], $this->asked, $case);
        }
        self::assertSame(SchemaStatus::Legacy, $this->state('mysql', [...$reappeared, ...self::NEW_SCHEMA], completion: false)->status(), 'before completion it is not ignored');
    }

    /**
     * Another application's tables with names a 1.x database also uses never close the gate and cost
     * nothing beyond the probe: a 1.x database is `entities`, `entities_metadata` AND
     * `entities_search`; `legacy_`-named tables ask nothing at all.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function foreignTables(): iterable
    {
        yield 'entities' => [['entities']];
        yield 'entities and entities_metadata' => [['entities', 'entities_metadata']];
        yield 'versions' => [['versions']];
        yield 'entities and versions' => [['entities', 'versions']];
        yield 'config, session, site, reader' => [['config', 'session', 'site', 'reader']];
        yield 'legacy_config' => [['legacy_config']];
        yield 'legacy_config, legacy_session, legacy_site, legacy_versions' => [['legacy_config', 'legacy_session', 'legacy_site', 'legacy_versions']];
        yield 'legacy_entities and legacy_entities_search only' => [['legacy_entities', 'legacy_entities_search']];
    }

    /**
     * @dataProvider foreignTables
     *
     * @param list<string> $foreign
     */
    public function testForeignTablesNeverCloseTheGate(array $foreign): void
    {
        $this->asked = [];
        self::assertSame(SchemaStatus::Current, $this->state('mysql', [...$foreign, ...self::NEW_SCHEMA], legacyVersion: 2019010101)->status());
        self::assertSame(['version' => 1, 'tables' => 1, 'probe' => 1], $this->asked, 'nothing more is asked');

        $this->asked = [];
        self::assertSame(SchemaStatus::Empty, $this->state('mysql', $foreign, legacyVersion: 2019010101)->status());
        self::assertSame(['version' => 1, 'tables' => 1], $this->asked);
    }

    /**
     * The three renamed 1.x `entities` tables with NO upgrade on record (no progress row at all):
     * someone dropped the new tables to start again without renaming the 1.x ones back. Closed, so
     * that the first page load does not set up an empty site beside the content. With a complete
     * upgrade on record they are open as before. The cost is unchanged: nothing beyond the catalog on
     * an empty database, the probe on a migrated one.
     */
    public function testRenamedOneXTablesWithoutAnUpgradeOnRecordCloseTheGate(): void
    {
        $renamed = ['legacy_entities', 'legacy_entities_metadata', 'legacy_entities_search', 'legacy_config'];

        $this->asked = [];
        self::assertSame(SchemaStatus::RenamedWithoutRecord, $this->state('mysql', $renamed)->status(), 'an empty database');
        self::assertSame(['version' => 1, 'tables' => 1], $this->asked);
        self::assertTrue(SchemaStatus::RenamedWithoutRecord->closesTheGate());

        $this->asked = [];
        self::assertSame(SchemaStatus::RenamedWithoutRecord, $this->state('mysql', [...$renamed, ...self::NEW_SCHEMA])->status(), 'a migrated database with no progress row');
        self::assertSame(['version' => 1, 'tables' => 1, 'probe' => 1], $this->asked);

        self::assertSame(SchemaStatus::Current, $this->state('mysql', [...$renamed, ...self::NEW_SCHEMA], completion: true)->status(), 'a complete upgrade');
        self::assertSame(SchemaStatus::Legacy, $this->state('mysql', [...$renamed, ...self::NEW_SCHEMA], completion: false)->status(), 'an upgrade in progress');
    }

    /**
     * After a COMPLETE upgrade, `entities`, `entities_metadata` AND `entities_search` present again
     * mean an undo or a restored backup (a booting 1.x process re-creates the last two, never
     * `entities`): closed, with
     * its own status, decided from the catalog and the probe alone. SQLite never asks about 1.x
     * tables.
     */
    public function testTheOneXEntitiesTablesBesideACompletedUpgradeCloseTheGate(): void
    {
        $restored = ['entities', 'entities_metadata', 'entities_search', 'versions'];
        foreach (['legacy_ tables deleted' => [], 'legacy_ tables kept' => ['legacy_entities', 'legacy_entities_metadata', 'legacy_entities_search']] as $case => $legacy) {
            $this->asked = [];
            self::assertSame(SchemaStatus::OneXBesideCompletedUpgrade, $this->state('mysql', [...$restored, ...$legacy, ...self::NEW_SCHEMA], completion: true, legacyVersion: 2020120301)->status(), $case);
            self::assertSame(['version' => 1, 'tables' => 1, 'probe' => 1], $this->asked, $case . ': nothing beyond the probe');
        }
        self::assertTrue(SchemaStatus::OneXBesideCompletedUpgrade->closesTheGate());
        self::assertTrue(LegacyDataCopier::isOneXBesideCompletedUpgrade(['entities', 'entities_metadata', 'entities_search'], true));
        self::assertFalse(LegacyDataCopier::isOneXBesideCompletedUpgrade(['entities', 'entities_metadata', 'entities_search'], false));
        self::assertFalse(LegacyDataCopier::isOneXBesideCompletedUpgrade(['entities', 'entities_metadata'], true));
        self::assertSame(SchemaStatus::Current, $this->state('sqlite', [...$restored, ...self::NEW_SCHEMA], completion: true)->status(), 'SQLite never asks about 1.x tables');
    }

    public function testTheEvidenceRule(): void
    {
        self::assertTrue(LegacyDataCopier::isOneXDatabase(['config', 'entities', 'entities_metadata', 'entities_search']));
        self::assertFalse(LegacyDataCopier::isOneXDatabase(['entities', 'entities_metadata']));
        self::assertTrue(LegacyDataCopier::isRenamedOneXDatabase(['legacy_entities', 'legacy_entities_metadata', 'legacy_entities_search']));
        self::assertFalse(LegacyDataCopier::isRenamedOneXDatabase(['legacy_config', 'legacy_entities', 'legacy_versions']));
    }

    public function testAnSqliteSiteNeverAsksAboutOneXTables(): void
    {
        $state = $this->state('sqlite', ['entities', 'entities_metadata', 'legacy_entities', ...self::NEW_SCHEMA]);

        self::assertSame(SchemaStatus::Current, $state->status());
        self::assertArrayNotHasKey('legacyVersion', $this->asked);
    }

    /**
     * Only a failure to CONNECT is Unreachable (the gate opens for the legacy error page). A
     * connection that connects and then fails to read the version is an error: thrown, never an open
     * gate on a database nobody checked.
     */
    public function testAFailureAfterConnectingIsThrown(): void
    {
        $connection = new class () {
            public function getServerVersion(): string
            {
                throw new \RuntimeException('the version read failed');
            }

            public function isConnected(): bool
            {
                return true;
            }
        };
        $state = new SchemaState(new BootConfig(['database' => 'mysql'], false), static fn (): object => $connection, self::forbidden('catalog'), self::forbidden('migrator'), self::forbidden('copier'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the version read failed');
        $state->status();
    }

    /**
     * The same with a real connection: it connects, then reading the version fails.
     */
    public function testARealConnectionThatConnectsAndThenFailsIsNotUnreachable(): void
    {
        $failingVersion = new class () implements \Doctrine\DBAL\Driver\Middleware {
            public function wrap(\Doctrine\DBAL\Driver $driver): \Doctrine\DBAL\Driver
            {
                return new class ($driver) extends \Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware {
                    public function connect(#[\SensitiveParameter] array $params): \Doctrine\DBAL\Driver\Connection
                    {
                        return new class (parent::connect($params)) extends \Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware {
                            public function getServerVersion(): string
                            {
                                throw new \RuntimeException('the version read failed');
                            }
                        };
                    }
                };
            }
        };
        $connection = \Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new \Doctrine\DBAL\Configuration())->setMiddlewares([new \Idno\Storage\Platform\SqliteConnectionSetup(), $failingVersion, new \Idno\Storage\Platform\ConnectFailureIsFinal()]));
        $state = new SchemaState(new BootConfig(['database' => 'sqlite'], false), static fn (): object => $connection, self::forbidden('catalog'), self::forbidden('migrator'), self::forbidden('copier'));

        try {
            $state->status();
            self::fail('A failure after connecting is thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('the version read failed', $e->getMessage());
        }
        self::assertTrue($connection->isConnected());
    }

    public function testAnUnsupportedServerIsRefusedBeforeAnythingElseIsAsked(): void
    {
        $this->asked = [];
        $state = $this->state('mysql', ['entities', 'entities_metadata'], server: '5.7.44');

        self::assertSame(SchemaStatus::UnsupportedServer, $state->status());
        self::assertTrue($state->status()->closesTheGate());
        self::assertSame('Idno needs MySQL 8.0 or later, or MariaDB 10.6 or later. This database server is MySQL 5.7.44.', $state->refusal()?->text);
        self::assertArrayNotHasKey('tables', $this->asked);

        $state = $this->state('sqlite', self::NEW_SCHEMA, server: '3.12.0');
        self::assertSame(SchemaStatus::Current, $state->status(), 'SQLite\'s floor is the connection setup\'s');
        self::assertNull($state->refusal());
    }

    // ------------------------------------------------------------------ the version rule

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function serverVersions(): iterable
    {
        yield 'MySQL 8.0.46' => ['8.0.46', null];
        yield 'MySQL 8.4.3' => ['8.4.3', null];
        yield 'MySQL 9.1.0' => ['9.1.0', null];
        yield 'MySQL 5.7.44' => ['5.7.44', 'This database server is MySQL 5.7.44.'];
        yield 'MySQL 5.7.44-log' => ['5.7.44-log', 'This database server is MySQL 5.7.44.'];
        yield 'MariaDB 10.6.28 as its server reports it' => ['10.6.28-MariaDB-ubu2004', null];
        yield 'MariaDB 11.4.2' => ['11.4.2-MariaDB', null];
        yield 'MariaDB 10.6 behind the replication prefix' => ['5.5.5-10.6.28-MariaDB-ubu2004', null];
        yield 'MariaDB 10.4.32 behind the replication prefix' => ['5.5.5-10.4.32-MariaDB', 'This database server is MariaDB 10.4.32.'];
        yield 'MariaDB 10.5.27' => ['10.5.27-MariaDB-log', 'This database server is MariaDB 10.5.27.'];
        yield 'unreadable: not refused' => ['unknown', null];
    }

    /**
     * @dataProvider serverVersions
     */
    public function testTheVersionRule(string $reported, ?string $found): void
    {
        $refusal = ServerVersion::refusal($reported);

        if ($found === null) {
            self::assertNull($refusal);
        } else {
            self::assertSame(ServerVersion::REQUIREMENT . ' ' . $found, $refusal?->text);
        }
    }

    /**
     * The sentence is built from the parsed product and version, never from free text.
     */
    public function testTheRefusalIsBuiltFromItsParts(): void
    {
        self::assertSame(ServerVersion::REQUIREMENT . ' This database server is MariaDB 10.4.32.', \Idno\Upgrade\PublicMessage::unsupportedServer('MariaDB', '10.4.32')->text);
        foreach ([['PostgreSQL', '9.6'], ['MySQL', '5.7 <b>'], ['MySQL', '']] as [$product, $version]) {
            try {
                \Idno\Upgrade\PublicMessage::unsupportedServer($product, $version);
                self::fail($product . ' ' . $version);
            } catch (\InvalidArgumentException) {
            }
        }
    }

    // ------------------------------------------------------------------ the gate

    public function testTheGateComputesTheStatusOnceAndOnlyWhenAsked(): void
    {
        $this->asked = [];
        $gate = new SchemaGate($this->state('mysql', []));

        self::assertFalse($gate->asked());
        self::assertSame([], $this->asked, 'constructing it asks nothing');
        self::assertTrue($gate->closed());
        self::assertTrue($gate->asked());
        self::assertSame(SchemaStatus::Empty, $gate->status());
        self::assertTrue($gate->closed());
        self::assertSame(['version' => 1, 'tables' => 1], $this->asked);
    }

    public function testTheGateIsOpenOnlyForCurrentUnreachableAndNotApplicable(): void
    {
        $open = array_values(array_filter(SchemaStatus::cases(), static fn (SchemaStatus $status): bool => !$status->closesTheGate()));

        self::assertSame([SchemaStatus::NotApplicable, SchemaStatus::Unreachable, SchemaStatus::Current], $open);
    }

    // ------------------------------------------------------------------ fakes

    private const NEW_SCHEMA = ['idno_entities', 'idno_migrations', 'idno_reader', 'idno_sessions', 'idno_settings', 'idno_site', 'idno_upgrade_progress', 'idno_upgrade_rejects'];

    /**
     * @param list<string> $tables
     */
    private function state(string $database, array $tables, bool $pending = false, ?int $legacyVersion = null, ?bool $completion = null, string $server = '8.0.46'): SchemaState
    {
        $asked = &$this->asked;
        $count = static function (string $what) use (&$asked): void {
            $asked[$what] = ($asked[$what] ?? 0) + 1;
        };

        return new SchemaState(
            new BootConfig(['database' => $database], false),
            static fn (): object => new class ($server, $count) {
                public function __construct(private readonly string $server, private readonly \Closure $count)
                {
                }

                public function getServerVersion(): string
                {
                    ($this->count)('version');

                    return $this->server;
                }
            },
            static fn (): object => new class ($tables, $count) {
                /** @param list<string> $tables */
                public function __construct(private readonly array $tables, private readonly \Closure $count)
                {
                }

                /** @return list<string> */
                public function tables(): array
                {
                    ($this->count)('tables');

                    return $this->tables;
                }
            },
            static fn (): object => new class ($pending, $completion, $count) {
                public function __construct(private readonly bool $pending, private readonly ?bool $completion, private readonly \Closure $count)
                {
                }

                /** @return array{pending: bool, incomplete: bool, complete: bool} */
                public function probe(?string $progressTable = null): array
                {
                    ($this->count)('probe');

                    return [
                        'pending' => $this->pending,
                        'incomplete' => $progressTable !== null && $this->completion === false,
                        'complete' => $progressTable !== null && $this->completion === true,
                    ];
                }
            },
            static fn (): object => new class ($legacyVersion, $completion, $count) {
                public function __construct(private readonly ?int $version, private readonly ?bool $completion, private readonly \Closure $count)
                {
                }

                public function legacySchemaVersion(): ?int
                {
                    ($this->count)('legacyVersion');

                    return $this->version;
                }

                public function completion(): ?bool
                {
                    ($this->count)('completion');

                    return $this->completion;
                }
            },
        );
    }

    private static function forbidden(string $what): \Closure
    {
        return static function () use ($what): never {
            throw new \LogicException('The ' . $what . ' must not be fetched.');
        };
    }
}
