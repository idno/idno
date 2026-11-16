<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Config\BootConfig;
use Idno\Storage\IdentityTypeMap;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use Idno\Upgrade\LegacyDataCopier;
use Idno\Upgrade\LegacyTables;
use Idno\Upgrade\UpgradeLock;
use PHPUnit\Framework\TestCase;
use Tests\Storage\StatementRecorder;

/**
 * LegacyTables where there is never a 1.x database: a site whose database is not SQL (nothing is
 * fetched) and an SQLite site (in memory, migrated). And what each question costs in statements.
 * The 1.x cases, on MySQL and MariaDB, are in Tests/Upgrade/LegacyTablesTest.
 */
final class LegacyTablesTest extends TestCase
{
    public function testNothingIsFetchedOnASiteWhoseDatabaseIsNotSql(): void
    {
        $never = static function (): never {
            throw new \LogicException('Nothing may be fetched on a site whose database is not SQL.');
        };
        foreach (['mongodb', 'mongo'] as $database) {
            $legacy = new LegacyTables(new BootConfig(['database' => $database], false), $never, $never, $never, $never);

            self::assertFalse($legacy->available());
            self::assertSame([], $legacy->names());
            self::assertSame([], $legacy->present());
            self::assertSame([], $legacy->rejects());
            self::assertSame(0, $legacy->rejectCount());
            self::assertSame([], $legacy->reappeared());
            self::assertFalse($legacy->isComplete());
            self::assertFalse($legacy->worthShowing());
            self::assertSame(0, $legacy->drop());
        }
    }

    public function testAnSqliteSiteHasNothingToShow(): void
    {
        [$connection, $recorder, $catalog] = $this->sqlite();
        $legacy = $this->legacyTables($connection, $catalog);

        self::assertTrue($legacy->available());
        self::assertSame([], $legacy->names());
        self::assertSame([], $legacy->present());
        self::assertSame([], $legacy->rejects());
        self::assertSame(0, $legacy->rejectCount());
        self::assertSame([], $legacy->reappeared(), 'no 1.x site runs on SQLite');
        self::assertFalse($legacy->isComplete());
        self::assertSame(0, $legacy->drop());

        $catalog->forget();
        $catalog->tables(); // read at boot by the schema gate, in a request
        $recorder->statements = [];
        self::assertFalse($legacy->worthShowing());
        self::assertCount(1, $recorder->statements, 'one probe of the rejects: ' . json_encode($recorder->statements));
        self::assertMatchesRegularExpression('/^select 1 from "idno_upgrade_rejects" limit 1$/', $recorder->statements[0]['sql']);
    }

    public function testTheMenuCostsNoStatementWhileLegacyTablesExist(): void
    {
        [$connection, $recorder, $catalog] = $this->sqlite();
        $connection->executeStatement('create table legacy_entities (_id text primary key)');
        $catalog->forget();
        $catalog->tables();
        $recorder->statements = [];

        self::assertTrue($this->legacyTables($connection, $catalog)->worthShowing());
        self::assertSame([], $recorder->statements);
    }

    /**
     * The rules do not depend on the database: legacy tables a completed upgrade left are dropped
     * (children first), the rejects and progress rows stay.
     */
    public function testACompletedUpgradesLegacyTablesAreDroppedAndTheRejectsKept(): void
    {
        [$connection, , $catalog] = $this->sqlite();
        $connection->executeStatement('create table legacy_entities (_id text primary key)');
        $connection->executeStatement('create table legacy_entities_metadata (_id text references legacy_entities (_id))');
        $connection->executeStatement('insert into legacy_entities values (?)', ['a']);
        $connection->executeStatement('insert into legacy_entities_metadata values (?)', ['a']);
        $connection->executeStatement('create table legacy_unrelated (id integer)');
        $connection->executeStatement(
            "insert into idno_upgrade_rejects (collection, legacy_uuid, legacy_id, reason, contents) values ('entities', 'https://example.com/view/<b>', 'x', 'contents are not valid JSON', 'nope')",
        );
        $legacy = $this->legacyTables($connection, $catalog);
        $catalog->forget();

        self::assertSame(['legacy_entities' => 1, 'legacy_entities_metadata' => 1], $legacy->present());
        try {
            $legacy->drop();
            self::fail('No upgrade on record: nothing is dropped.');
        } catch (\LogicException) {
            self::assertSame(['legacy_entities', 'legacy_entities_metadata'], $legacy->names());
        }

        foreach (LegacyDataCopier::COLLECTIONS as $collection) {
            $connection->executeStatement(
                'insert into idno_upgrade_progress (collection, source_rows, source_existed, copied, rejected, completed_at) values (?, 0, 1, 0, 0, 1700000000)',
                [$collection],
            );
        }
        self::assertTrue($legacy->isComplete());
        self::assertSame(2, $legacy->drop());

        self::assertSame([], $legacy->names());
        self::assertContains('legacy_unrelated', $catalog->tables());
        self::assertSame(1, $legacy->rejectCount());
        self::assertSame([['n' => 1, 'collection' => 'entities', 'legacy_uuid' => 'https://example.com/view/<b>', 'reason' => 'contents are not valid JSON']], $legacy->rejects());
        self::assertTrue($legacy->worthShowing(), 'the rejects');
        self::assertSame(4, (int) $connection->fetchOne('select count(*) from idno_upgrade_progress'));
    }

    /**
     * @return array{0: Connection, 1: StatementRecorder, 2: SchemaCatalog}
     */
    private function sqlite(): array
    {
        $recorder = new StatementRecorder();
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new SqliteConnectionSetup(), $recorder]));
        (new SchemaMigrator($connection))->migrate();

        return [$connection, $recorder, new SchemaCatalog($connection)];
    }

    private function legacyTables(Connection $connection, SchemaCatalog $catalog): LegacyTables
    {
        $copier = new LegacyDataCopier($connection, new SqliteDialect(), new IdentityTypeMap(), new UpgradeLock($connection));

        return new LegacyTables(
            new BootConfig(['database' => 'sqlite', 'dbname' => ':memory:'], false),
            static fn (): Connection => $connection,
            static fn (): SchemaCatalog => $catalog,
            static fn (): LegacyDataCopier => $copier,
            static fn (): Dialect => new SqliteDialect(),
        );
    }
}
