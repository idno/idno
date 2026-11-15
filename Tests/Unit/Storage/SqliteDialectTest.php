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

    public function testTheUpsertUpdatesOnAnyUniqueKeyFromTheInsertedValues(): void
    {
        $this->assertSame(
            ['insert into "entities" ("uuid", "_id", "contents") values (?, ?, ?) on conflict do update set "uuid" = excluded."uuid", "contents" = excluded."contents"', ['uuid', '_id', 'contents']],
            (new SqliteDialect())->upsert('entities', ['uuid', '_id', 'contents'], ['uuid', 'contents']),
        );
    }

    public function testEverySearchTermGoesToTheSearchFunction(): void
    {
        $dialect = new SqliteDialect();

        foreach ([[true, false], [false, false], [true, true]] as [$boolean, $bypass]) {
            $this->assertSame(['idno_search(srch."search", ?)', ['abc@def.com'], false], $dialect->searchCondition('srch."search"', 'abc@def.com', $boolean, $bypass));
            $this->assertSame(['idno_search(s, ?)', ['ab%c'], false], $dialect->searchCondition('s', 'ab%c', $boolean, $bypass));
        }
    }

    public function testCreatedOperandsAreCheckedAndNormalised(): void
    {
        $dialect = new SqliteDialect();

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
        $dialect = new SqliteDialect();

        $this->assertSame('"a""b"', $dialect->quoteIdentifier('a"b'));
        $this->assertSame('limit 10 offset 20', $dialect->limit(10, 20));
        $this->assertSame("select name from sqlite_master where type = 'table'", $dialect->tableNamesQuery());
        $this->assertSame('select 1', $dialect->tableNamesIgnoreCaseQuery());
    }

    public function testRefusedDataIsConstraintsAndLocks(): void
    {
        $dialect = new SqliteDialect();

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
