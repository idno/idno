<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Doctrine\DBAL\Driver\Exception as DriverError;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\ReadOnlyException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Idno\Storage\Platform\MySqlDialect;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\Platform\SqliteDialect;
use PHPUnit\Framework\TestCase;

/**
 * The SQL the SQLite dialect writes and how it reads driver errors (exceptions built here, of the
 * classes DBAL's SQLite exception converter gives; no database).
 */
final class SqliteDialectTest extends TestCase
{
    /**
     * @param class-string<DriverException> $class
     */
    private static function error(string $message, string $class = DriverException::class): DriverException
    {
        $driverError = new class ('SQLSTATE[HY000]: General error: ' . $message, 1) extends \Exception implements DriverError {
            public function getSQLState(): ?string
            {
                return 'HY000';
            }
        };

        return new $class($driverError, null);
    }

    /**
     * A dialect on a connection that is never used (for everything but the upsert).
     */
    private static function dialect(): SqliteDialect
    {
        return new SqliteDialect(SqliteTestDatabase::connection());
    }

    public function testTheUpsertNamesItsConflictTargetFromTheTablesIndexes(): void
    {
        $dialect = new SqliteDialect(SqliteTestDatabase::withSchema());
        $set = ' do update set "uuid" = excluded."uuid", "contents" = excluded."contents"';

        // A unique index on _id alone: entities and reader.
        foreach (['entities', 'reader'] as $table) {
            $this->assertSame(
                ['insert into "' . $table . '" ("uuid", "_id", "contents") values (?, ?, ?) on conflict ("_id")' . $set, ['uuid', '_id', 'contents']],
                $dialect->upsert($table, ['uuid', '_id', 'contents'], ['uuid', 'contents']),
            );
        }
        // _id is not unique: the primary key, uuid.
        foreach (['site', 'config'] as $table) {
            $this->assertSame(
                ['insert into "' . $table . '" ("uuid", "_id", "contents") values (?, ?, ?) on conflict ("uuid")' . $set, ['uuid', '_id', 'contents']],
                $dialect->upsert($table, ['uuid', '_id', 'contents'], ['uuid', 'contents']),
            );
        }
        // The search tables: their primary key is _id.
        foreach (['entities_search', 'site_search', 'config_search', 'reader_search'] as $table) {
            $this->assertSame(
                ['insert into "' . $table . '" ("_id", "search") values (?, ?) on conflict ("_id") do update set "search" = excluded."search"', ['_id', 'search']],
                $dialect->upsert($table, ['_id', 'search'], ['search']),
            );
        }
    }

    public function testTheConflictTargetIsReadOncePerTable(): void
    {
        $connection = SqliteTestDatabase::withSchema();
        $dialect = new SqliteDialect($connection);
        $first = $dialect->upsert('entities', ['uuid', '_id'], ['uuid']);

        // Were the indexes read again, the target would now be the primary key.
        $connection->executeStatement('drop index entities__id');
        $this->assertSame($first, $dialect->upsert('entities', ['uuid', '_id'], ['uuid']));
        $this->assertStringContainsString('on conflict ("_id")', $dialect->upsert('ENTITIES', ['uuid', '_id'], ['uuid'])[0], 'cached by the name SQLite resolves');
        $this->assertStringContainsString('on conflict ("uuid")', (new SqliteDialect($connection))->upsert('entities', ['uuid', '_id'], ['uuid'])[0]);
    }

    public function testATableWithoutAUniqueKeyCannotBeUpserted(): void
    {
        $dialect = new SqliteDialect(SqliteTestDatabase::withSchema());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('entities_metadata');
        $dialect->upsert('entities_metadata', ['_id', 'name', 'value'], ['value']);
    }

    public function testTheMinimumSqliteVersion(): void
    {
        foreach (['3.24.0', '3.24.1', '3.35.5', '3.51.2', '4.0.0'] as $version) {
            SqliteDialect::checkVersion($version);
            $this->addToAssertionCount(1);
        }
        foreach (['3.23.1', '3.8.11', '2.8.17'] as $version) {
            try {
                SqliteDialect::checkVersion($version);
                $this->fail($version . ' should be refused');
            } catch (\LogicException $e) {
                $this->assertSame('SQLite storage needs SQLite 3.24.0 or later; this PHP uses SQLite ' . $version . '.', $e->getMessage());
            }
        }
    }

