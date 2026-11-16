<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Idno\Storage\Platform\ConnectFailureIsFinal;
use Idno\Storage\Platform\SqliteConnectionSetup;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\FailFirstConnection;

/**
 * Once the first attempt to connect has failed, every later attempt on the same connection fails
 * with that same failure and never reaches the driver: the schema gate's view of an unreachable
 * database is final for the request, so the legacy application cannot boot on a database the gate
 * never checked (a second attempt that succeeds). A connection that has once succeeded reconnects
 * as before.
 */
final class ConnectFailureIsFinalTest extends TestCase
{
    public function testAFailedFirstConnectionIsNeverRetried(): void
    {
        $failing = new FailFirstConnection(true);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([$failing, new SqliteConnectionSetup(), new ConnectFailureIsFinal()]));

        $first = null;
        try {
            $connection->getServerVersion();
            self::fail('The first attempt fails.');
        } catch (\Doctrine\DBAL\Exception $e) {
            $first = $e->getMessage();
        }
        foreach ([1, 2] as $again) {
            try {
                $connection->getNativeConnection();
                self::fail('A later attempt fails too: ' . $again);
            } catch (\Doctrine\DBAL\Exception $e) {
                self::assertSame($first, $e->getMessage());
            }
        }
        self::assertSame(1, $failing->attempts, 'one attempt reached the driver');
    }

    public function testAConnectionThatSucceededReconnects(): void
    {
        $counting = new FailFirstConnection(false);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([$counting, new SqliteConnectionSetup(), new ConnectFailureIsFinal()]));

        self::assertSame(1, (int) $connection->fetchOne('select 1'));
        $connection->close();
        self::assertSame(1, (int) $connection->fetchOne('select 1'));
        self::assertSame(2, $counting->attempts);
    }
}
