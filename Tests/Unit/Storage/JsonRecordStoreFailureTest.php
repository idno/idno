<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Idno\Storage\Criteria;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\IdentityTypeMap;
use Idno\Storage\JsonRecordStore;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use PHPUnit\Framework\TestCase;

/**
 * RecordStore's error contract on JsonRecordStore: a failure of the database that is neither a
 * rejected operand (which matches nothing) nor refused data (which makes a write return false) is
 * thrown, from every method. Over an in-memory SQLite database with the schema migrations run, with
 * a driver middleware that fails one chosen statement with an I/O error. A failed save() stores
 * nothing and leaves the connection usable.
 */
final class JsonRecordStoreFailureTest extends TestCase
{
    private Connection $connection;

    private FailingStatementMiddleware $failure;

    private JsonRecordStore $store;

    protected function setUp(): void
    {
        $this->failure = new FailingStatementMiddleware();
        $this->connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            (new Configuration())->setMiddlewares([new SqliteConnectionSetup(), $this->failure]),
        );
        (new SchemaMigrator($this->connection))->migrate();
        $this->store = $this->newStore();
        // A first save, so that the schema catalog has been read before a failure is armed.
        $this->assertSame('existing', $this->store->save('entities', ['_id' => 'existing', 'title' => 'Before', 'tags' => ['a', 'b'], 'created' => 1_700_000_000]));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    private function newStore(): JsonRecordStore
    {
        return new JsonRecordStore($this->connection, new SqliteDialect($this->connection), new FixedRecordDefaults(), new IdentityTypeMap(), new SchemaCatalog($this->connection), static fn (string $text): string => $text, true, false);
    }

    /**
     * @return array<string, array{string, \Closure(JsonRecordStore): mixed}>
     */
    public static function failingReadsAndDeletes(): array
    {
        $criteria = Criteria::fromLegacyArray(['title' => 'Before']);
        $search = Criteria::fromLegacyArray(['$search' => ['before']]);

        return [
            'find' => ['/^select t\."data" from "idno_entities" t where/', static fn (JsonRecordStore $store): mixed => $store->find('entities', $criteria, 10, 0)],
            'find with a search' => ['/^select t\."data" from "idno_entities" t where/', static fn (JsonRecordStore $store): mixed => $store->find('entities', $search, 10, 0)],
            'count' => ['/^select count\(\*\) from "idno_entities" t/', static fn (JsonRecordStore $store): mixed => $store->count('entities', $criteria)],
            'findById' => ['/where "id" = \?/', static fn (JsonRecordStore $store): mixed => $store->findById('entities', 'existing')],
            'findByUuid' => ['/where "uuid" = \?/', static fn (JsonRecordStore $store): mixed => $store->findByUuid('entities', 'http://example.test/view/existing')],
            'findLatest' => ['/order by "created" desc, "id" desc limit 1/', static fn (JsonRecordStore $store): mixed => $store->findLatest('entities')],
            'delete' => ['/^delete from "idno_entities" where/', static fn (JsonRecordStore $store): mixed => $store->delete('entities', 'existing')],
            'deleteAll' => ['/^delete from "idno_entities"$/', static fn (JsonRecordStore $store): mixed => $store->deleteAll('entities')],
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
     * A failure of the one statement of save(), for a new record and for a replacement: thrown,
     * nothing of the save stored, the connection usable afterwards.
     */
    public function testAFailedSaveIsThrownAndStoresNothing(): void
    {
        $before = $this->rows();

        foreach (['a new record' => 'new', 'a replacement' => 'existing'] as $case => $id) {
            $this->failure->failOn('/^insert into "idno_entities" /');

            $thrown = self::thrown(fn () => $this->store->save('entities', ['_id' => $id, 'title' => 'After', 'tags' => ['c', 'd', 'e'], 'created' => 1_700_000_100]));

            $this->assertInstanceOf(DriverException::class, $thrown, $case);
            $this->assertStringEndsWith(FailingStatementMiddleware::MESSAGE, $thrown->getMessage(), $case);
            $this->assertFalse($this->connection->isTransactionActive(), $case);
            $this->assertSame($before, $this->rows(), $case . ': nothing of the save is stored');
        }

        // The connection is usable: the same save now succeeds.
        $this->assertSame('existing', $this->store->save('entities', ['_id' => 'existing', 'title' => 'After', 'created' => 1_700_000_100]));
        $this->assertSame('After', $this->store->findById('entities', 'existing')['title']);
    }

    /**
     * Reading the schema catalog is the first statement a new store sends: its failure is thrown
     * from every method, never taken for "no such collection".
     */
    public function testAFailureToReadTheSchemaIsThrown(): void
    {
        foreach (['save' => static fn (JsonRecordStore $store): mixed => $store->save('entities', ['_id' => 'x']), 'findById' => static fn (JsonRecordStore $store): mixed => $store->findById('entities', 'existing'), 'count' => static fn (JsonRecordStore $store): mixed => $store->count('entities', Criteria::all()), 'deleteAll' => static fn (JsonRecordStore $store): mixed => $store->deleteAll('entities')] as $method => $call) {
            $store = $this->newStore();
            $this->failure->failOn('/sqlite_master/');

            $thrown = self::thrown(fn () => $call($store));

            $this->assertInstanceOf(DriverException::class, $thrown, $method);
            $this->assertSame('Before', $store->findById('entities', 'existing')['title'] ?? null, $method . ': the next call reads the schema');
        }
    }

    /**
     * The rows of `idno_entities`.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative('select * from idno_entities order by id');
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
