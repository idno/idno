<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Test-environment only (config/services_test.php tags it `doctrine.middleware`): when the
 * environment variable IDNO_TEST_STATEMENT_LOG names a file, every statement the site's DBAL
 * connection sends (prepared, queried or executed) is appended to it, one line each (its SQL,
 * whitespace collapsed). Otherwise it does nothing. Statements sent on the native PDO (the PHP
 * session handler's) do not pass through DBAL and are not logged.
 */
final class StatementLog implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        $file = getenv('IDNO_TEST_STATEMENT_LOG');
        if (!is_string($file) || $file === '') {
            return $driver;
        }

        return new class ($driver, $file) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly string $file)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                return new class (parent::connect($params), $this->file) extends AbstractConnectionMiddleware {
                    public function __construct(DriverConnection $connection, private readonly string $file)
                    {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->log($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        $this->log($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        $this->log($sql);

                        return parent::exec($sql);
                    }

                    private function log(string $sql): void
                    {
                        file_put_contents($this->file, (string) preg_replace('/\s+/', ' ', trim($sql)) . "\n", FILE_APPEND | LOCK_EX);
                    }
                };
            }
        };
    }
}
