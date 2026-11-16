<?php

declare(strict_types=1);

namespace Idno\Storage\Platform;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * A DBAL driver middleware: when a connection's FIRST attempt to connect fails, every later attempt
 * on it fails with that same failure, without reaching the driver (no second connection attempt).
 * Once an attempt has succeeded it does nothing: a lost connection is reopened as before.
 *
 * Why: the schema gate (Idno\Upgrade\SchemaState) connects first, and an unreachable database opens
 * the gate so that the legacy application shows its database error page. DBAL retries a failed
 * connect on the next call, so without this a second attempt that succeeded (a server that was
 * refusing connections a moment before, "Too many connections") would let the legacy application
 * boot on a database the gate never checked: an empty one, a 1.x one, a copy in progress. With it,
 * "unreachable" is final for the request, and a host that times out is waited for once, not twice.
 *
 * Idno\Storage\BootConfigConnectionFactory adds one instance to each connection it builds (the
 * site's `database_connection`), after every other middleware. Not a container service.
 */
final class ConnectFailureIsFinal implements Middleware
{
    private ?Driver\Exception $failure = null;

    private bool $connected = false;

    public function wrap(Driver $driver): Driver
    {
        $memory = $this;

        return new class ($driver, $memory) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly ConnectFailureIsFinal $memory)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): DriverConnection
            {
                return $this->memory->connect(fn (): DriverConnection => parent::connect($params));
            }
        };
    }

    /**
     * @internal the wrapped driver's connect()
     *
     * @param \Closure(): DriverConnection $connect
     */
    public function connect(\Closure $connect): DriverConnection
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        try {
            $connection = $connect();
        } catch (Driver\Exception $e) {
            if (!$this->connected) {
                $this->failure = $e;
            }

            throw $e;
        }
        $this->connected = true;

        return $connection;
    }
}
