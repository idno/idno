<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/**
 * A DBAL driver middleware that records each prepared statement as it is executed: its SQL and the
 * values bound to its positional placeholders, in order. For tests that EXPLAIN the statements a
 * store sends.
 */
final class StatementRecorder implements Middleware
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    public array $statements = [];

    /**
     * The last recorded statement whose SQL matches the regular expression.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    public function last(string $pattern): array
    {
        foreach (array_reverse($this->statements) as $statement) {
            if (preg_match($pattern, $statement['sql']) === 1) {
                return $statement;
            }
        }

        throw new \LogicException('No statement matching ' . $pattern . ' was recorded.');
    }

    /**
     * @param list<mixed> $params
     */
    public function record(string $sql, array $params): void
    {
        $this->statements[] = ['sql' => $sql, 'params' => $params];
    }

    public function wrap(Driver $driver): Driver
    {
        $recorder = $this;

        return new class ($driver, $recorder) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly StatementRecorder $recorder)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                return new class (parent::connect($params), $this->recorder) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly StatementRecorder $recorder)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        return new class (parent::prepare($sql), $sql, $this->recorder) extends AbstractStatementMiddleware {
                            /** @var array<int, mixed> */
                            private array $params = [];

                            public function __construct(Statement $statement, private readonly string $sql, private readonly StatementRecorder $recorder)
                            {
                                parent::__construct($statement);
                            }

                            public function bindValue(int|string $param, mixed $value, ParameterType $type): void
                            {
                                $this->params[(int) $param] = $value;
                                parent::bindValue($param, $value, $type);
                            }

                            public function execute(): Result
                            {
                                ksort($this->params);
                                $this->recorder->record($this->sql, array_values($this->params));

                                return parent::execute();
                            }
                        };
                    }

                    public function query(string $sql): Result
                    {
                        $this->recorder->record($sql, []);

                        return parent::query($sql);
                    }
                };
            }
        };
    }
}
