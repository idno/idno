<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;
use Idno\Storage\Attribute\AsEntity;
use Idno\Storage\AttributeTypeMap;
use Idno\Storage\Criteria;
use Idno\Storage\EntityClassFinder;
use Idno\Storage\Platform\MySqlDialect;
use Idno\Storage\RecordStore;
use Idno\Storage\SettingsRecordStore;
use Idno\Upgrade\LegacyDataCopier;
use Idno\Upgrade\UpgradeLock;
use Idno\Upgrade\UpgradeProgress;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tests\Storage\PrivateDatabase;
use Tests\Storage\StatementRecorder;
use Tests\Unit\Storage\FailingStatementMiddleware;

/**
 * The copy of a 1.x database into the new schema (LegacyDataCopier), on a private database of the
 * rig's MySQL or MariaDB server (LegacyFixture: the 1.x tables, then the schema migrations and the
 * core field indexes, as the upgrade prepares it), dropped after each test. Skipped on an SQLite
 * rig: no 1.x site runs on SQLite, and the copier supports MySQL and MariaDB only.
 */
final class LegacyDataCopierTest extends TestCase
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

    // ------------------------------------------------------------------ copying, resuming, rejects, renaming

    public function testEveryRowIsCopiedAndTheColumnsAreRight(): void
    {
        $fixture = $this->fixture();
        $types = new AttributeTypeMap();
        $written = [];
        $i = 0;
        foreach (self::entityClasses() as $class => $collection) {
            $document = [
                'entity_subtype' => $class,
                'title' => 'A record of ' . $class,
                'body' => '<p>Some <b>body</b> text #tag</p>',
                'created' => 1_700_000_000 + $i++,
                'owner' => 'https://legacy.example/profile/owner',
                'access' => 'PUBLIC',
            ];
            $written[] = [$collection, $class, $document['created'], $fixture->insert($collection, $document)];
        }
        // No class is stored in `reader` any more, but a 1.x site holds records there: one of the removed feed reader's classes is copied like any other
        $removed = 'Idno\\Entities\\Reader\\Feed';
        $document = ['entity_subtype' => $removed, 'title' => 'A record of ' . $removed, 'body' => '<p>Some <b>body</b> text #tag</p>', 'created' => 1_700_000_000 + $i++, 'owner' => 'https://legacy.example/profile/owner', 'access' => 'PUBLIC'];
        $written[] = ['reader', $removed, $document['created'], $fixture->insert('reader', $document)];
        $collections = array_unique(array_column($written, 0));
        self::assertContains('entities', $collections);
        self::assertContains('reader', $collections);
        self::assertContains('site', $collections);
        $config = ['title' => 'My site', 'description' => 'A 1.x site', 'theme' => 'Twenty26', 'plugins' => ['Status', 'Text'], 'created' => 1_700_000_500];
        $configRow = $fixture->insert('config', $config);

        $progress = $this->complete($this->copier());

        $store = $this->store();
        foreach ($written as [$collection, $class, $created, $row]) {
            $expected = json_decode($row['contents'], true);
            self::assertSame($expected, $store->findById($collection, $row['_id']), $class . ' by id');
            self::assertSame($expected, $store->findByUuid($collection, $row['uuid']), $class . ' by uuid');
            $copied = $fixture->connection->fetchAssociative(
                'select type, created, data, search, owner, publish_status from ' . $fixture->connection->quoteSingleIdentifier('idno_' . $collection) . ' where id = ?',
                [$row['_id']],
            );
            self::assertIsArray($copied);
            self::assertSame($types->typeFor($class), $copied['type'], $class . ': the type is the alias');
            self::assertSame((string) $created, (string) $copied['created'], $class . ': the same instant');
            self::assertSame($row['contents'], $copied['data'], $class . ': the 1.x contents byte for byte');
            self::assertSame($this->legacySearch($collection, $row['_id']), $copied['search'], $class . ': the search text is carried');
            self::assertStringContainsString('some body text  #tag', (string) $copied['search']);
            self::assertSame('https://legacy.example/profile/owner', $copied['owner']);
            self::assertSame('published', $copied['publish_status']);
        }
        self::assertSame(json_decode($configRow['contents'], true), $store->findLatest('config'));
        foreach (LegacyDataCopier::COLLECTIONS as $collection) {
            $counts = $progress->collections[$collection];
            self::assertSame($counts['source'], $counts['copied'] + $counts['rejected'], $collection);
            self::assertSame(0, $counts['rejected'], $collection);
            self::assertTrue($counts['done'], $collection);
        }
        self::assertTrue($progress->complete);
        self::assertNull($progress->error);
    }

    public function testAnUnknownClassKeepsItsClassNameAsType(): void
    {
        $fixture = $this->fixture();
        $row = $fixture->insert('entities', ['entity_subtype' => 'IdnoPlugins\\Gone\\Thing', 'title' => 'Gone']);

        $this->complete($this->copier());

        self::assertSame('IdnoPlugins\\Gone\\Thing', $fixture->connection->fetchOne('select type from idno_entities where id = ?', [$row['_id']]));
        $found = $this->store()->find('entities', Criteria::fromLegacyArray(['entity_subtype' => 'IdnoPlugins\\Gone\\Thing']), 10, 0);
        self::assertSame([json_decode($row['contents'], true)], $found);
    }

    public function testNestedKeyOrderAndFourByteCharactersSurvive(): void
    {
        $fixture = $this->fixture(2026111401);
        $document = [
            'title' => 'Emoji 😀 and 𝄞',
            'annotations' => ['z' => ['b' => 2, 'a' => 1], 'a' => ['y' => ['k' => 'v', 'c' => '🎵'], 'x' => [3, 2, 1]]],
            'created' => 1_700_000_000,
        ];
        // As written by PHP, with the four-byte characters as raw UTF-8 bytes (not \u escapes).
        $contents = json_encode(['_id' => 'emoji1', 'uuid' => 'https://legacy.example/view/emoji1', 'owner' => ''] + $document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $row = $fixture->insert('entities', ['_id' => 'emoji1'] + $document, [], $contents);

        $this->complete($this->copier());

        self::assertSame($contents, $fixture->connection->fetchOne('select data from idno_entities where id = ?', [$row['_id']]));
        $found = $this->store()->findById('entities', 'emoji1');
        self::assertIsArray($found);
        self::assertSame(['z', 'a'], array_keys($found['annotations']));
        self::assertSame(['y', 'x'], array_keys($found['annotations']['a']));
        self::assertSame(['k', 'c'], array_keys($found['annotations']['a']['y']));
        self::assertSame('🎵', $found['annotations']['a']['y']['c']);
        self::assertSame('Emoji 😀 and 𝄞', $found['title']);
    }

    public function testBatchesAreResumable(): void
    {
        $fixture = $this->fixture();
        $ids = $fixture->insertMany('entities', 1200);
        $copier = $this->copier();

        $first = $copier->step(microtime(true) - 1);

        self::assertSame(500, $first->collections['entities']['copied']);
        self::assertSame(500, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));
        self::assertFalse($first->complete);

        $steps = 1;
        do {
            $progress = $copier->step(microtime(true) - 1);
            self::assertLessThan(20, ++$steps, 'the upgrade finishes');
        } while (!$progress->complete);

        self::assertSame(1200, $progress->collections['entities']['copied']);
        $copied = $fixture->connection->fetchFirstColumn('select id from idno_entities order by id');
        sort($ids);
        self::assertSame($ids, $copied, 'each source id once');
    }

    public function testAStepInterruptedMidBatchIsResumed(): void
    {
        $fixture = $this->fixture();
        $ids = $fixture->insertMany('entities', 1200);
        $failing = new FailingStatementMiddleware();
        $connection = $this->connect($failing);
        $copier = $this->copier($connection);
        $copier->step(microtime(true) - 1);
        $position = $this->progressRow('entities')['position'];

        $failing->failOn('/^insert into `idno_entities`/', 3);
        try {
            $copier->step(microtime(true) - 1);
            self::fail('The failing insert ends the step.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('disk I/O error', $e->getMessage());
        }

        $row = $this->progressRow('entities');
        self::assertSame('500', (string) $row['copied']);
        self::assertSame($position, $row['position'], 'the position is that of the last committed batch');
        self::assertSame(500, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));
        self::assertSame(\Idno\Upgrade\PublicMessage::stopped()->text, (string) $row['error']);
        self::assertStringContainsString('disk I/O error', (string) $row['error_detail'], 'the driver message is private detail');

        $progress = $this->complete($copier);
        self::assertNull($progress->error, 'a step that works clears the error');
        self::assertNull($progress->errorDetail);
        $copied = $fixture->connection->fetchFirstColumn('select id from idno_entities order by id');
        sort($ids);
        self::assertSame($ids, $copied);
    }

    public function testTwoConcurrentStepsDoNotDuplicateRows(): void
    {
        $fixture = $this->fixture();
        $ids = $fixture->insertMany('entities', 700);
        $other = $this->connect();
        self::assertSame(1, (int) $other->fetchOne('select get_lock(?, 0)', [$this->lockName()]));

        $started = microtime(true);
        $blocked = $this->copier()->step(microtime(true) + self::FAR);

        self::assertLessThan(5.0, microtime(true) - $started, 'the step does not wait for the lock');
        self::assertSame(0, $blocked->collections['entities']['copied']);
        self::assertSame(0, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));
        self::assertFalse($blocked->complete);

        $other->fetchOne('select release_lock(?)', [$this->lockName()]);
        $progress = $this->complete($this->copier());

        self::assertSame(700, $progress->collections['entities']['copied']);
        $copied = $fixture->connection->fetchFirstColumn('select id from idno_entities order by id');
        sort($ids);
        self::assertSame($ids, $copied);
    }

    public function testAnUncopiableRowIsKeptInTheRejectsTable(): void
    {
        $fixture = $this->fixture();
        $good = $fixture->insert('entities', ['title' => 'Good']);
        $notJson = $fixture->insert('entities', ['title' => 'Not JSON'], [], 'not json');
        $notUtf8 = $fixture->insert('entities', ['title' => 'Not UTF-8'], [], "\xff\xfe");
        $scalar = $fixture->insert('reader', ['title' => 'A string'], [], '"a string"');
        $site = $fixture->insert('site', ['_id' => 'site1', 'uuid' => 'https://legacy.example/', 'title' => 'Site']);
        $sameId = $fixture->insert('site', ['_id' => 'site1', 'uuid' => 'https://legacy.example/other', 'title' => 'Site again']);

        $progress = $this->complete($this->copier());

        $rejects = $this->rejects();
        self::assertSame([
            ['entities', $notJson['uuid'], $notJson['_id'], 'not json'],
            ['entities', $notUtf8['uuid'], $notUtf8['_id'], "\xff\xfe"],
            ['reader', $scalar['uuid'], $scalar['_id'], '"a string"'],
            ['site', $sameId['uuid'], $sameId['_id'], $sameId['contents']],
        ], array_map(static fn (array $r): array => [$r['collection'], $r['legacy_uuid'], $r['legacy_id'], $r['contents']], self::sortRejects($rejects)));
        $reasons = array_column(self::sortRejects($rejects), 'reason');
        self::assertSame('contents are not valid JSON', $reasons[0]);
        self::assertSame('contents are not valid UTF-8', $reasons[1]);
        self::assertSame('contents are not a JSON object or array', $reasons[2]);
        self::assertStringContainsString('_id site1 is that of the copied record https://legacy.example/', $reasons[3]);

        self::assertSame(json_decode($good['contents'], true), $this->store()->findById('entities', $good['_id']));
        self::assertSame(json_decode($site['contents'], true), $this->store()->findById('site', 'site1'));
        self::assertTrue($progress->complete);
        foreach (LegacyDataCopier::COLLECTIONS as $collection) {
            $counts = $progress->collections[$collection];
            self::assertSame($counts['source'], $counts['copied'] + $counts['rejected'], $collection);
        }
        self::assertSame(2, $progress->collections['entities']['rejected']);
        self::assertSame(1, $progress->collections['site']['rejected']);
    }

    public function testTheNewestConfigBecomesTheSettings(): void
    {
        $fixture = $this->fixture();
        $old = $fixture->insert('config', ['title' => 'Old', 'created' => 1_600_000_000]);
        $newest = $fixture->insert('config', ['title' => 'Newest', 'description' => 'd', 'created' => 1_700_000_000]);
        $middle = $fixture->insert('config', ['title' => 'Middle', 'created' => 1_650_000_000]);

        $progress = $this->complete($this->copier());

        self::assertSame(json_decode($newest['contents'], true), $this->store()->findLatest('config'));
        self::assertSame(
            array_map(static fn (string $json): string => $json, SettingsRecordStore::rowsOf(json_decode($newest['contents'], true))),
            $this->settingsRows(),
        );
        $rejects = self::sortRejects($this->rejects());
        self::assertSame(
            [[$old['uuid'], 'superseded configuration', $old['contents']], [$middle['uuid'], 'superseded configuration', $middle['contents']]],
            self::sortRows(array_map(static fn (array $r): array => [$r['legacy_uuid'], $r['reason'], $r['contents']], $rejects)),
        );
        self::assertSame(['source' => 3, 'copied' => 1, 'rejected' => 2, 'done' => true], $progress->collections['config']);
    }

    public function testCompletionRenamesTheLegacyTables(): void
    {
        $fixture = $this->fixture();
        $row = $fixture->insert('entities', ['title' => 'A post']);
        $fixture->insert('config', ['title' => 'Site']);

        $progress = $this->complete($this->copier());

        self::assertTrue($progress->complete);
        $tables = $this->tables();
        foreach (LegacyDataCopier::LEGACY_TABLES as $table) {
            self::assertContains('legacy_' . $table, $tables);
            self::assertNotContains($table, $tables);
        }
        self::assertCount(14, LegacyDataCopier::LEGACY_TABLES);
        foreach (['idno_entities', 'idno_reader', 'idno_site', 'idno_settings', 'idno_sessions', 'idno_upgrade_progress', 'idno_upgrade_rejects', 'idno_migrations'] as $table) {
            self::assertContains($table, $tables);
        }
        self::assertSame(json_decode($row['contents'], true), $this->store()->findById('entities', $row['_id']));
        self::assertSame(1, (int) $fixture->connection->fetchOne('select count(*) from legacy_entities'));
        foreach ($fixture->connection->fetchFirstColumn('select completed_at from idno_upgrade_progress') as $completedAt) {
            self::assertNotNull($completedAt);
        }

        $again = $this->copier()->step(microtime(true) + self::FAR);
        self::assertTrue($again->complete);
        self::assertEquals($progress, $again, 'a step after completion changes nothing');
    }

    public function testARowInsertedBehindTheCopyCursorIsCopied(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 1200);
        $copier = $this->copier();
        for ($i = 0; $i < 3; ++$i) {
            $copier->step(microtime(true) - 1);
        }
        self::assertSame(1200, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));

        // Behind the cursor, after the last batch and before completion.
        $late = $fixture->insert('entities', ['uuid' => 'https://legacy.example/view/00000-late', 'title' => 'Late']);
        self::assertContains('entities', $this->tables());

        $progress = $this->complete($copier);

        self::assertSame(json_decode($late['contents'], true), $this->store()->findById('entities', $late['_id']));
        self::assertSame(1201, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));
        // `source` is the count when the upgrade began; the late row was copied by the verify pass.
        self::assertSame(['source' => 1200, 'copied' => 1201, 'rejected' => 0, 'done' => true], $progress->collections['entities']);
    }

    /**
     * A `legacy_<name>` that appears beside its 1.x table once the copy has begun still blocks the
     * rename (the check when the copy starts is not the only one).
     */
    public function testAnExistingLegacyTableBlocksTheRename(): void
    {
        $fixture = $this->fixture();
        $fixture->insert('entities', ['title' => 'A post']);
        // One unit of work: the progress rows and the first batch.
        $this->copier()->step(microtime(true) - 1);
        $fixture->connection->executeStatement('create table legacy_entities (x int)');

        $progress = $this->copier()->step(microtime(true) + self::FAR);
        $progress = $this->copier()->step(microtime(true) + self::FAR);

        self::assertFalse($progress->complete);
        self::assertSame(UpgradeProgress::COPYING, $progress->phase);
        self::assertSame(1, $progress->collections['entities']['copied']);
        self::assertNotNull($progress->error);
        self::assertStringContainsString('entities and legacy_entities both exist', $progress->error);
        self::assertStringContainsString('Nothing was changed', $progress->error);
        self::assertStringNotContainsString('Every row', $progress->error, 'no claim that the rows were copied');
        $tables = $this->tables();
        foreach (LegacyDataCopier::LEGACY_TABLES as $table) {
            self::assertContains($table, $tables, 'no table is renamed');
            if ($table !== 'entities') {
                self::assertNotContains('legacy_' . $table, $tables);
            }
        }
        self::assertSame(1, (int) $fixture->connection->fetchOne('select count(*) from entities'));
        self::assertSame(0, (int) $fixture->connection->fetchOne('select count(*) from legacy_entities'));
    }

    /**
     * Before the rename, a `legacy_<name>` that exists beside the 1.x table blocks it, and the message
     * says both sets must be examined: it never tells which to delete.
     */
    public function testTheBlockedRenameMessageSaysToExamineBothSets(): void
    {
        $fixture = $this->fixture();
        $fixture->insert('entities', ['title' => 'A post']);
        $fixture->connection->executeStatement('create table legacy_entities (x int)');

        $this->copier()->step(microtime(true) + self::FAR);
        $error = (string) $this->copier()->progress()->error;

        self::assertSame(\Idno\Upgrade\PublicMessage::renameBlocked(['entities'])->text, $error);
        self::assertStringContainsString('examine', $error);
        self::assertStringContainsString('without the prefix hold this site', $error);
        self::assertStringNotContainsString('remove them', $error);
        self::assertStringNotContainsString('remove it', $error);
    }

    /**
     * A `legacy_<name>` beside its 1.x table when the copy is about to start stops it before any row
     * is copied (the rename could never be made): the same message as at the rename, nothing written,
     * on every step.
     */
    public function testAnExistingLegacyTableStopsTheCopyBeforeAnyRowIsCopied(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 10);
        $fixture->connection->executeStatement('create table legacy_versions like versions');
        $before = $this->rowCounts();
        $copier = $this->copier();

        foreach ([$copier->step(microtime(true) + self::FAR), $copier->step(microtime(true) + self::FAR), $copier->progress()] as $progress) {
            self::assertSame(\Idno\Upgrade\PublicMessage::renameBlocked(['versions'])->text, $progress->error);
            self::assertSame(UpgradeProgress::NOT_STARTED, $progress->phase);
            self::assertSame(0, $progress->collections['entities']['copied']);
            self::assertFalse($progress->complete);
        }
        self::assertSame($before, $this->rowCounts(), 'nothing was written: no progress row, no copied row');

        // Moved away: the copy starts.
        $fixture->connection->executeStatement('drop table legacy_versions');
        $progress = $this->complete($copier);
        self::assertSame(10, $progress->collections['entities']['copied']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rowsInTheNewTables(): iterable
    {
        $document = "'{\"_id\": \"000000000000000000000001\"}'";
        foreach (['idno_entities', 'idno_reader', 'idno_site'] as $table) {
            yield $table => [$table, 'insert into ' . $table . " (id, uuid, type, owner, created, publish_status, data, search) values ('000000000000000000000001', 'http://elsewhere.test/view/1', 'status', '', 1700000000, 'published', " . $document . ", '')"];
        }
        yield 'idno_settings' => ['idno_settings', "insert into idno_settings (name, value, position) values ('title', '\"A site in use\"', 0)"];
        yield 'idno_upgrade_rejects' => ['idno_upgrade_rejects', "insert into idno_upgrade_rejects (collection, legacy_uuid, legacy_id, reason, contents) values ('entities', 'http://elsewhere.test/view/1', '1', 'left over', '{}')"];
    }

    /**
     * A copy never starts while a table of the new schema holds rows (an earlier upgrade's tables,
     * one of them missed when they were removed by hand; or a site in use whose database the 1.x
     * tables were restored into): every 1.x row sharing an `_id` or uuid with one of them would be
     * rejected and the older copy kept. It stops before anything is written, naming the table, on
     * every step, and the 1.x tables are untouched.
     *
     * @dataProvider rowsInTheNewTables
     */
    public function testACopyDoesNotStartWhileATableOfTheNewSchemaHoldsRows(string $table, string $insert): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 10);
        $fixture->insert('config', ['title' => 'The 1.x site']);
        $fixture->connection->executeStatement($insert);
        $before = $this->rowCounts();
        $oneX = array_values(array_intersect(LegacyDataCopier::LEGACY_TABLES, $this->tables()));
        $sums = $this->checksums($oneX);
        $copier = $this->copier();

        foreach ([$copier->step(microtime(true) + self::FAR), $copier->step(microtime(true) + self::FAR), $copier->progress()] as $progress) {
            self::assertSame(\Idno\Upgrade\PublicMessage::newTablesNotEmpty([$table])->text, $progress->error);
            self::assertStringContainsString($table . ' already holds data', (string) $progress->error);
            self::assertStringContainsString('remove ALL the tables whose names begin with idno_', (string) $progress->error);
            self::assertSame(UpgradeProgress::NOT_STARTED, $progress->phase);
            self::assertFalse($progress->complete);
        }
        self::assertSame($before, $this->rowCounts(), 'nothing was written');
        self::assertSame($sums, $this->checksums($oneX), 'the 1.x tables are untouched');
    }

    /**
     * The check is for a copy that is about to START: a copy that has begun (progress rows, rows
     * already copied into the new tables) goes on to completion.
     */
    public function testACopyThatHasBegunGoesOnWithRowsInTheNewTables(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', LegacyDataCopier::BATCH + 100);
        $copier = $this->copier();

        $progress = $copier->step(microtime(true) - 1);
        self::assertNull($progress->error);
        self::assertSame(LegacyDataCopier::BATCH, $progress->collections['entities']['copied']);
        self::assertSame(LegacyDataCopier::BATCH, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));

        $progress = $this->complete($copier);
        self::assertNull($progress->error);
        self::assertSame(LegacyDataCopier::BATCH + 100, $progress->collections['entities']['copied']);
    }

    /**
     * The rename waits for the 1.x tables at most RENAME_WAIT_SECONDS (the session's
     * `lock_wait_timeout`, for that statement only): while another session holds them (an open
     * transaction that has read one, as a backup's does) the step gives up within the bound, reports
     * the notice, stores no error and renames nothing; once they are free the next step renames and
     * the upgrade completes.
     */
    public function testARenameThatWaitsForTheOneXTablesGivesUpAndTheNextStepRenames(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 10);
        $copier = $this->copier();
        $timeout = (string) $fixture->connection->fetchOne('select @@session.lock_wait_timeout');
        $other = $this->connect();
        $other->beginTransaction();
        $other->fetchAllAssociative('select * from entities limit 1');

        foreach ([1, 2] as $attempt) {
            $started = microtime(true);
            $progress = $copier->step(microtime(true) + self::FAR);
            $took = microtime(true) - $started;

            self::assertLessThan(LegacyDataCopier::RENAME_WAIT_SECONDS + 3, $took, 'attempt ' . $attempt . ': bounded');
            self::assertNull($progress->error, 'attempt ' . $attempt . ': not an error');
            self::assertSame(\Idno\Upgrade\PublicMessage::tablesInUse()->text, $progress->notice);
            self::assertFalse($progress->complete);
            self::assertSame(UpgradeProgress::COPYING, $progress->phase);
            self::assertContains('entities', $this->tables());
            self::assertNotContains('legacy_entities', $this->tables(), 'nothing is renamed');
            self::assertNull($copier->progress()->error, 'nothing is stored as an error');
            self::assertNull($copier->progress()->notice, 'only the step that waited reports it');
            self::assertSame($timeout, (string) $fixture->connection->fetchOne('select @@session.lock_wait_timeout'), 'the session\'s timeout is restored');
        }

        $other->commit();
        $progress = $this->complete($copier);
        self::assertNull($progress->notice);
        self::assertContains('legacy_entities', $this->tables());
        self::assertSame(10, $progress->collections['entities']['copied']);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function recreatedInTheVerifyPass(): iterable
    {
        // What a 1.x process booting after the rename creates: it runs every dated upgrade file, each
        // up to its first failing statement (LegacyDataCopier::isOneXBesideCompletedUpgrade() lists
        // them file by file). In LEGACY_TABLES order.
        yield 'what a booting 1.x process creates' => [['entities_metadata', 'entities_search', 'reader', 'site', 'site_metadata', 'site_search', 'versions']];
        yield 'reader, site and versions only' => [['reader', 'reader_metadata', 'reader_search', 'site', 'site_metadata', 'site_search', 'versions']];
        // Every 1.x name: before, the copier took this for a rename still to do, and blocked it with
        // a message that pointed at the legacy_ tables, which hold the site.
        yield 'every 1.x table' => [LegacyDataCopier::LEGACY_TABLES];
    }

    /**
     * In the verify pass (the rename has happened: the legacy_ tables are the site's original data),
     * 1.x-named tables that exist again were created by an old process after the rename. The upgrade
     * stops with its own message (keep the legacy_ tables; stop the process; remove the new old-name
     * tables), writes nothing, and goes on once they are removed.
     *
     * @dataProvider recreatedInTheVerifyPass
     *
     * @param list<string> $recreated
     */
    public function testOneXTablesRecreatedInTheVerifyPassStopTheUpgradeAndKeepTheLegacyTables(array $recreated): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 30);
        $fixture->insert('config', ['title' => 'Site']);
        $copier = $this->copier();
        for ($i = 0; $i < 50 && $copier->progress()->phase !== UpgradeProgress::VERIFYING; ++$i) {
            $copier->step(microtime(true) - 1);
        }
        self::assertSame(UpgradeProgress::VERIFYING, $copier->progress()->phase);
        foreach ($recreated as $table) {
            $fixture->connection->executeStatement('create table ' . $table . ' like legacy_' . $table);
        }
        $before = $this->rowCounts();

        $progress = $copier->step(microtime(true) + self::FAR);

        self::assertFalse($progress->complete);
        self::assertSame(\Idno\Upgrade\PublicMessage::recreatedAfterRename($recreated)->text, $progress->error);
        self::assertStringContainsString('must be kept', (string) $progress->error);
        self::assertStringNotContainsString('cannot be renamed', (string) $progress->error);
        self::assertSame($before, $this->rowCounts(), 'nothing was written');
        foreach (LegacyDataCopier::LEGACY_TABLES as $table) {
            self::assertContains('legacy_' . $table, $this->tables(), 'nothing was renamed');
        }

        $fixture->connection->executeStatement('set foreign_key_checks = 0');
        $fixture->connection->executeStatement('drop table ' . implode(', ', $recreated));
        $fixture->connection->executeStatement('set foreign_key_checks = 1');
        $progress = $this->complete($copier);
        self::assertNull($progress->error);
        self::assertSame(30, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));
    }

    public function testTheNewerOneXSchemaIsAccepted(): void
    {
        $fixture = $this->fixture(2026111401);
        $post = $fixture->insert('entities', ['entity_subtype' => 'IdnoPlugins\\Status\\Status', 'body' => 'A note 🎉', 'created' => 1_700_000_000]);
        $feed = $fixture->insert('reader', ['entity_subtype' => 'Idno\\Entities\\Reader\\Feed', 'feed_url' => 'https://feed.example/']);
        $fixture->insert('config', ['title' => 'Site']);

        $progress = $this->complete($this->copier());

        self::assertTrue($progress->complete);
        self::assertSame(json_decode($post['contents'], true), $this->store()->findById('entities', $post['_id']));
        self::assertSame(json_decode($feed['contents'], true), $this->store()->findByUuid('reader', $feed['uuid']));
        // the feed reader's classes are gone, so no alias is declared for them: the copied row keeps the class name as its `type` (nothing reads it)
        self::assertSame('Idno\\Entities\\Reader\\Feed', $fixture->connection->fetchOne('select type from idno_reader where uuid = ?', [$feed['uuid']]));
        self::assertSame('a note 🎉', $fixture->connection->fetchOne('select search from idno_entities where id = ?', [$post['_id']]));
        self::assertSame('Site', $this->store()->findLatest('config')['title'] ?? null);
    }

    public function testTheLegacySchemaVersionIsKnownInEveryPhase(): void
    {
        $this->fixture(2026111401);
        $this->fixture?->insertMany('entities', 30);
        $copier = $this->copier();
        $seen = [];
        for ($i = 0; $i < 20; ++$i) {
            $phase = $copier->progress()->phase;
            $seen[$phase] = true;
            self::assertSame(2026111401, $copier->legacySchemaVersion(), $phase);
            if ($copier->progress()->complete) {
                break;
            }
            $copier->step(microtime(true) - 1);
        }
        self::assertSame([UpgradeProgress::NOT_STARTED, UpgradeProgress::COPYING, UpgradeProgress::VERIFYING, UpgradeProgress::COMPLETE], array_keys($seen));
    }

    public function testLegacySchemaVersion(): void
    {
        $fixture = $this->fixture(2020120301, false);
        $copier = $this->copier();
        self::assertSame(2020120301, $copier->legacySchemaVersion());
        $fixture->connection->executeStatement("update versions set value = '2019060501' where label = 'schema'");
        self::assertSame(2019060501, $copier->legacySchemaVersion());
        $fixture->connection->executeStatement('drop table versions');
        self::assertNull($copier->legacySchemaVersion());
        $fixture->drop();
        $this->fixture = null;

        $this->fixture(2026111401, false);
        self::assertSame(2026111401, $this->copier()->legacySchemaVersion());
    }

    public function testTheOneXTablesAreNeverWritten(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 600);
        $fixture->insert('entities', ['title' => 'Bad'], [], 'not json');
        $fixture->insert('entities', ['uuid' => 'https://legacy.example/straße', 'title' => 'One']);
        $fixture->insert('entities', ['uuid' => 'https://legacy.example/strasse', 'title' => 'Two']);
        $fixture->insert('reader', ['title' => 'Feed']);
        $fixture->insert('site', ['title' => 'Site']);
        $fixture->insert('config', ['title' => 'Old', 'created' => 1_600_000_000]);
        $fixture->insert('config', ['title' => 'New', 'created' => 1_700_000_000]);
        $fixture->connection->executeStatement("insert into session values ('s1', 'value', 3600, 1700000000)");
        $before = $this->checksums(LegacyDataCopier::LEGACY_TABLES);

        $middleware = new FailingStatementMiddleware();
        $atRename = null;
        $middleware->hookOn('/^rename table/i', function () use (&$atRename): void {
            $atRename = $this->checksums(LegacyDataCopier::LEGACY_TABLES);
        });
        $this->complete($this->copier($this->connect($middleware)));

        self::assertNotNull($atRename, 'the tables were checked just before the rename');
        self::assertSame($before, $atRename);
        // The verify pass reads the renamed tables and writes none of them either.
        self::assertSame($before, $this->checksums(array_map(static fn (string $t): string => 'legacy_' . $t, LegacyDataCopier::LEGACY_TABLES), LegacyDataCopier::LEGACY_TABLES));
    }

    // ------------------------------------------------------------------ rename and verify

    public function testASourceRowDeletedAfterItWasCopiedDoesNotStallCompletion(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 1200);
        $copier = $this->copier();
        $copier->step(microtime(true) - 1);
        $fixture->connection->executeStatement('delete from entities where uuid = ?', [LegacyFixture::manyUuid(10)]);

        $progress = $this->complete($copier);

        self::assertTrue($progress->complete);
        self::assertSame(1200, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'), 'the copy stays');
        self::assertSame(['source' => 1200, 'copied' => 1200, 'rejected' => 0, 'done' => true], $progress->collections['entities']);
    }

    public function testARowAddedWhileAnotherIsDeletedIsStillCopied(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 1200);
        $copier = $this->copier();
        for ($i = 0; $i < 3; ++$i) {
            $copier->step(microtime(true) - 1);
        }
        // The counts match again (1,200 rows, 1,200 copied), but one row is new.
        $fixture->connection->executeStatement('delete from entities where uuid = ?', [LegacyFixture::manyUuid(10)]);
        $late = $fixture->insert('entities', ['uuid' => 'https://legacy.example/view/00000-late', 'title' => 'Late']);

        $this->complete($copier);

        self::assertSame(json_decode($late['contents'], true), $this->store()->findById('entities', $late['_id']));
    }

    public function testARowWrittenAfterTheCopyAndBeforeTheRenameIsCopiedByTheVerifyPass(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 30);
        $middleware = new FailingStatementMiddleware();
        $late = [];
        // An old-version process writes while the copy is about to rename: behind and ahead of the cursor.
        $middleware->hookOn('/^rename table/i', static function () use ($fixture, &$late): void {
            $late[] = $fixture->insert('entities', ['uuid' => 'https://legacy.example/view/00000-behind', 'title' => 'Behind']);
            $late[] = $fixture->insert('entities', ['uuid' => 'https://legacy.example/view/99999-ahead', 'title' => 'Ahead']);
        });

        $progress = $this->complete($this->copier($this->connect($middleware)));

        self::assertCount(2, $late);
        foreach ($late as $row) {
            self::assertSame(json_decode($row['contents'], true), $this->store()->findById('entities', $row['_id']));
        }
        self::assertSame(32, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));
        self::assertSame(32, $progress->collections['entities']['copied']);
    }

    public function testAUuidEqualToACopiedOneOnlyUnderTheNewCollationIsNotTakenForIt(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 10);
        $sharp = $fixture->insert('entities', ['uuid' => 'https://legacy.example/view/straße', 'title' => 'Straße']);
        $fixture->insert('entities', ['uuid' => 'https://legacy.example/view/zzz', 'title' => 'Last']);
        $copier = $this->copier();
        $copier->step(microtime(true) - 1);
        self::assertSame(12, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));

        // Behind the cursor (`…/zzz`), different from `…/straße` in 1.x, equal to it in the new table.
        $strasse = $fixture->insert('entities', ['uuid' => 'https://legacy.example/view/strasse', 'title' => 'Strasse']);
        $progress = $this->complete($copier);

        $rejects = $this->rejects();
        self::assertCount(1, $rejects, 'the verify pass handled `…/strasse` itself');
        self::assertSame($strasse['uuid'], $rejects[0]['legacy_uuid']);
        self::assertSame($strasse['contents'], $rejects[0]['contents']);
        self::assertSame('its uuid equals that of the copied record ' . $sharp['uuid'] . ' under the new schema\'s collation', $rejects[0]['reason']);
        self::assertSame(['source' => 12, 'copied' => 12, 'rejected' => 1, 'done' => true], $progress->collections['entities']);
    }

    public function testAStepThatDiesInTheVerifyPassIsResumedFromItsCursor(): void
    {
        $fixture = $this->fixture();
        $ids = $fixture->insertMany('entities', 1200);
        $failing = new FailingStatementMiddleware();
        $connection = $this->connect($failing);
        // As if the copies had been lost before the rename: the verify pass must copy every row again.
        $failing->hookOn('/^rename table/i', static function () use ($fixture): void {
            $fixture->connection->executeStatement('delete from idno_entities');
        });
        $copier = $this->copier($connection);
        $failing->failOn('/^insert into `idno_entities`/', 1200 + 503);

        try {
            for ($i = 0; $i < 10; ++$i) {
                $copier->step(microtime(true) + self::FAR);
            }
            self::fail('The failing insert ends the step.');
        } catch (\Doctrine\DBAL\Exception) {
        }

        self::assertSame(UpgradeProgress::VERIFYING, $copier->progress()->phase);
        self::assertSame('>' . LegacyFixture::manyUuid(500), $this->progressRow('entities')['verify_cursor']);
        self::assertSame(500, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));

        $progress = $this->complete($copier);

        self::assertSame(UpgradeProgress::COMPLETE, $progress->phase);
        $copied = $fixture->connection->fetchFirstColumn('select id from idno_entities order by id');
        sort($ids);
        self::assertSame($ids, $copied);
    }

    public function testAStepThatDiesBeforeCompletedAtIsFinishedByTheNext(): void
    {
        $fixture = $this->fixture();
        $fixture->insert('entities', ['title' => 'A post']);
        $failing = new FailingStatementMiddleware();
        $copier = $this->copier($this->connect($failing));
        $failing->failOn('/set completed_at/');

        try {
            for ($i = 0; $i < 10; ++$i) {
                $copier->step(microtime(true) + self::FAR);
            }
            self::fail('Writing completed_at fails.');
        } catch (\Doctrine\DBAL\Exception) {
        }
        self::assertSame(UpgradeProgress::VERIFYING, $copier->progress()->phase);
        self::assertFalse($copier->progress()->complete);
        self::assertSame([null, null, null, null], $fixture->connection->fetchFirstColumn('select completed_at from idno_upgrade_progress'));

        $progress = $copier->step(microtime(true) + self::FAR);

        self::assertTrue($progress->complete);
        self::assertSame(UpgradeProgress::COMPLETE, $progress->phase);
    }

    public function testTheRenameIsMadeWhenAStepDiedAfterMarkingTheCopyReady(): void
    {
        $fixture = $this->fixture();
        $row = $fixture->insert('entities', ['title' => 'A post']);
        $failing = new FailingStatementMiddleware();
        $copier = $this->copier($this->connect($failing));
        $failing->failOn('/^rename table/i');
        try {
            for ($i = 0; $i < 10; ++$i) {
                $copier->step(microtime(true) + self::FAR);
            }
            self::fail('The rename fails.');
        } catch (\Doctrine\DBAL\Exception) {
        }
        self::assertContains('entities', $this->tables());
        self::assertSame(UpgradeProgress::COPYING, $copier->progress()->phase);
        $late = $fixture->insert('entities', ['title' => 'Late']);

        $progress = $this->complete($copier);

        self::assertNotContains('entities', $this->tables());
        foreach ([$row, $late] as $written) {
            self::assertSame(json_decode($written['contents'], true), $this->store()->findById('entities', $written['_id']));
        }
        self::assertTrue($progress->complete);
    }

    public function testADatabaseRenamedByHalvesStopsTheUpgradeAndIsLeftAsItIs(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 600);
        $copier = $this->copier();
        $copier->step(microtime(true) - 1);
        $fixture->connection->executeStatement('rename table entities to legacy_entities');
        $before = $this->rowCounts();

        $progress = $copier->step(microtime(true) + self::FAR);

        self::assertNotNull($progress->error);
        self::assertStringContainsString('renamed (legacy_entities)', $progress->error);
        self::assertStringContainsString('entities_search', $progress->error);
        self::assertSame($before, $this->rowCounts(), 'nothing was written');
        self::assertSame($progress->error, $this->progressRow('entities')['error']);
        self::assertFalse($progress->complete);
    }

    public function testTablesRenamedBeforeTheCopyFinishedStopTheUpgrade(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 600);
        $copier = $this->copier();
        $copier->step(microtime(true) - 1);
        $fixture->connection->executeStatement('rename table ' . implode(', ', array_map(static fn (string $t): string => $t . ' to legacy_' . $t, LegacyDataCopier::LEGACY_TABLES)));
        $before = $this->rowCounts();

        $progress = $copier->step(microtime(true) + self::FAR);

        self::assertNotNull($progress->error);
        self::assertStringContainsString('the copy had not finished', $progress->error);
        self::assertSame($before, $this->rowCounts());
        self::assertSame(500, $progress->collections['entities']['copied']);
    }

    public function testBeforeAnyStepTheUpgradeIsNotStarted(): void
    {
        $this->fixture();
        $progress = $this->copier()->progress();

        self::assertSame(UpgradeProgress::NOT_STARTED, $progress->phase);
        self::assertFalse($progress->complete);
        self::assertNull($progress->error);
    }

    // ------------------------------------------------------------------ sources that are not there

    public function testADatabaseWithoutOneXTablesIsLeftAlone(): void
    {
        $connection = PrivateDatabase::mysql('upgrade');
        $this->connections[] = $connection;
        try {
            $connection->insert('idno_settings', ['name' => 'title', 'value' => '"My site"', 'position' => 0]);
            $connection->insert('idno_settings', ['name' => 'theme', 'value' => '"Twenty26"', 'position' => 1]);
            $settings = $connection->fetchAllNumeric('select name, value, position from idno_settings order by position');
            $copier = new LegacyDataCopier($connection, new MySqlDialect(), new AttributeTypeMap(), new UpgradeLock($connection));

            foreach ([$copier->step(microtime(true) + self::FAR), $copier->step(microtime(true) - 1), $copier->progress()] as $progress) {
                self::assertTrue($progress->noLegacyDatabase);
                self::assertFalse($progress->complete);
                self::assertNull($progress->error);
                self::assertSame(UpgradeProgress::NOT_STARTED, $progress->phase);
            }
            self::assertSame($settings, $connection->fetchAllNumeric('select name, value, position from idno_settings order by position'));
            self::assertSame(0, (int) $connection->fetchOne('select count(*) from idno_upgrade_progress'));
            self::assertSame(0, (int) $connection->fetchOne('select count(*) from idno_upgrade_rejects'));
        } finally {
            array_pop($this->connections);
            PrivateDatabase::drop($connection);
        }
    }

    public function testAOneXDatabaseIsNotTakenForNone(): void
    {
        $this->fixture();

        self::assertFalse($this->copier()->progress()->noLegacyDatabase);
        self::assertFalse($this->copier()->step(microtime(true) - 1)->noLegacyDatabase);
    }

    public function testASiteThatNeverHadReaderTablesUpgrades(): void
    {
        $fixture = $this->fixture();
        $fixture->connection->executeStatement('drop table reader_search, reader_metadata');
        $fixture->connection->executeStatement('drop table reader');
        $post = $fixture->insert('entities', ['title' => 'A post']);
        $fixture->insert('config', ['title' => 'Site']);

        $progress = $this->complete($this->copier());

        self::assertTrue($progress->complete);
        self::assertNull($progress->error);
        self::assertSame(['source' => 0, 'copied' => 0, 'rejected' => 0, 'done' => true], $progress->collections['reader']);
        self::assertSame(json_decode($post['contents'], true), $this->store()->findById('entities', $post['_id']));
        self::assertNotContains('legacy_reader', $this->tables());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function renamedSourcesThatVanish(): iterable
    {
        yield 'legacy_config' => ['legacy_config'];
        yield 'legacy_entities' => ['legacy_entities'];
        yield 'legacy_entities_search (dropped by hand mid-upgrade)' => ['legacy_entities_search'];
    }

    /**
     * @dataProvider renamedSourcesThatVanish
     */
    public function testASourceThatVanishesInTheVerifyPassStopsTheUpgrade(string $table): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 30);
        $fixture->insert('config', ['title' => 'Site']);
        $copier = $this->copier();
        for ($i = 0; $i < 10 && $copier->progress()->phase !== UpgradeProgress::VERIFYING; ++$i) {
            $copier->step(microtime(true) - 1);
        }
        self::assertSame(UpgradeProgress::VERIFYING, $copier->progress()->phase);
        $this->dropTable($table);
        $settings = $this->settingsRows();
        $before = $this->rowCounts();

        foreach ([1, 2] as $attempt) {
            $progress = $copier->step(microtime(true) + self::FAR);

            self::assertNotNull($progress->error, 'attempt ' . $attempt);
            self::assertStringContainsString($table, $progress->error);
            self::assertStringContainsString('Nothing was changed', $progress->error);
            self::assertFalse($progress->complete);
            self::assertSame($settings, $this->settingsRows());
            self::assertSame($before, $this->rowCounts());
        }
        self::assertSame([null, null, null, null], $fixture->connection->fetchFirstColumn('select completed_at from idno_upgrade_progress'));
        // Stored, and public-safe: a PublicMessage naming the table, no private detail.
        $row = $this->progressRow('entities');
        self::assertStringContainsString($table, (string) $row['error']);
        self::assertNull($row['error_detail']);
        self::assertStringContainsString($table, (string) $copier->progress()->error);
    }

    /**
     * A collection whose 1.x table did not exist when the upgrade began (`source_existed` 0) and
     * that appears later (an old-version process recreating its tables during the upgrade) is
     * renamed with the others and then never read: its rows would be neither copied nor rejected.
     * In the verify pass that is an impossible state: stopped with an error naming the table,
     * nothing written, never complete.
     */
    public function testASourceThatAppearsDuringTheUpgradeStopsIt(): void
    {
        $fixture = $this->fixture();
        $fixture->connection->executeStatement('drop table reader_search, reader_metadata');
        $fixture->connection->executeStatement('drop table reader');
        $fixture->insertMany('entities', 30);
        $fixture->insert('config', ['title' => 'Site']);
        $copier = $this->copier();
        $copier->step(microtime(true) - 1);
        self::assertSame('0', (string) $fixture->connection->fetchOne("select source_existed from idno_upgrade_progress where collection = 'reader'"));
        foreach (['reader' => 'entities', 'reader_metadata' => 'entities_metadata', 'reader_search' => 'entities_search'] as $table => $like) {
            $fixture->connection->executeStatement('create table ' . $table . ' like ' . $like);
        }
        $feed = $fixture->insert('reader', ['entity_subtype' => 'Idno\\Entities\\Reader\\Feed', 'feed_url' => 'https://feed.example/']);

        $progress = null;
        for ($i = 0; $i < 10; ++$i) {
            $progress = $copier->step(microtime(true) + self::FAR);
            if ($progress->error !== null || $progress->complete) {
                break;
            }
        }

        self::assertNotNull($progress);
        self::assertFalse($progress->complete);
        self::assertNotNull($progress->error);
        self::assertStringContainsString('legacy_reader', $progress->error);
        self::assertStringContainsString('Nothing was changed', $progress->error);
        $before = $this->rowCounts();
        $again = $copier->step(microtime(true) + self::FAR);
        self::assertFalse($again->complete);
        self::assertNotNull($again->error);
        self::assertSame($before, $this->rowCounts());
        self::assertNull($this->store()->findByUuid('reader', $feed['uuid']));
        self::assertFalse($copier->completion());
    }

    /**
     * completion(): null before any upgrade began, false while it runs, true once it is complete;
     * one statement, on the progress table (which the caller knows exists).
     */
    public function testCompletion(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 30);
        $fixture->insert('config', ['title' => 'Site']);
        $copier = $this->copier();

        self::assertNull($copier->completion());
        $copier->step(microtime(true) - 1);
        self::assertFalse($copier->completion());
        $this->complete($copier);
        self::assertTrue($copier->completion());
    }

    /**
     * In the copy phase a 1.x table dropped by hand (here `entities_metadata`, which the copy does not
     * read) stops the upgrade with a public-safe message naming it: the gate stays closed (an upgrade
     * on record and not complete) and the copier says why.
     */
    public function testAOneXTableDroppedByHandInTheCopyPhaseIsNamed(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 600);
        $fixture->insert('config', ['title' => 'Site']);
        $copier = $this->copier();
        $copier->step(microtime(true) - 1);
        $this->dropTable('entities_metadata');

        $progress = $copier->step(microtime(true) + self::FAR);

        self::assertNotNull($progress->error);
        self::assertStringContainsString('entities_metadata', $progress->error);
        self::assertNull($progress->errorDetail);
        self::assertFalse($progress->complete);
    }

    /**
     * The one evidence rule: tables with 1.x names that are not a 1.x database (another
     * application's `config`, `session`, `versions`) are no 1.x copy to report.
     */
    public function testForeignOneXNamesAreNoLegacyDatabase(): void
    {
        $connection = PrivateDatabase::mysql('upgrade');
        $this->connections[] = $connection;
        try {
            $connection->executeStatement('create table config (x int)');
            $connection->executeStatement('create table session (x int)');
            $connection->executeStatement('create table versions (label varchar(10), value varchar(10))');
            $copier = new LegacyDataCopier($connection, new MySqlDialect(), new AttributeTypeMap(), new UpgradeLock($connection));

            self::assertTrue($copier->progress()->noLegacyDatabase);
            self::assertTrue($copier->step(microtime(true) + self::FAR)->noLegacyDatabase);
            self::assertSame(0, (int) $connection->fetchOne('select count(*) from idno_upgrade_progress'));
        } finally {
            array_pop($this->connections);
            PrivateDatabase::drop($connection);
        }
    }

    /**
     * A progress table made before `error_detail` existed (a development database) fails with a
     * message that says so, not with an undefined-key warning.
     */
    public function testAProgressTableWithoutTheDetailColumnSaysSo(): void
    {
        $fixture = $this->fixture();
        $copier = $this->copier();
        $copier->step(microtime(true) - 1);
        $fixture->connection->executeStatement('alter table idno_upgrade_progress drop column error_detail');

        try {
            $copier->progress();
            self::fail('A progress table without error_detail is refused.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('error_detail', $e->getMessage());
        }
    }

    public function testAVersionsTableOfAnotherShapeIsNoVersion(): void
    {
        $fixture = $this->fixture(2020120301, false);
        $this->dropTable('versions');
        $fixture->connection->executeStatement('create table versions (id int, name varchar(10))');

        self::assertNull($this->copier()->legacySchemaVersion());
    }

    /**
     * After completion, 1.x tables recreated under their own names are listed (read-only) and do not
     * stop anything.
     */
    public function testReappearedTablesAreListedAfterCompletion(): void
    {
        $fixture = $this->fixture();
        $fixture->insert('config', ['title' => 'Site']);
        $copier = $this->copier();
        self::assertSame([], $copier->reappearedTables());
        $this->complete($copier);
        self::assertSame([], $copier->reappearedTables());
        foreach (['reader' => 'legacy_reader', 'versions' => 'legacy_versions'] as $table => $like) {
            $fixture->connection->executeStatement('create table ' . $table . ' like ' . $like);
        }

        self::assertSame(['reader', 'versions'], $copier->reappearedTables());
        $progress = $copier->step(microtime(true) + self::FAR);
        self::assertTrue($progress->complete);
        self::assertNull($progress->error);
    }

    /**
     * In the verify phase EVERY legacy_ table is dropped by hand: the step stores a public-safe error
     * that names what vanished, writes nothing else, and the upgrade is never complete.
     */
    public function testEveryRenamedTableDroppedInTheVerifyPassStopsTheUpgrade(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 30);
        $fixture->insert('config', ['title' => 'Site']);
        $copier = $this->copier();
        for ($i = 0; $i < 10 && $copier->progress()->phase !== UpgradeProgress::VERIFYING; ++$i) {
            $copier->step(microtime(true) - 1);
        }
        self::assertSame(UpgradeProgress::VERIFYING, $copier->progress()->phase);
        foreach (LegacyDataCopier::LEGACY_TABLES as $table) {
            $this->dropTable('legacy_' . $table);
        }
        $settings = $this->settingsRows();
        $before = $this->rowCounts();

        $progress = $copier->step(microtime(true) + self::FAR);

        self::assertFalse($progress->complete);
        self::assertNotNull($progress->error);
        self::assertStringContainsString('no longer there', $progress->error);
        foreach (['legacy_entities', 'legacy_entities_metadata', 'legacy_entities_search', 'legacy_config'] as $table) {
            self::assertStringContainsString($table, $progress->error);
        }
        self::assertNull($progress->errorDetail, 'public-safe: no private detail');
        $row = $this->progressRow('entities');
        self::assertSame($progress->error, $row['error'], 'stored');
        self::assertSame($settings, $this->settingsRows());
        $after = $this->rowCounts();
        unset($before['idno_upgrade_progress'], $after['idno_upgrade_progress']);
        self::assertSame($before, $after, 'nothing else was written');
        self::assertSame([null, null, null, null], $fixture->connection->fetchFirstColumn('select completed_at from idno_upgrade_progress'));
    }

    public function testTheConfigCopyNeverEmptiesTheSettingsWithoutASourceTable(): void
    {
        $fixture = $this->fixture();
        $fixture->connection->insert('idno_settings', ['name' => 'title', 'value' => '"Kept"', 'position' => 0]);
        $this->dropTable('config');
        $config = new \Idno\Upgrade\ConfigCopier($fixture->connection, new MySqlDialect(), new \Idno\Upgrade\RowWriter($fixture->connection, new MySqlDialect(), new AttributeTypeMap()));

        try {
            $config->copy(\Idno\Upgrade\LegacySource::read($fixture->connection, new MySqlDialect()), 'config');
            self::fail('Without its table the config copy throws.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('config', $e->getMessage());
        }
        self::assertSame(['title' => '"Kept"'], $this->settingsRows());
    }

    public function testAOneXTableThatVanishesInTheCopyStopsTheUpgrade(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 600);
        $fixture->insert('config', ['title' => 'Site']);
        $copier = $this->copier();
        $copier->step(microtime(true) - 1);
        $this->dropTable('entities');
        $before = $this->rowCounts();

        foreach ([1, 2] as $attempt) {
            $progress = $copier->step(microtime(true) + self::FAR);

            self::assertNotNull($progress->error, 'attempt ' . $attempt);
            self::assertStringContainsString('entities', $progress->error);
            self::assertStringContainsString('Nothing was changed', $progress->error);
            self::assertFalse($progress->complete);
            self::assertSame(UpgradeProgress::COPYING, $progress->phase);
            self::assertSame($before, $this->rowCounts());
        }
    }

    // ------------------------------------------------------------------ the config in the verify pass

    public function testANewerConfigWrittenBeforeTheRenameBecomesTheSettings(): void
    {
        $fixture = $this->fixture();
        $first = $fixture->insert('config', ['title' => 'First', 'created' => 1_600_000_000]);
        $middleware = new FailingStatementMiddleware();
        $newer = null;
        $middleware->hookOn('/^rename table/i', static function () use ($fixture, &$newer): void {
            $newer = $fixture->insert('config', ['title' => 'Newer', 'created' => 1_700_000_000]);
        });

        $progress = $this->complete($this->copier($this->connect($middleware)));

        self::assertIsArray($newer);
        self::assertSame('Newer', $this->store()->findLatest('config')['title'] ?? null);
        self::assertSame([[$first['uuid'], 'superseded configuration']], array_map(static fn (array $r): array => [$r['legacy_uuid'], $r['reason']], $this->rejects()));
        self::assertSame(['copied' => 1, 'rejected' => 1], array_intersect_key($progress->collections['config'], ['copied' => 0, 'rejected' => 0]));
    }

    public function testAConfigDeletedBeforeTheRenameIsNoLongerTheSettings(): void
    {
        $fixture = $this->fixture();
        $fixture->insert('config', ['title' => 'Older', 'created' => 1_600_000_000]);
        $newest = $fixture->insert('config', ['title' => 'Newest', 'created' => 1_700_000_000]);
        $middleware = new FailingStatementMiddleware();
        $middleware->hookOn('/^rename table/i', static function () use ($fixture, $newest): void {
            $fixture->connection->executeStatement('delete from config where uuid = ?', [$newest['uuid']]);
        });

        $progress = $this->complete($this->copier($this->connect($middleware)));

        self::assertSame('Older', $this->store()->findLatest('config')['title'] ?? null);
        self::assertSame([], $this->rejects());
        self::assertSame(['copied' => 1, 'rejected' => 0], array_intersect_key($progress->collections['config'], ['copied' => 0, 'rejected' => 0]));
    }

    public function testAConfigReplacedBeforeTheRenameWithTheSameCountIsComputedAgain(): void
    {
        $fixture = $this->fixture();
        $older = $fixture->insert('config', ['title' => 'Older', 'created' => 1_600_000_000]);
        $newest = $fixture->insert('config', ['title' => 'Newest', 'created' => 1_700_000_000]);
        $middleware = new FailingStatementMiddleware();
        $middleware->hookOn('/^rename table/i', static function () use ($fixture, $newest): void {
            $fixture->connection->executeStatement('delete from config where uuid = ?', [$newest['uuid']]);
            $fixture->insert('config', ['title' => 'Replacement', 'created' => 1_800_000_000]);
        });

        $progress = $this->complete($this->copier($this->connect($middleware)));

        self::assertSame('Replacement', $this->store()->findLatest('config')['title'] ?? null);
        self::assertSame([[$older['uuid'], 'superseded configuration']], array_map(static fn (array $r): array => [$r['legacy_uuid'], $r['reason']], $this->rejects()));
        self::assertSame(['copied' => 1, 'rejected' => 1], array_intersect_key($progress->collections['config'], ['copied' => 0, 'rejected' => 0]));
    }

    // ------------------------------------------------------------------ rows the database refuses

    public function testARowTheDatabaseRefusesIsARejectAndTheRestOfItsBatchIsCopied(): void
    {
        $fixture = $this->fixture();
        $first = $fixture->insert('entities', ['uuid' => 'https://legacy.example/view/1', 'title' => 'First']);
        // Valid for PHP (depth 512), deeper than MySQL (100) and MariaDB (32) accept.
        $deep = '{"_id":"deep","access":"PUBLIC","x":' . str_repeat('[', 101) . str_repeat(']', 101) . '}';
        $tooDeep = $fixture->insert('entities', ['_id' => 'deep', 'uuid' => 'https://legacy.example/view/2', 'title' => 'Deep'], [], $deep);
        // Valid for PHP; MySQL refuses the number, MariaDB the indexed value made from it.
        $huge = '{"_id":"huge","access":1e400}';
        $tooBig = $fixture->insert('entities', ['_id' => 'huge', 'uuid' => 'https://legacy.example/view/3', 'title' => 'Huge'], [], $huge);
        $last = $fixture->insert('entities', ['uuid' => 'https://legacy.example/view/4', 'title' => 'Last']);

        $progress = $this->complete($this->copier());

        self::assertSame(['source' => 4, 'copied' => 2, 'rejected' => 2, 'done' => true], $progress->collections['entities']);
        self::assertSame([$first['_id'], $last['_id']], $fixture->connection->fetchFirstColumn('select id from idno_entities order by uuid'));
        $rejects = self::sortRejects($this->rejects());
        self::assertSame([[$tooDeep['uuid'], $deep], [$tooBig['uuid'], $huge]], array_map(static fn (array $r): array => [$r['legacy_uuid'], $r['contents']], $rejects));
        foreach ($rejects as $reject) {
            self::assertStringStartsWith('the database refused the document', $reject['reason']);
            self::assertLessThanOrEqual(255, mb_strlen($reject['reason']));
        }
        // Every document is JSON the engine accepts, so queries on document fields work.
        self::assertSame(0, (int) $fixture->connection->fetchOne('select count(*) from idno_entities where not json_valid(data)'));
        self::assertCount(1, $this->store()->find('entities', Criteria::fromLegacyArray(['title' => 'First']), 10, 0));
        self::assertCount(2, $this->store()->find('entities', Criteria::fromLegacyArray(['title' => ['$in' => ['First', 'Last']]]), 10, 0));
        self::assertCount(0, $this->store()->find('entities', Criteria::fromLegacyArray(['access' => 'PUBLIC']), 10, 0));
    }

    /**
     * The schema's `ix_access` column holds 255 characters (as `uuid` and `owner` do): a 1.x row
     * with a longer access value is a reject, as a save of it is refused; nothing is ever cut.
     */
    public function testARowWhoseAccessIsLongerThanItsColumnIsAReject(): void
    {
        $fixture = $this->fixture();
        $longest = $fixture->insert('entities', ['uuid' => 'https://legacy.example/view/1', 'access' => str_repeat('a', 255)]);
        $longer = $fixture->insert('entities', ['uuid' => 'https://legacy.example/view/2', 'access' => str_repeat('a', 256)]);

        $progress = $this->complete($this->copier());

        self::assertSame(['source' => 2, 'copied' => 1, 'rejected' => 1, 'done' => true], $progress->collections['entities']);
        self::assertSame([$longest['_id']], $fixture->connection->fetchFirstColumn('select id from idno_entities'));
        $rejects = $this->rejects();
        self::assertSame([$longer['uuid']], array_column($rejects, 'legacy_uuid'));
        self::assertStringStartsWith('the database refused the document', $rejects[0]['reason']);
        self::assertCount(1, $this->store()->find('entities', Criteria::fromLegacyArray(['access' => str_repeat('a', 255)]), 10, 0));
    }

    public function testUuidsEqualUnderTheNewCollationAreCopiedOnceAndTheOtherIsRejected(): void
    {
        $fixture = $this->fixture();
        // Different in 1.x (utf8_general_ci), equal in utf8mb4_unicode_520_ci (ß is ss).
        $strasse = $fixture->insert('entities', ['uuid' => 'https://legacy.example/strasse', 'title' => 'Strasse']);
        $strasseSharp = $fixture->insert('entities', ['uuid' => 'https://legacy.example/straße', 'title' => 'Straße']);

        $progress = $this->complete($this->copier());

        // utf8_general_ci orders ß as s: `straße` (s,t,r,a,s,e) comes before `strasse`, so it is copied.
        self::assertSame([$strasseSharp['_id']], $fixture->connection->fetchFirstColumn('select id from idno_entities'));
        $rejects = $this->rejects();
        self::assertCount(1, $rejects);
        self::assertSame($strasse['uuid'], $rejects[0]['legacy_uuid']);
        self::assertSame($strasse['contents'], $rejects[0]['contents']);
        self::assertSame('its uuid equals that of the copied record https://legacy.example/straße under the new schema\'s collation', $rejects[0]['reason']);
        self::assertTrue($progress->complete);
    }

    public function testAVerificationMismatchRollsTheBatchBackAndThrows(): void
    {
        $fixture = $this->fixture();
        $fixture->insert('entities', ['title' => 'One']);
        $fixture->insert('entities', ['title' => 'Two']);
        $fixture->connection->executeStatement('create trigger alter_data before insert on idno_entities for each row set new.data = concat(new.data, \' \')');
        $logger = self::logger();

        try {
            $this->copier(null, $logger)->step(microtime(true) + self::FAR);
            self::fail('A copy that differs from its source throws.');
        } catch (\UnexpectedValueException $e) {
            self::assertStringContainsString('does not match its source', $e->getMessage());
        }

        self::assertSame(0, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));
        $row = $this->progressRow('entities');
        self::assertSame('0', (string) $row['copied']);
        self::assertNull($row['position']);
        // The detail (it names the record) is private: stored apart, logged; the public error is the
        // generic sentence.
        self::assertSame(\Idno\Upgrade\PublicMessage::stopped()->text, (string) $row['error']);
        self::assertStringContainsString('does not match its source', (string) $row['error_detail']);
        self::assertStringContainsString('https://legacy.example/view/', (string) $row['error_detail']);
        self::assertStringContainsString('does not match its source', implode("\n", $logger->messages));
        $progress = $this->copier()->progress();
        self::assertSame(\Idno\Upgrade\PublicMessage::stopped()->text, $progress->error);
        self::assertStringContainsString('does not match its source', (string) $progress->errorDetail);
    }

    /**
     * A driver error's message (SQL state, a value) is private detail too.
     */
    public function testADriverErrorIsPrivateDetail(): void
    {
        $fixture = $this->fixture();
        $fixture->insert('entities', ['uuid' => 'https://legacy.example/view/secret-uuid', 'title' => 'One']);
        $middleware = new FailingStatementMiddleware();
        $middleware->failOn('/^insert into `idno_entities`/i');

        try {
            $this->copier($this->connect($middleware))->step(microtime(true) + self::FAR);
            self::fail('A failing statement is thrown.');
        } catch (\Throwable) {
        }

        $progress = $this->copier()->progress();
        self::assertSame(\Idno\Upgrade\PublicMessage::stopped()->text, $progress->error);
        self::assertNotNull($progress->errorDetail);
        self::assertStringNotContainsString('secret-uuid', $progress->error);
    }

    /**
     * An impossible state found before any progress row exists cannot be stored, but progress()
     * reports it from the tables alone, and step() returns it: it is never dropped.
     */
    public function testAnImpossibleStateWithoutProgressRowsIsReported(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 10);
        $fixture->connection->executeStatement('rename table ' . implode(', ', array_map(static fn (string $t): string => $t . ' to legacy_' . $t, LegacyDataCopier::LEGACY_TABLES)));
        $copier = $this->copier();

        foreach ([$copier->progress(), $copier->step(microtime(true) + self::FAR), $copier->progress()] as $progress) {
            self::assertNotNull($progress->error);
            self::assertStringContainsString('the copy had not finished', $progress->error);
            self::assertFalse($progress->complete);
        }
        self::assertSame(0, (int) $fixture->connection->fetchOne('select count(*) from idno_upgrade_progress'));
    }

    // ------------------------------------------------------------------ created, config, packets

    public function testTheCreatedColumnComesFromTheDocument(): void
    {
        $fixture = $this->fixture();
        $differs = $fixture->insert('entities', ['title' => 'Differs', 'created' => 1_700_000_000], ['created' => '2001-02-03 04:05:06']);
        $numeric = $fixture->insert('entities', ['title' => 'Numeric', 'created' => '1600000000.5'], ['created' => '2001-02-03 04:05:06']);
        $missing = $fixture->insert('entities', ['title' => 'Missing'], [], '{"title":"Missing"}');
        $fixture->connection->executeStatement("update entities set created = '2002-03-04 05:06:07' where _id = ?", [$missing['_id']]);
        $notATimestamp = $fixture->insert('entities', ['title' => 'Words', 'created' => 'yesterday'], ['created' => '2003-04-05 06:07:08']);

        $this->complete($this->copier());

        $column = fn (array $row): string => (string) $fixture->connection->fetchOne('select unix_timestamp(created) from legacy_entities where _id = ?', [$row['_id']]);
        $copied = fn (array $row): string => (string) $fixture->connection->fetchOne('select created from idno_entities where id = ?', [$row['_id']]);
        self::assertSame('1700000000', $copied($differs));
        self::assertSame('1600000000', $copied($numeric));
        self::assertSame($column($missing), $copied($missing));
        self::assertSame($column($notATimestamp), $copied($notATimestamp));
        self::assertNotSame('1700000000', $column($differs));
    }

    public function testAConfigWithAKeyTheSettingsCannotHoldIsNotTheSettings(): void
    {
        $fixture = $this->fixture();
        $old = $fixture->insert('config', ['title' => 'Old', 'created' => 1_600_000_000]);
        $middle = $fixture->insert('config', ['title' => 'Middle', 'created' => 1_650_000_000]);
        $bad = $fixture->insert('config', ['title' => 'Newest', 'trailing ' => 'space', 'created' => 1_700_000_000]);

        $progress = $this->complete($this->copier());

        self::assertSame(json_decode($middle['contents'], true), $this->store()->findLatest('config'));
        $reasons = [];
        foreach ($this->rejects() as $reject) {
            $reasons[$reject['legacy_uuid']] = $reject['reason'];
        }
        self::assertSame('superseded configuration', $reasons[$old['uuid']]);
        self::assertStringStartsWith('cannot be the settings: ', $reasons[$bad['uuid']]);
        self::assertStringContainsString('end in a space', $reasons[$bad['uuid']]);
        self::assertSame(['source' => 3, 'copied' => 1, 'rejected' => 2, 'done' => true], $progress->collections['config']);
    }

    public function testWhenNoConfigCanBeTheSettingsTheSettingsStayEmpty(): void
    {
        $fixture = $this->fixture();
        $fixture->insert('config', ['title' => 'Bad', '' => 'empty key']);
        $fixture->insert('config', ['title' => 'Bad'], [], 'not json');

        $progress = $this->complete($this->copier());

        self::assertNull($this->store()->findLatest('config'));
        self::assertSame([], $this->settingsRows());
        self::assertCount(2, $this->rejects());
        self::assertSame(['source' => 2, 'copied' => 0, 'rejected' => 2, 'done' => true], $progress->collections['config']);
        self::assertTrue($progress->complete);
    }

    public function testConfigsCreatedInTheSameSecondAreOrderedByUuid(): void
    {
        $fixture = $this->fixture();
        $fixture->insert('config', ['uuid' => 'https://legacy.example/view/b', 'title' => 'B', 'created' => 1_700_000_000]);
        $fixture->insert('config', ['uuid' => 'https://legacy.example/view/a', 'title' => 'A', 'created' => 1_700_000_000]);

        $this->complete($this->copier());

        self::assertSame('B', $this->store()->findLatest('config')['title'] ?? null, 'ties: the greatest uuid');
    }

    public function testARowTooLargeForOneStatementIsCopiedInTwo(): void
    {
        $fixture = $this->fixture();
        $body = str_repeat('word ', 500);
        $row = $fixture->insert('entities', ['title' => 'Large', 'body' => $body]);

        $this->complete($this->copier(null, null, 4096));

        self::assertSame($row['contents'], $fixture->connection->fetchOne('select data from idno_entities where id = ?', [$row['_id']]));
        self::assertSame($this->legacySearch('entities', $row['_id'], 'legacy_'), $fixture->connection->fetchOne('select search from idno_entities where id = ?', [$row['_id']]));
    }

    public function testARowTooLargeForTheServerStopsTheUpgradeAndSaysSo(): void
    {
        $fixture = $this->fixture();
        $fixture->insert('entities', ['title' => 'Small']);
        $fixture->insert('entities', ['title' => 'Huge', 'body' => str_repeat('word ', 2000)]);

        try {
            $this->copier(null, null, 4096)->step(microtime(true) + self::FAR);
            self::fail('A row larger than one statement can carry stops the upgrade.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('max_allowed_packet', $e->getMessage());
        }
        self::assertSame(0, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));
        self::assertSame(0, (int) $fixture->connection->fetchOne('select count(*) from idno_upgrade_rejects'));
    }

    // ------------------------------------------------------------------ the lock

    public function testTheLockIsPerDatabaseAndNeverWaits(): void
    {
        $this->fixture(2020120301, false);
        $one = new UpgradeLock($this->connect());
        $two = new UpgradeLock($this->connect());

        self::assertTrue($one->acquire());
        self::assertTrue($one->holds());
        $started = microtime(true);
        self::assertFalse($two->acquire());
        self::assertLessThan(2.0, microtime(true) - $started);
        self::assertFalse($two->holds());
        self::assertNotNull($this->fixture?->connection->fetchOne('select is_used_lock(?)', [$this->lockName()]));

        $one->release();
        self::assertFalse($one->holds());
        self::assertTrue($two->acquire());
        $two->release();
    }

    public function testABatchIsNotWrittenUnderALockTheStepNoLongerHolds(): void
    {
        $fixture = $this->fixture();
        $ids = $fixture->insertMany('entities', 1200);
        $middleware = new FailingStatementMiddleware();
        $connection = $this->connect($middleware);
        $other = $this->connect();
        $name = $this->lockName();
        // Just before the second batch checks its lock (the step asked twice before: whether its
        // caller held it, and acquire()), the lock
        // passes to another connection (as when the step's connection was replaced by a new one).
        $middleware->hookOn('/is_used_lock/', static function () use ($connection, $other, $name): void {
            $connection->fetchOne('select release_lock(?)', [$name]);
            $other->fetchOne('select get_lock(?, 0)', [$name]);
        }, 4);

        $progress = $this->copier($connection)->step(microtime(true) + self::FAR);

        self::assertSame(500, $progress->collections['entities']['copied']);
        self::assertSame(500, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));
        self::assertFalse($progress->complete);

        $other->fetchOne('select release_lock(?)', [$name]);
        $this->complete($this->copier($connection));
        $copied = $fixture->connection->fetchFirstColumn('select id from idno_entities order by id');
        sort($ids);
        self::assertSame($ids, $copied);
    }

    public function testALostConnectionEndsTheStepAndTheNextStepResumes(): void
    {
        $fixture = $this->fixture();
        $ids = $fixture->insertMany('entities', 1200);
        $middleware = new FailingStatementMiddleware();
        $connection = $this->connect($middleware);
        $session = (int) $connection->fetchOne('select connection_id()');
        $admin = $this->connect();
        $middleware->hookOn('/^insert into `idno_entities`/', static function () use ($admin, $session): void {
            $admin->executeStatement('kill ' . $session);
        }, 503);

        try {
            $this->copier($connection)->step(microtime(true) + self::FAR);
            self::fail('The lost connection ends the step.');
        } catch (\Doctrine\DBAL\Exception) {
        }

        self::assertSame(500, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));
        self::assertSame('500', (string) $this->progressRow('entities')['copied']);

        $this->complete($this->copier($connection));
        $copied = $fixture->connection->fetchFirstColumn('select id from idno_entities order by id');
        sort($ids);
        self::assertSame($ids, $copied);
    }

    public function testAStepLeavesTheLockAsItFoundIt(): void
    {
        $this->fixture()->insert('entities', ['title' => 'A post']);
        $connection = $this->connect();
        $lock = new UpgradeLock($connection);
        self::assertTrue($lock->acquire());

        $copier = new LegacyDataCopier($connection, new MySqlDialect(), new AttributeTypeMap(), new UpgradeLock($connection));
        $progress = $copier->step(microtime(true) + self::FAR);

        self::assertSame(1, $progress->collections['entities']['copied'], 'the step worked under the caller\'s lock');
        self::assertTrue($lock->holds(), 'the caller still holds the lock');
        $lock->release();
        self::assertFalse($lock->holds());

        // A step that took the lock itself gives it up.
        $copier->step(microtime(true) - 1);
        self::assertFalse($lock->holds());
    }

    public function testAcquireAndReleaseStayBalancedOnOneSession(): void
    {
        $this->fixture(2020120301, false);
        $connection = $this->connect();
        $other = $this->connect();
        // The session took the lock twice already (an earlier release() that failed, say).
        $connection->fetchOne('select get_lock(?, 0)', [$this->lockName()]);
        $connection->fetchOne('select get_lock(?, 0)', [$this->lockName()]);
        $lock = new UpgradeLock($connection);

        self::assertTrue($lock->acquire());
        self::assertTrue($lock->acquire());
        $lock->release();

        self::assertFalse($lock->holds());
        self::assertSame(1, (int) $other->fetchOne('select get_lock(?, 0)', [$this->lockName()]), 'one release() frees the lock');
        $other->fetchOne('select release_lock(?)', [$this->lockName()]);
    }

    // ------------------------------------------------------------------ 1.x tables converted to utf8mb4

    /**
     * @return iterable<string, array{string}>
     */
    public static function convertedCollations(): iterable
    {
        yield 'utf8mb4_general_ci' => ['utf8mb4_general_ci'];
        yield 'utf8mb4_unicode_ci' => ['utf8mb4_unicode_ci'];
        yield 'utf8mb4_unicode_520_ci' => ['utf8mb4_unicode_520_ci'];
        yield 'utf8mb4_0900_ai_ci (MySQL 8 only)' => ['utf8mb4_0900_ai_ci'];
    }

    /**
     * @dataProvider convertedCollations
     */
    public function testOneXTablesConvertedToUtf8mb4AreCopied(string $collation): void
    {
        $fixture = $this->fixture(2020120301, true, $collation);
        $rows = $fixture->insertMany('entities', 120);
        $emoji = $fixture->insert('entities', ['uuid' => 'https://legacy.example/view/🎉party', 'title' => 'Party 🎉'], [], null);
        $contents = '{"_id":"emoji2","uuid":"https://legacy.example/view/𝄞","title":"Clef 𝄞 😀","created":1700000000}';
        $clef = $fixture->insert('entities', ['_id' => 'emoji2', 'uuid' => 'https://legacy.example/view/𝄞', 'title' => 'Clef 𝄞 😀'], [], $contents);
        $site = $fixture->insert('site', ['title' => 'Site ✨']);
        $fixture->insert('config', ['title' => 'Config 🎛']);
        $middleware = new FailingStatementMiddleware();
        // Written during the rename window, so that the verify pass copies it: its lookups too.
        $middleware->hookOn('/^rename table/i', static function () use ($fixture): void {
            $fixture->insert('entities', ['_id' => 'emoji3', 'uuid' => 'https://legacy.example/view/0🎈', 'title' => 'Balloon'], [], '{"title":"Balloon 🎈"}');
        });

        $progress = $this->complete($this->copier($this->connect($middleware)));

        self::assertSame($collation, $fixture->connection->fetchOne("select collation_name from information_schema.columns where table_schema = database() and table_name = 'legacy_entities' and column_name = 'uuid'"));
        self::assertSame(123, (int) $fixture->connection->fetchOne('select count(*) from idno_entities'));
        self::assertSame($contents, $fixture->connection->fetchOne('select data from idno_entities where id = ?', ['emoji2']));
        self::assertSame($clef['uuid'], $fixture->connection->fetchOne('select uuid from idno_entities where id = ?', ['emoji2']));
        self::assertSame('{"title":"Balloon 🎈"}', $this->store()->findById('entities', 'emoji3') === null ? null : $fixture->connection->fetchOne('select data from idno_entities where id = ?', ['emoji3']));
        self::assertSame(json_decode($emoji['contents'], true), $this->store()->findByUuid('entities', $emoji['uuid']));
        self::assertSame(json_decode($site['contents'], true), $this->store()->findById('site', $site['_id']));
        self::assertSame('Config 🎛', $this->store()->findLatest('config')['title'] ?? null);
        self::assertSame([], $this->rejects());
        self::assertCount(120, array_intersect($rows, $fixture->connection->fetchFirstColumn('select id from idno_entities')));
        self::assertTrue($progress->complete);
    }

    // ------------------------------------------------------------------ bounded statements

    public function testNoStatementReadsAOneXTableUnbounded(): void
    {
        $fixture = $this->fixture();
        $fixture->insertMany('entities', 1500);
        $fixture->insertMany('reader', 40);
        $fixture->insert('site', ['title' => 'Site']);
        $fixture->insert('config', ['title' => 'Config']);
        $recorder = new StatementRecorder();
        $connection = $this->connect($recorder);

        $copier = $this->copier($connection);
        for ($i = 0; $i < 50 && !$copier->step(microtime(true) - 1)->complete; ++$i) {
        }

        self::assertTrue($copier->progress()->complete);
        $names = implode('|', array_merge(LegacyDataCopier::LEGACY_TABLES, array_map(static fn (string $t): string => 'legacy_' . $t, LegacyDataCopier::LEGACY_TABLES)));
        $counts = [];
        $reads = 0;
        foreach ($recorder->statements as ['sql' => $sql]) {
            if (preg_match('/`(' . $names . ')`/', $sql, $match) !== 1) {
                continue;
            }
            ++$reads;
            $table = $match[1];
            self::assertDoesNotMatchRegularExpression('/_metadata`/', $sql, 'the metadata tables are never read');
            if (preg_match('/^select count\(\*\) from `(\w+)`$/', $sql, $count) === 1) {
                $counts[] = $count[1];
                continue;
            }
            $bounded = preg_match('/ limit \d+$/', $sql) === 1
                // a primary key: `uuid` of a collection table, `_id` of a search table
                || preg_match('/ where `uuid` (in \(|= )convert\(\?/', $sql) === 1
                || preg_match('/_search` where `_id` in \(convert\(\?/', $sql) === 1;
            self::assertTrue($bounded, 'unbounded read of ' . $table . ': ' . $sql);
            if (preg_match('/ limit \d+$/', $sql) === 1 && !str_contains($sql, 'order by `created` desc')) {
                // A LIMIT on the primary key's order, from the start or after a key.
                self::assertMatchesRegularExpression('/^select [^;]+ from `\w+`( where `uuid` > convert\(\? using \w+\) collate \w+)? order by `uuid` limit \d+$/', $sql);
            }
        }
        sort($counts);
        self::assertSame(['config', 'entities', 'reader', 'site'], $counts, 'one count per collection, when its progress row is made');
        self::assertGreaterThan(10, $reads);
    }

    // ------------------------------------------------------------------ defence in depth

    public function testADocumentTheDatabaseDoesNotAcceptAsJsonIsARejectWithoutGeneratedColumns(): void
    {
        $fixture = $this->fixture();
        foreach ($fixture->connection->fetchFirstColumn("select column_name from information_schema.columns where table_schema = database() and table_name = 'idno_site' and column_name like 'ix\\_%'") as $column) {
            $fixture->connection->executeStatement('alter table idno_site drop column ' . $column);
        }
        $good = $fixture->insert('site', ['title' => 'Good', 'uuid' => 'https://legacy.example/1']);
        // Valid for PHP; MySQL's JSON_VALID() fails on it (depth 101), MariaDB's says 0 (depth 32).
        $deep = '{"x":' . str_repeat('[', 101) . str_repeat(']', 101) . '}';
        $bad = $fixture->insert('site', ['title' => 'Deep', 'uuid' => 'https://legacy.example/2'], [], $deep);

        $this->complete($this->copier());

        self::assertSame([$good['_id']], $fixture->connection->fetchFirstColumn('select id from idno_site'));
        $rejects = $this->rejects();
        self::assertCount(1, $rejects);
        self::assertSame([$bad['uuid'], $deep], [$rejects[0]['legacy_uuid'], $rejects[0]['contents']]);
        self::assertContains(
            (string) preg_replace('/:.*$/', '', $rejects[0]['reason']),
            ['the database does not accept the document as JSON', 'the database refused the document'],
        );
    }

    // ------------------------------------------------------------------ helpers

    private function fixture(int $schema = 2020120301, bool $prepare = true, ?string $collation = null): LegacyFixture
    {
        if ($collation !== null) {
            $this->fixture = LegacyFixture::create('upgrade', $schema);
            $available = $this->fixture->connection->fetchOne('select count(*) from information_schema.collations where collation_name = ?', [$collation]);
            if ((int) $available === 0) {
                self::markTestSkipped('This server has no collation ' . $collation . '.');
            }
            $this->fixture->drop();
            $this->fixture = null;
        }
        $this->fixture = LegacyFixture::create('upgrade', $schema, $collation);
        if ($prepare) {
            $this->fixture->prepareNewSchema();
        }

        return $this->fixture;
    }

    private function connection(): Connection
    {
        self::assertNotNull($this->fixture);

        return $this->fixture->connection;
    }

    /**
     * Another connection to the private database, through the middleware when one is given.
     */
    private function connect(?Middleware $middleware = null): Connection
    {
        $configuration = new Configuration();
        if ($middleware !== null) {
            $configuration->setMiddlewares([$middleware]);
        }
        $connection = DriverManager::getConnection($this->connection()->getParams(), $configuration);

        return $this->connections[] = $connection;
    }

    private function copier(?Connection $connection = null, ?\Psr\Log\LoggerInterface $logger = null, ?int $packetLimit = null): LegacyDataCopier
    {
        $connection ??= $this->connection();

        return new LegacyDataCopier($connection, new MySqlDialect(), new AttributeTypeMap(), new UpgradeLock($connection), $logger ?? self::logger(), $packetLimit);
    }

    private function complete(LegacyDataCopier $copier): UpgradeProgress
    {
        for ($i = 0; $i < 30; ++$i) {
            $progress = $copier->step(microtime(true) + self::FAR);
            if ($progress->complete) {
                return $progress;
            }
        }
        self::fail('The upgrade did not complete: ' . json_encode($copier->progress()));
    }

    private function store(): RecordStore
    {
        return PrivateDatabase::recordStore($this->connection(), null, new AttributeTypeMap());
    }

    private function lockName(): string
    {
        return 'idno_upgrade_' . substr(sha1((string) $this->connection()->getDatabase()), 0, 16);
    }

    /**
     * @return array<string, mixed>
     */
    private function progressRow(string $collection): array
    {
        $row = $this->connection()->fetchAssociative('select * from idno_upgrade_progress where collection = ?', [$collection]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<array{collection: string, legacy_uuid: string, legacy_id: string, reason: string, contents: string}>
     */
    private function rejects(): array
    {
        return $this->connection()->fetchAllAssociative('select collection, legacy_uuid, legacy_id, reason, contents from idno_upgrade_rejects order by n');
    }

    /**
     * @param list<array{collection: string, legacy_uuid: string, legacy_id: string, reason: string, contents: string}> $rejects
     *
     * @return list<array{collection: string, legacy_uuid: string, legacy_id: string, reason: string, contents: string}>
     */
    private static function sortRejects(array $rejects): array
    {
        usort($rejects, static fn (array $a, array $b): int => [$a['collection'], $a['legacy_uuid']] <=> [$b['collection'], $b['legacy_uuid']]);

        return $rejects;
    }

    /**
     * @param list<list<string>> $rows
     *
     * @return list<list<string>>
     */
    private static function sortRows(array $rows): array
    {
        sort($rows);

        return $rows;
    }

    /**
     * @return array<string, string> name => value, in position order
     */
    private function settingsRows(): array
    {
        $rows = [];
        foreach ($this->connection()->fetchAllNumeric('select name, value from idno_settings order by position') as [$name, $value]) {
            $rows[(string) $name] = (string) $value;
        }

        return $rows;
    }

    private function dropTable(string $table): void
    {
        $this->connection()->executeStatement('set foreign_key_checks = 0');
        $this->connection()->executeStatement('drop table ' . $table);
        $this->connection()->executeStatement('set foreign_key_checks = 1');
    }

    /**
     * The row count of every table of the private database.
     *
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $counts = [];
        foreach ($this->tables() as $table) {
            $counts[$table] = (int) $this->connection()->fetchOne('select count(*) from `' . $table . '`');
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @return list<string> the tables of the private database, lower case
     */
    private function tables(): array
    {
        return array_map('strtolower', $this->connection()->fetchFirstColumn('select table_name from information_schema.tables where table_schema = database()'));
    }

    private function legacySearch(string $collection, string $id, string $prefix = ''): string
    {
        $table = $this->tables();
        $name = in_array($collection . '_search', $table, true) ? $collection . '_search' : 'legacy_' . $collection . '_search';
        if ($prefix !== '') {
            $name = $prefix . $collection . '_search';
        }

        return (string) $this->connection()->fetchOne('select search from ' . $name . ' where _id = ?', [$id]);
    }

    /**
     * CHECKSUM TABLE of each table, keyed by the name in $as (default: the table's own).
     *
     * @param list<string>      $tables
     * @param list<string>|null $as
     *
     * @return array<string, string>
     */
    private function checksums(array $tables, ?array $as = null): array
    {
        $sums = [];
        foreach ($tables as $i => $table) {
            $row = $this->connection()->fetchNumeric('checksum table ' . $table . ' extended');
            self::assertIsArray($row);
            $sums[$as[$i] ?? $table] = (string) $row[1];
        }

        return $sums;
    }

    /**
     * Every class under Idno/ and IdnoPlugins/ with its own #[AsEntity], and its collection.
     *
     * @return array<class-string, string>
     */
    private static function entityClasses(): array
    {
        $root = dirname(__DIR__, 2);
        $classes = [];
        foreach ([$root . '/Idno', ...(glob($root . '/IdnoPlugins/*', GLOB_ONLYDIR) ?: [])] as $directory) {
            foreach (EntityClassFinder::in($directory) as $class) {
                $attributes = (new \ReflectionClass($class))->getAttributes(AsEntity::class);
                if ($attributes !== []) {
                    $classes[$class] = $attributes[0]->newInstance()->collection;
                }
            }
        }
        ksort($classes);

        return $classes;
    }

    /**
     * A logger that keeps its messages.
     */
    private static function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->messages[] = $level . ': ' . $message;
            }
        };
    }
}
