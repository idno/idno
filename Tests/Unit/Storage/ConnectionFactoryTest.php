<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Idno\Storage\ConnectionFactory;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\Platform\ConnectFailureIsFinal;
use Idno\Storage\Platform\SqliteConnectionSetup;
use PHPUnit\Framework\TestCase;

/**
 * The connection a legacy database configuration gives (the site's, and the MongoDB import's
 * target): ConnectionParameters' parameters and the site's middlewares, without connecting.
 */
final class ConnectionFactoryTest extends TestCase
{
    public function testASqliteConfigHasTheConnectionSetup(): void
    {
        $connection = ConnectionFactory::forConfig(['database' => 'sqlite', 'dbname' => 'data/site.sqlite'], [], '/srv/idno');

        $this->assertSame(['driver' => 'pdo_sqlite', 'path' => '/srv/idno/data/site.sqlite'], array_intersect_key($connection->getParams(), ['driver' => 1, 'path' => 1]));
        $middlewares = $connection->getConfiguration()->getMiddlewares();
        $this->assertCount(2, $middlewares);
        $this->assertInstanceOf(SqliteConnectionSetup::class, $middlewares[0]);
        $this->assertInstanceOf(ConnectFailureIsFinal::class, $middlewares[1], 'last, so that it sees every failure');
        $this->assertFalse($connection->isConnected());
    }

    public function testAMysqlConfigHasTheSitesParameters(): void
    {
        $config = ['database' => 'mysql', 'dbname' => 'idno', 'dbhost' => '127.0.0.1', 'dbport' => '9', 'dbuser' => 'user', 'dbpass' => 'secret'];
        $connection = ConnectionFactory::forConfig($config);

        $params = $connection->getParams();
        foreach (ConnectionParameters::fromLegacyConfig($config, []) as $key => $value) {
            $this->assertSame($value, $params[$key] ?? null, $key);
        }
        $middlewares = $connection->getConfiguration()->getMiddlewares();
        $this->assertCount(1, $middlewares);
        $this->assertInstanceOf(ConnectFailureIsFinal::class, $middlewares[0]);
        $this->assertFalse($connection->isConnected(), 'building the connection does not connect (port 9 is closed)');
    }

    public function testADatabaseThatIsNotSqlHasNoConnection(): void
    {
        $this->expectException(\LogicException::class);
        ConnectionFactory::forConfig(['database' => 'mongo']);
    }
}
