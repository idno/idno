<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * A DBAL driver middleware that makes one statement fail: once armed with a pattern, the Nth
 * statement whose SQL matches it fails with a driver error that is not a refusal of the data (an
 * I/O error, as the driver would raise it: a PDOException wrapped in a driver exception). The
 * statements before and after it run normally. For tests.
 */
final class FailingStatementMiddleware implements Middleware
{
    public const MESSAGE = 'SQLSTATE[HY000]: General error: 10 disk I/O error';

    private ?string $pattern = null;

    private int $nth = 1;

    private int $matched = 0;

    /** @var list<string> the statements that failed */
    public array $failed = [];

    /**
     * Fails the $nth statement (counted from now) whose SQL matches the regular expression.
     */
    public function failOn(string $pattern, int $nth = 1): void
    {
        $this->pattern = $pattern;
        $this->nth = $nth;
        $this->matched = 0;
    }

    public function disarm(): void
    {
        $this->pattern = null;
    }

    /**
     * Throws when this statement is the one to fail.
     */
    public function check(string $sql): void
    {
        if ($this->pattern === null || preg_match($this->pattern, $sql) !== 1) {
            return;
        }
        if (++$this->matched !== $this->nth) {
            return;
        }
        $this->pattern = null;
        $this->failed[] = $sql;
        $pdo = new \PDOException(self::MESSAGE);
        $pdo->errorInfo = ['HY000', 10, 'disk I/O error'];

        throw new class (self::MESSAGE, 'HY000', 10, $pdo) extends AbstractException {
        };
    }

    public function wrap(Driver $driver): Driver
    {
        $middleware = $this;

        return new class ($driver, $middleware) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly FailingStatementMiddleware $middleware)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                return new class (parent::connect($params), $this->middleware) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly FailingStatementMiddleware $middleware)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->middleware->check($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        $this->middleware->check($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->middleware->check($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