    public function testTheConnectionSetupRefusesAConnectionThatIsNotPdoSqlite(): void
    {
        $native = $this->createMock(\Doctrine\DBAL\Driver\Connection::class);
        $native->method('getNativeConnection')->willReturn(new \stdClass());
        $driver = $this->createMock(\Doctrine\DBAL\Driver::class);
        $driver->method('connect')->willReturn($native);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('pdo_sqlite driver, but the connection is a stdClass');
        (new SqliteConnectionSetup())->wrap($driver)->connect([]);
    }

    public function testEverySearchTermGoesToTheSearchFunction(): void
    {
        $dialect = self::dialect();

        foreach ([[true, false], [false, false], [true, true]] as [$boolean, $bypass]) {
            $this->assertSame(['idno_search(srch."search", ?)', ['abc@def.com'], false], $dialect->searchCondition('srch."search"', 'abc@def.com', $boolean, $bypass));
            $this->assertSame(['idno_search(s, ?)', ['ab%c'], false], $dialect->searchCondition('s', 'ab%c', $boolean, $bypass));
        }
    }

    public function testCreatedOperandsAreCheckedAndNormalised(): void
    {
        $dialect = self::dialect();

        $this->assertSame('2023-06-04 01:02:03', $dialect->columnValue('created', '2023-6-4 1:2:3'));
        $this->assertSame('2023-06-14 00:00:00', $dialect->columnValue('created', '2023-06-14'));
        $this->assertSame('2023-06-14 00:00:00.5', $dialect->columnValue('created', '2023-06-14T00:00:00.500'));
        foreach (['1686700100', '', '1', '2023-02-30 00:00:00', '24:00:00'] as $rejected) {
            $this->assertNull($dialect->columnValue('created', $rejected), $rejected);
        }
        // Every other column takes any value; MySQL's dialect never changes a value.
        $this->assertSame('1686700100', $dialect->columnValue('owner', '1686700100'));
        $this->assertSame('1686700100', (new MySqlDialect())->columnValue('created', '1686700100'));
    }

    public function testIdentifiersPagingAndTables(): void
    {
        $dialect = self::dialect();

        $this->assertSame('"a""b"', $dialect->quoteIdentifier('a"b'));
        $this->assertSame('limit 10 offset 20', $dialect->limit(10, 20));
        $this->assertSame("select name from sqlite_master where type = 'table'", $dialect->tableNamesQuery());
        $this->assertSame('select 1', $dialect->tableNamesIgnoreCaseQuery());
    }

    public function testRefusedDataIsConstraintsAndLocks(): void
    {
        $dialect = self::dialect();

        foreach ([
            'duplicate uuid' => self::error('19 UNIQUE constraint failed: entities.uuid', UniqueConstraintViolationException::class),
            'foreign key (declared or trigger)' => self::error('19 FOREIGN KEY constraint failed', ForeignKeyConstraintViolationException::class),
            'not null' => self::error('19 NOT NULL constraint failed: entities.owner', NotNullConstraintViolationException::class),
            'busy timeout' => self::error('5 database is locked', LockWaitTimeoutException::class),
        ] as $case => $error) {
            $this->assertTrue($dialect->isRefusedData($error), $case);
            $this->assertFalse($dialect->isRejectedOperand($error, true), $case);
        }

        foreach ([
            'missing table' => self::error('1 no such table: entities', TableNotFoundException::class),
            'no connection setup' => self::error('1 no such collation sequence: idno_ci'),
            'read-only database' => self::error('8 attempt to write a readonly database', ReadOnlyException::class),
            'syntax error' => self::error('1 near "x": syntax error', SyntaxErrorException::class),
            'disk full' => self::error('13 database or disk is full'),
        ] as $case => $error) {
            $this->assertFalse($dialect->isRefusedData($error), $case);
            $this->assertFalse($dialect->isRejectedOperand($error, false), $case);
        }
    }
}
