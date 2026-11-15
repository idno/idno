<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Idno\Storage\ConnectionParameters;
use PHPUnit\Framework\TestCase;

/**
 * The legacy database settings → DBAL connection parameters. The expected values are read from
 * Idno\Data\MySQL::buildConnectionString() / createPDOConnection() and
 * Idno\Legacy\LegacyServiceFactory::db().
 */
final class ConnectionParametersTest extends TestCase
{
    /** The PDO attributes createPDOConnection() sets. */
    private const LEGACY_OPTIONS = [
        \Pdo\Mysql::ATTR_LOCAL_INFILE => 1,
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    ];

    public function testMysqlNamesAreCaseInsensitive(): void
    {
        foreach (['MySQL', 'mysql', 'MYSQL', ' MySql ', 'beanstalk-mysql', 'Beanstalk-MySQL'] as $name) {
            $this->assertSame('mysql', ConnectionParameters::driverFamily(['database' => $name]), $name);
            $this->assertSame('pdo_mysql', ConnectionParameters::fromLegacyConfig(['database' => $name], [])['driver'], $name);
        }
    }

    public function testTheLegacyDefaultDatabaseIsMysql(): void
    {
        // Idno\Core\Config's defaults: database 'mysql', dbname 'idno'.
        $this->assertSame('mysql', ConnectionParameters::driverFamily([]));
        $params = ConnectionParameters::fromLegacyConfig([], []);
        $this->assertSame('pdo_mysql', $params['driver']);
        $this->assertSame('idno', $params['dbname']);
    }

    public function testBeanstalkUsesRdsVariables(): void
    {
        $server = [
            'RDS_HOSTNAME' => 'rds.example.test',
            'RDS_USERNAME' => 'rds-user',
            'RDS_PASSWORD' => 'rds-secret',
            'RDS_PORT' => '3307',
            'RDS_DB_NAME' => 'rds_db',
        ];
        $config = ['database' => 'beanstalk-mysql', 'dbhost' => 'ignored', 'dbuser' => 'ignored', 'dbpass' => 'ignored', 'dbport' => '1'];

        $params = ConnectionParameters::fromLegacyConfig($config + ['dbname' => 'from_config'], $server);
        $this->assertSame('rds.example.test', $params['host']);
        $this->assertSame('rds-user', $params['user']);
        $this->assertSame('rds-secret', $params['password']);
        $this->assertSame(3307, $params['port']);
        // dbname comes from the configuration when it is set...
        $this->assertSame('from_config', $params['dbname']);
        // ...and from RDS_DB_NAME when it is empty.
        $this->assertSame('rds_db', ConnectionParameters::fromLegacyConfig($config + ['dbname' => ''], $server)['dbname']);
        $this->assertSame(self::LEGACY_OPTIONS, $params['driverOptions']);
        $this->assertSame('utf8', $params['charset']);
    }

    public function testSqlite(): void
    {
        $this->assertSame('sqlite', ConnectionParameters::driverFamily(['database' => 'SQLite']));
        $this->assertSame(
            ['driver' => 'pdo_sqlite', 'path' => '/tmp/x.sqlite'],
            ConnectionParameters::fromLegacyConfig(['database' => 'sqlite', 'dbname' => '/tmp/x.sqlite'], []),
        );
        $this->assertSame(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            ConnectionParameters::fromLegacyConfig(['database' => 'sqlite', 'dbname' => ':memory:'], []),
        );
    }

    public function testMongo(): void
    {
        foreach (['mongo', 'mongodb', 'MongoDB'] as $name) {
            $this->assertSame('mongo', ConnectionParameters::driverFamily(['database' => $name]), $name);
            try {
                ConnectionParameters::fromLegacyConfig(['database' => $name], []);
                $this->fail('No DBAL parameters for ' . $name);
            } catch (\LogicException $e) {
                $this->assertStringContainsString('not an SQL database', $e->getMessage());
            }
        }
    }

    public function testUnknownDriverName(): void
    {
        $this->assertSame('other', ConnectionParameters::driverFamily(['database' => 'SomePluginBackend']));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"SomePluginBackend"');
        ConnectionParameters::fromLegacyConfig(['database' => 'SomePluginBackend'], []);
    }

    public function testCharsetAndOptionsMatchLegacy(): void
    {
        // 'mysql:host=db.example.test;dbname=idno_site;charset=utf8;port=3306', user, password,
        // [PDO::MYSQL_ATTR_LOCAL_INFILE => 1], then ATTR_ERRMODE => ERRMODE_EXCEPTION.
        $config = [
            'database' => 'MySQL',
            'dbhost' => 'db.example.test',
            'dbport' => '3306',
            'dbname' => 'idno_site',
            'dbuser' => 'idno',
            'dbpass' => 'p@ss;word',
            'url' => 'https://example.test/',
        ];

        $this->assertSame([
            'driver' => 'pdo_mysql',
            'host' => 'db.example.test',
            'port' => 3306,
            'dbname' => 'idno_site',
            'user' => 'idno',
            'password' => 'p@ss;word',
            'charset' => 'utf8',
            'driverOptions' => self::LEGACY_OPTIONS,
        ], ConnectionParameters::fromLegacyConfig($config, ['RDS_HOSTNAME' => 'not used for mysql']));
    }

    public function testThePortIsLeftOutWhenItIsEmpty(): void
    {
        // buildConnectionString() appends ';port=' only when dbport is not empty.
        foreach ([null, '', 0, '0'] as $port) {
            $params = ConnectionParameters::fromLegacyConfig(['database' => 'mysql', 'dbhost' => 'h', 'dbport' => $port], []);
            $this->assertArrayNotHasKey('port', $params, (string) json_encode($port));
        }
        $this->assertArrayNotHasKey('port', ConnectionParameters::fromLegacyConfig(['database' => 'mysql'], []));
    }

    public function testMissingCredentialsAreNull(): void
    {
        $params = ConnectionParameters::fromLegacyConfig(['database' => 'mysql'], []);

        $this->assertNull($params['host']);
        $this->assertNull($params['user']);
        $this->assertNull($params['password']);
    }
}
