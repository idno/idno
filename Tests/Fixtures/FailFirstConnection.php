<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Test-environment only (config/services_test.php tags it `doctrine.middleware`): when the
 * environment variable IDNO_TEST_FAIL_FIRST_CONNECTION is `1`, the first attempt to connect fails
 * (as a server refusing a connection, "Too many connections", would) and later attempts reach the
 * driver. Otherwise it does nothing. `attempts` counts every attempt that reached it.
 */
final class FailFirstConnection implements Middleware
{
    public int $attempts = 0;

    public function __construct(private readonly ?bool $enabled = null)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $middleware = $this;

        return new class ($driver, $middleware) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly FailFirstConnection $middleware)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                if ($this->middleware->failsNext()) {
                    throw new class ('SQLSTATE[08004] [1040] Too many connections (a test middleware)') extends \Exception implements Driver\Exception {
                        public function getSQLState(): ?string
                        {
                            return '08004';
                        }
                    };
                }

                return parent::connect($params);
            }
        };
    }

    /**
     * @internal
     */
    public function failsNext(): bool
    {
        ++$this->attempts;

        return ($this->enabled ?? getenv('IDNO_TEST_FAIL_FIRST_CONNECTION') === '1') && $this->attempts === 1;
    }
}
