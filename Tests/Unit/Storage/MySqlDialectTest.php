<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Doctrine\DBAL\Driver\Exception as DriverError;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Idno\Storage\Platform\MySqlDialect;
use PHPUnit\Framework\TestCase;

/**
 * How the MySQL dialect reads driver errors, with exceptions built here (no database). The codes
 * are MySQL's; the exception classes are the ones DBAL's MySQL exception converter gives them.
 */
final class MySqlDialectTest extends TestCase
{
    /**
     * @param class-string<DriverException> $class
     */
    private static function error(int $code, string $class = DriverException::class): DriverException
    {
        $driverError = new class ('SQLSTATE[HY000]: error ' . $code, $code) extends \Exception implements DriverError {
            public function getSQLState(): ?string
            {
                return 'HY000';
            }
        };

        return new $class($driverError, null);
    }

    public function testRefusedDataIsAnAllowlist(): void
    {
        $dialect = new MySqlDialect();

        foreach ([
            'duplicate uuid' => self::error(1062, UniqueConstraintViolationException::class),
            'foreign key' => self::error(1452, ForeignKeyConstraintViolationException::class),
            'not null' => self::error(1048, NotNullConstraintViolationException::class),
            'incorrect string value' => self::error(1366),
            'data too long (an _id over 36 characters)' => self::error(1406),
            'incorrect datetime value' => self::error(1292),
            'deadlock' => self::error(1213, DeadlockException::class),
            'lock wait timeout' => self::error(1205, LockWaitTimeoutException::class),
        ] as $case => $error) {
            $this->assertTrue($dialect->isRefusedData($error), $case);
        }

        foreach ([
            'lost connection during query' => self::error(2013),
            'server shutdown in progress' => self::error(1053),
            'table full' => self::error(1114),
            'server has gone away' => self::error(2006, ConnectionLost::class),
            'missing table' => self::error(1146, TableNotFoundException::class),
            'syntax error' => self::error(1064, SyntaxErrorException::class),
            'unknown error' => self::error(9999),
        ] as $case => $error) {
            $this->assertFalse($dialect->isRefusedData($error), $case);
        }
    }

    public function testRejectedOperands(): void
    {
        $dialect = new MySqlDialect();

        // A parse error is the search term's only when the query holds a boolean-mode full-text predicate.
        $this->assertTrue($dialect->isRejectedOperand(self::error(1064, SyntaxErrorException::class), true));
        $this->assertFalse($dialect->isRejectedOperand(self::error(1064, SyntaxErrorException::class), false));
        // The store never sends a `created` operand that is not a timestamp, so MySQL's "Incorrect
        // TIMESTAMP value" (1525) is a broken query like any other.
        foreach ([2013, 2006, 1146, 1054, 1366, 1525] as $code) {
            $this->assertFalse($dialect->isRejectedOperand(self::error($code), true), (string) $code);
        }
    }

    public function testOnlyABooleanModeFullTextConditionParsesTheTerm(): void
    {
        $dialect = new MySqlDialect();

        $this->assertSame(['match (s) against (? in boolean mode)', ['abc@def.com'], true], $dialect->searchTextCondition('s', 'abc@def.com', true, false));
        $this->assertSame(['match (s) against (?)', ['abc@def.com'], false], $dialect->searchTextCondition('s', 'abc@def.com', false, false));
        $this->assertSame(['s like ?', ['%abc@def.com%'], false], $dialect->searchTextCondition('s', 'abc@def.com', true, true));
        $this->assertSame(['s like ?', ['%hello%'], false], $dialect->searchTextCondition('s', 'hello', true, false));
    }
}
