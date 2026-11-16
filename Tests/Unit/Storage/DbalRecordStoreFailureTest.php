<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Idno\Storage\Criteria;
use Idno\Storage\DbalRecordStore;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\Platform\SqliteDialect;
use PHPUnit\Framework\TestCase;

/**
 * RecordStore's error contract on DbalRecordStore: a failure of the database that is neither a
 * rejected operand (which matches nothing) nor refused data (which makes a write return false) is
 * thrown, from every method. Over an in-memory SQLite database, with a driver middleware that
 * fails one chosen statement with an I/O error. A failed save() leaves nothing of the record
 * behind (its transaction is rolled back) and the connection usable.
 */
final class DbalRecordStoreFailureTest extends TestCase
{
    private Connection $connection;

    private FailingStatementMiddleware $failure;

    private DbalRecordStore $store;

    protected function setUp(): void
    {
        $this->failure = new FailingStatementMiddleware();
        $this->connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            (new Configuration())->setMiddlewares([new SqliteConnectionSetup(), $this->failure]),
        );
        SqliteTestDatabase::loadSchema($this->connection);
        $this->store = new DbalRecordStore($this->connection, new SqliteDialect($this->connection), new FixedRecordDefaults(), static fn (string $text): string => $text, true, false);
        // A first save, so that the table list and the upsert targets are known before a failure is armed.
        $this->assertSame('existing', $this->store->save('entities', ['_id' => 'existing', 'title' => 'Before', 'tags' => ['a', 'b'], 'created' => 1_700_000_000]));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    /**
     * @return array<string, array{string, \Closure(DbalRecordStore): mixed}>
     */
    public static function failingReadsAndDeletes(): array
    {
        $criteria = Criteria::fromLegacyArray(['title' => 'Before']);

        return [
            'find' => ['/^select distinct "entities"\.\*/', static fn (DbalRecordStore $store): mixed => $store->find('entities', $criteria, 10, 0)],
            'count' => ['/^select count\(distinct/', static fn (DbalRecordStore $store): mixed => $store->count('entities', $criteria)],
            'findById' => ['/where _id = \?/', static fn (DbalRecordStore $store): mixed => $store->findById('entities', 'existing')],
            'findByUuid' => ['/where uuid = \?/', static fn (DbalRecordStore $store): mixed => $store->findByUuid('entities', 'http://example.test/view/existing')],
            'findLatest' => ['/order by created desc limit 1/', static fn (DbalRecordStore $store): mixed => $store->findLatest('entities')],
            'delete' => ['/^delete from "entities" where/', static fn (DbalRecordStore $store): mixed => $store->delete('entities', 'existing')],
            'deleteAll' => ['/^delete from "entities"$/', static fn (DbalRecordStore $store): mixed => $store->deleteAll('entities')],
        ];
    }

    /**
     * @dataProvider failingReadsAndDeletes
     */
    public function testADatabaseFailureIsThrown(string $statement, \Closure $call): void
    {
        $this->failure->failOn($statement);

        $thrown = self::thrown(fn () => $call($this->store));

        $this->assertInstanceOf(DriverException::class, $thrown);
        $this->assertStringEndsWith(FailingStatementMiddleware::MESSAGE, $thrown->getMessage());
        $this->assertCount(1, $this->failure->failed, 'the chosen statement ran and failed');
        // Nothing was deleted, and the connection is usable.
        $this->assertSame('Before', $this->store->findById('entities', 'existing')['title'] ?? null);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function failingSaveStatements(): array
    {
        return [
            'the main row' => ['/^insert into "entities" /', 1],
            'the search row' => ['/^insert into "entities_search" /', 1],
            'the deletion of the metadata rows' => ['/^delete from "entities_metadata"/', 1],
            'the first metadata row' => ['/^insert into "entities_metadata"/', 1],
            'a later metadata row' => ['/^insert into "entities_metadata"/', 3],
        ];
    }

    /**
     * A failure on any statement of save(), of a new record or of a replacement: thrown, the
     * transaction rolled back, nothing of the save stored, the connection usable afterwards.
     *
     * @dataProvider failingSaveStatements
     */
    public function testAFailedSaveIsThrownAndRolledBack(string $statement, int $nth): void
    {
        $before = $this->tables();

        foreach (['a new record' => 'new', 'a replacement' => 'existing'] as $case => $id) {
            $this->failure->failOn($statement, $nth);

            $thrown = self::thrown(fn () => $this->store->save('entities', ['_id' => $id, 'title' => 'After', 'tags' => ['c', 'd', 'e'], 'created' => 1_700_000_100]));

            $this->assertInstanceOf(DriverException::class, $thrown, $case);
            $this->assertStringEndsWith(FailingStatementMiddleware::MESSAGE, $thrown->getMessage(), $case);
            $this->assertFalse($this->connection->isTransactionActive(), $case . ': rolled back');
            $this->assertSame($before, $this->tables(), $case . ': nothing of the save is stored');
        }

        // The connection is usable: the same save now succeeds.
        $this->assertSame('existing', $this->store->save('entities', ['_id' => 'existing', 'title' => 'After', 'created' => 1_700_000_100]));
        $this->assertSame('After', $this->store->findById('entities', 'existing')['title']);
    }

    /**
     * The rows of the three tables of `entities`.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function tables(): array
    {
        return [
            'entities' => $this->connection->fetchAllAssociative('select * from entities order by _id'),
            'entities_search' => $this->connection->fetchAllAssociative('select * from entities_search order by _id'),
            'entities_metadata' => $this->connection->fetchAllAssociative('select _id, name, value from entities_metadata order by _id, name, value'),
        ];
    }

    private static function thrown(\Closure $call): ?\Throwable
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }
}
