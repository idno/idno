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

    private static function dialect(): SqliteDialect
    {
        return new SqliteDialect();
    }

    public function testTheMinimumSqliteVersion(): void
    {
        foreach (['3.38.0', '3.38.5', '3.45.1', '3.51.2', '4.0.0'] as $version) {
            SqliteDialect::checkVersion($version);
            SqliteDialect::checkVersion($version, true);
            $this->addToAssertionCount(1);
        }
        foreach (['3.37.2', '3.24.0', '3.8.11', '2.8.17'] as $version) {
            try {
                SqliteDialect::checkVersion($version);
                $this->fail($version . ' should be refused');
            } catch (\LogicException $e) {
                $this->assertSame('SQLite storage needs SQLite 3.38.0 or later with the JSON functions; this PHP uses SQLite ' . $version . '.', $e->getMessage());
            }
        }
    }

    public function testASqliteWithoutTheJsonFunctionsIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SQLite storage needs SQLite 3.38.0 or later with the JSON functions; this PHP uses SQLite 3.45.1 without them.');

        SqliteDialect::checkVersion('3.45.1', false);
    }

    public function testTheConnectionSetupChecksTheLibraryItRunsOn(): void
    {
        // This PHP's SQLite passes (the test suite needs 3.38 or later); the check is the one above.
        $pdo = new \Pdo\Sqlite('sqlite::memory:');
        SqliteDialect::setUpConnection($pdo);

        $this->assertSame(1, (int) $pdo->query("select json_valid('{}')")->fetchColumn());
        $this->assertSame(5000, (int) $pdo->query('pragma busy_timeout')->fetchColumn());
        $this->assertSame(1, (int) $pdo->query('pragma foreign_keys')->fetchColumn());
        // Nothing of its own is registered: the schema needs built-in collations and functions only.
        foreach (["select 'a' = 'A' collate idno_ci", "select idno_search('a', 'a')"] as $sql) {
            try {
                $pdo->query($sql);
                $this->fail($sql . ' should fail: nothing custom is registered');
            } catch (\PDOException $e) {
                $this->assertMatchesRegularExpression('/no such (collation sequence|function)/', $e->getMessage());
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

    /**
     * Built-in functions only, whatever the site's search settings: a term of at most 5 bytes is one
     * `like` substring (wildcards and `\` escapes as in SQL), a longer one matches any of its words
     * with instr(), ignoring ASCII case; a long term with no word matches nothing.
     */
    public function testSearchTermsUseBuiltInFunctionsOnly(): void
    {
        $dialect = self::dialect();

        foreach ([[true, false], [false, false], [true, true]] as [$boolean, $bypass]) {
            $this->assertSame(['s like ? escape \'\\\'', ['%ab%c%'], false], $dialect->searchTextCondition('s', 'ab%c', $boolean, $bypass));
            $this->assertSame(
                ['(instr(lower(t."search"), lower(?)) > 0 or instr(lower(t."search"), lower(?)) > 0)', ['abc@def.com', 'walrus'], false],
                $dialect->searchTextCondition('t."search"', ' abc@def.com  walrus ', $boolean, $bypass),
            );
            $this->assertSame(['(1 = 0)', [], false], $dialect->searchTextCondition('s', '       ', $boolean, $bypass));
        }
    }

    public function testIdentifiersAndPaging(): void
    {
        $dialect = self::dialect();

        $this->assertSame('"a""b"', $dialect->quoteIdentifier('a"b'));
        $this->assertSame('limit 10 offset 20', $dialect->limit(10, 20));
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
            'read-only database' => self::error('8 attempt to write a readonly database', ReadOnlyException::class),
            'syntax error' => self::error('1 near "x": syntax error', SyntaxErrorException::class),
            'disk full' => self::error('13 database or disk is full'),
        ] as $case => $error) {
            $this->assertFalse($dialect->isRefusedData($error), $case);
            $this->assertFalse($dialect->isRejectedOperand($error, false), $case);
        }
    }
}
