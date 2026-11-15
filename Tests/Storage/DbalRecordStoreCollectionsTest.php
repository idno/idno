<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Idno\Storage\DbalRecordStore;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\Platform\Dialect;
use Idno\Storage\Platform\MySqlDialect;
use PHPUnit\Framework\TestCase;

/**
 * How DbalRecordStore decides that a collection exists, on an in-memory SQLite database (no MySQL
 * server needed, but it opens a database, so it is not in the unit tier; skipped without
 * pdo_sqlite). Only reads are used: the statements are MySQL's, the table listing and the case
 * rule come from a test dialect. Independent of the site's database.
 */
final class DbalRecordStoreCollectionsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not available.');
        }
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['entities', 'entities_metadata', 'entities_search', 'session', 'half', 'half_search'] as $table) {
            $this->connection->executeStatement('create table ' . $table . ' (_id text, uuid text, created text, contents text)');
        }
        $this->connection->executeStatement("insert into entities values ('a', 'u', '2023-06-14 00:00:00', '{\"_id\":\"a\"}')");
    }

    private function store(bool $serverIgnoresCase): DbalRecordStore
    {
        $dialect = new class ($serverIgnoresCase) implements Dialect {
            private MySqlDialect $mysql;

            public function __construct(private readonly bool $ignoreCase)
            {
                $this->mysql = new MySqlDialect();
            }

            public function quoteIdentifier(string $name): string
            {
                return $this->mysql->quoteIdentifier($name);
            }

            public function upsert(string $table, array $insertColumns, array $updateColumns): array
            {
                return $this->mysql->upsert($table, $insertColumns, $updateColumns);
            }

            public function searchCondition(string $column, string $term, bool $booleanSearch, bool $bypassFulltext): array
            {
                return $this->mysql->searchCondition($column, $term, $booleanSearch, $bypassFulltext);
            }

            public function tableNamesQuery(): string
            {
                return "select name from sqlite_master where type = 'table'";
            }

            public function tableNamesIgnoreCaseQuery(): string
            {
                return 'select ' . ($this->ignoreCase ? 1 : 0);
            }

            public function limit(int $limit, int $offset): string
            {
                return 'limit ' . $limit . ' offset ' . $offset;
            }

            public function isRejectedOperand(DriverException $exception, bool $parsedSearchTerm): bool
            {
                return false;
            }

            public function isRefusedData(DriverException $exception): bool
            {
                return false;
            }
        };

        return new DbalRecordStore($this->connection, $dialect, new FixedRecordDefaults(), static fn (string $s): string => $s, true, false);
    }

    public function testACollectionIsThreeTables(): void
    {
        $store = $this->store(false);

        $this->assertSame(['_id' => 'a'], $store->findById('entities', 'a'));
        $this->assertSame(['_id' => 'a'], $store->findById('enti-ties', 'a'), 'the name is sanitised');
        $this->assertNull($store->findById('session', 'a'), 'a table without _metadata and _search');
        $this->assertNull($store->findById('half', 'a'), 'a table without _metadata');
        $this->assertFalse($store->delete('nosuchtable', 'a'));
    }

    public function testTableNamesMatchWithoutCaseOnlyWhenTheServerIgnoresCase(): void
    {
        $this->assertNull($this->store(false)->findById('ENTITIES', 'a'), 'case-sensitive server: no such collection');
        $this->assertSame(['_id' => 'a'], $this->store(true)->findById('ENTITIES', 'a'), 'lower_case_table_names 1 or 2: the same collection');
        $this->assertSame(['_id' => 'a'], $this->store(true)->findById('Entities', 'a'));
    }

    public function testATableCreatedLaterIsFound(): void
    {
        $store = $this->store(false);
        $this->assertNull($store->findLatest('reader'));

        foreach (['reader', 'reader_metadata', 'reader_search'] as $table) {
            $this->connection->executeStatement('create table ' . $table . ' (_id text, uuid text, created text, contents text)');
        }
        $this->connection->executeStatement("insert into reader values ('r', 'u', '2023-06-14 00:00:00', '{\"_id\":\"r\"}')");

        $this->assertSame(['_id' => 'r'], $store->findLatest('reader'));
    }
}
