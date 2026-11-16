<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Config\BootConfig;
use Idno\Storage\AttributeTypeMap;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\Platform\MySqlDialect;
use Idno\Storage\SchemaCatalog;
use Idno\Upgrade\LegacyDataCopier;
use Idno\Upgrade\LegacyTables;
use Idno\Upgrade\UpgradeLock;
use Idno\Upgrade\UpgradeProgress;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Storage\FailingStatementMiddleware;

/**
 * What a completed upgrade of a 1.x database leaves behind (LegacyTables): the 1.x tables renamed
 * `legacy_<name>`, the rejects, and 1.x tables an old process recreated; and deleting the legacy
 * tables. On a private database of the rig's MySQL or MariaDB server (LegacyFixture), dropped after
 * each test; skipped on an SQLite rig: no 1.x site runs on SQLite (the empty and non-SQL cases are in
 * Tests/Unit/Upgrade/LegacyTablesTest).
 */
final class LegacyTablesTest extends TestCase
{
    private const FAR = 120.0;

    private ?LegacyFixture $fixture = null;

    /** @var list<Connection> */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
        if ($this->fixture !== null) {
            $this->fixture->drop();
            $this->fixture = null;
        }
    }

    public function testPresentListsTheRenamedTablesWithCounts(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 3);
        $fixture->insert('entities', ['title' => 'With metadata', 'tags' => ['a', 'b']]);
        $fixture->insert('config', ['title' => 'A 1.x site', 'created' => 1_700_000_000]);
        $this->complete();

        $legacy = $this->legacyTables();
        $present = $legacy->present();

        $expected = [];
        foreach (LegacyDataCopier::LEGACY_TABLES as $table) {
            $expected['legacy_' . $table] = (int) $fixture->connection->fetchOne('select count(*) from ' . $fixture->connection->quoteSingleIdentifier('legacy_' . $table));
        }
        self::assertSame($expected, $present, 'every renamed table, in the order of LEGACY_TABLES, with its row count');
        self::assertSame(4, $present['legacy_entities']);
        self::assertSame(1, $present['legacy_config']);
        self::assertGreaterThan(0, $present['legacy_entities_metadata']);
        self::assertSame(array_keys($expected), $legacy->names());
        self::assertTrue($legacy->isComplete());
        self::assertTrue($legacy->worthShowing());
        self::assertSame([], $legacy->reappeared());
    }

    public function testDropRemovesOnlyTheKnownLegacyTables(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 5);
        $this->complete();
        $db = $fixture->connection;
        $db->executeStatement('create table legacy_other (id int primary key)');
        $db->executeStatement('create table legacy_entities_extra (id int primary key)');
        $idno = array_values(array_filter($this->tables(), static fn (string $table): bool => str_starts_with($table, 'idno_')));
        $copied = (int) $db->fetchOne('select count(*) from idno_entities');
        self::assertSame(5, $copied);

        self::assertSame(count(LegacyDataCopier::LEGACY_TABLES), $this->legacyTables()->drop());

        $tables = $this->tables();
        foreach (LegacyDataCopier::LEGACY_TABLES as $table) {
            self::assertNotContains('legacy_' . $table, $tables);
        }
        self::assertContains('legacy_other', $tables, 'a legacy_ table of another name survives');
        self::assertContains('legacy_entities_extra', $tables);
        self::assertSame($idno, array_values(array_filter($tables, static fn (string $table): bool => str_starts_with($table, 'idno_'))), 'every idno_ table survives');
        self::assertSame($copied, (int) $db->fetchOne('select count(*) from idno_entities'));
        self::assertTrue($this->copier()->progress()->complete, 'the progress rows are kept: the upgrade stays complete');

        $legacy = $this->legacyTables();
        self::assertSame(0, $legacy->drop(), 'nothing left to drop');
        self::assertSame([], $legacy->present());
        self::assertFalse($legacy->worthShowing(), 'no legacy tables, no rejects, nothing reappeared');
    }

    public function testDropRefusesBeforeTheUpgradeIsComplete(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 5);
        self::assertSame(0, $this->legacyTables()->drop(), 'a 1.x database not yet renamed has no legacy tables');

        $copier = $this->copier();
        for ($i = 0; $i < 50 && $copier->progress()->phase !== UpgradeProgress::VERIFYING; ++$i) {
            $copier->step(microtime(true) - 1);
        }
        self::assertSame(UpgradeProgress::VERIFYING, $copier->progress()->phase);
        $before = $this->tables();

        $legacy = $this->legacyTables();
        self::assertFalse($legacy->isComplete());
        try {
            $legacy->drop();
            self::fail('drop() refuses while the upgrade is not complete.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('not complete', $e->getMessage());
        }
        self::assertSame($before, $this->tables(), 'nothing was dropped');
    }

    public function testRejectsAreListedAndSurviveTheDrop(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 2);
        $bad = $fixture->insert('entities', ['title' => 'Not JSON'], [], 'this is <b>not</b> JSON');
        $deep = $fixture->insert('entities', ['title' => 'Not an object'], [], '"a string"');
        $this->complete();

        $legacy = $this->legacyTables();
        self::assertSame(2, $legacy->rejectCount());
        $rejects = $legacy->rejects();
        self::assertCount(2, $rejects);
        foreach ($rejects as $reject) {
            self::assertSame(['n', 'collection', 'legacy_uuid', 'reason'], array_keys($reject), 'never the contents');
            self::assertSame('entities', $reject['collection']);
        }
        self::assertEqualsCanonicalizing([$bad['uuid'], $deep['uuid']], array_column($rejects, 'legacy_uuid'));
        self::assertContains('contents are not valid JSON', array_column($rejects, 'reason'));
        self::assertContains('contents are not a JSON object or array', array_column($rejects, 'reason'));
        self::assertLessThan($rejects[1]['n'], $rejects[0]['n'], 'oldest first');
        self::assertSame([$rejects[1]], $legacy->rejects(1, 1), 'a page');
        self::assertSame([], $legacy->rejects(10, 2), 'past the end');

        self::assertSame(count(LegacyDataCopier::LEGACY_TABLES), $legacy->drop());

        $after = $this->legacyTables();
        self::assertSame(2, $after->rejectCount(), 'the rejects are never deleted');
        self::assertSame($rejects, $after->rejects());
        self::assertSame('this is <b>not</b> JSON', $fixture->connection->fetchOne('select contents from idno_upgrade_rejects where legacy_uuid = ?', [$bad['uuid']]), 'with their contents');
        self::assertTrue($after->worthShowing(), 'rejects alone keep the page in the menu');
        self::assertSame([], $after->present());
    }

    public function testARejectsPageIsBounded(): void
    {
        $this->fixture();
        $legacy = $this->legacyTables();
        foreach ([[0, 0], [LegacyTables::MAX_LIMIT + 1, 0], [10, -1]] as [$limit, $offset]) {
            try {
                $legacy->rejects($limit, $offset);
                self::fail('rejects(' . $limit . ', ' . $offset . ') is refused');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * The 1.x child tables reference their collection's table: they are dropped first, so the
     * foreign-key checks stay on. A failure part-way leaves the rest, and the next drop() finishes.
     */
    public function testADropThatFailsPartWayIsFinishedByTheNext(): void
    {
        $this->fixture()->insertMany('entities', 5);
        $this->complete();
        $failing = new FailingStatementMiddleware();
        $connection = $this->connect($failing);
        $failing->failOn('/^drop table/', 3);

        try {
            $this->legacyTables($connection)->drop();
            self::fail('The failing DROP ends the drop.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('disk I/O error', $e->getMessage());
        }
        $left = array_values(array_filter($this->tables(), static fn (string $table): bool => str_starts_with($table, 'legacy_')));
        self::assertCount(count(LegacyDataCopier::LEGACY_TABLES) - 2, $left, 'two tables were dropped before the failure');
        self::assertNotContains('legacy_entities_metadata', $left, 'children first');
        self::assertNotContains('legacy_entities_search', $left);
        self::assertContains('legacy_entities', $left);

        self::assertSame(count(LegacyDataCopier::LEGACY_TABLES) - 2, $this->legacyTables()->drop());
        self::assertSame([], array_values(array_filter($this->tables(), static fn (string $table): bool => str_starts_with($table, 'legacy_'))));
    }

    /**
     * 1.x tables an old process created again after completion are listed, never dropped.
     */
    public function testReappearedTablesAreListedAndNeverDropped(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 2);
        $this->complete();
        $this->legacyTables()->drop();
        self::assertFalse($this->legacyTables()->worthShowing());
        foreach (['entities', 'versions'] as $table) {
            $fixture->connection->executeStatement('create table ' . $table . ' (id int primary key)');
        }

        $legacy = $this->legacyTables();
        self::assertSame(['entities', 'versions'], $legacy->reappeared());
        self::assertTrue($legacy->worthShowing());
        self::assertSame(0, $legacy->drop());
        self::assertContains('entities', $this->tables());
        self::assertContains('versions', $this->tables());
    }

    private function fixture(): LegacyFixture
    {
        $this->fixture = LegacyFixture::create('legacy_tables');
        $this->fixture->prepareNewSchema();

        return $this->fixture;
    }

    private function connection(): Connection
    {
        self::assertNotNull($this->fixture);

        return $this->fixture->connection;
    }

    private function connect(FailingStatementMiddleware $middleware): Connection
    {
        $connection = DriverManager::getConnection($this->connection()->getParams(), (new Configuration())->setMiddlewares([$middleware]));

        return $this->connections[] = $connection;
    }

    private function copier(?Connection $connection = null): LegacyDataCopier
    {
        $connection ??= $this->connection();

        return new LegacyDataCopier($connection, new MySqlDialect(), new AttributeTypeMap(), new UpgradeLock($connection));
    }

    private function complete(): void
    {
        $copier = $this->copier();
        for ($i = 0; $i < 30; ++$i) {
            if ($copier->step(microtime(true) + self::FAR)->complete) {
                return;
            }
        }
        self::fail('The upgrade did not complete: ' . json_encode($copier->progress()));
    }

    /**
     * LegacyTables as the container builds it on a MySQL site, on the private database.
     */
    private function legacyTables(?Connection $connection = null): LegacyTables
    {
        $connection ??= $this->connection();
        $catalog = new SchemaCatalog($connection);
        $copier = $this->copier($connection);

        return new LegacyTables(
            new BootConfig(['database' => 'mysql'], false),
            static fn (): Connection => $connection,
            static fn (): SchemaCatalog => $catalog,
            static fn (): LegacyDataCopier => $copier,
            static fn (): Dialect => new MySqlDialect(),
        );
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return (new SchemaCatalog($this->connection()))->tables();
    }
}
