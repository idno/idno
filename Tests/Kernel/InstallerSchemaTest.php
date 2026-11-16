<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Doctrine\DBAL\Connection;
use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Core\Installer;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\SchemaCatalog;
use PHPUnit\Framework\TestCase;
use Tests\Storage\PrivateDatabase;

/**
 * The installers' Installer::installSchema() only checks that it can connect to the database: it
 * creates no table (the schema is made by the first page load or `bin/console idno:upgrade`). On an
 * empty private database on the rig's MySQL or MariaDB server; skipped on any other site's database.
 */
final class InstallerSchemaTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $config = Idno::container()->get(BootConfig::class)->all();
        if (ConnectionParameters::driverFamily($config) !== 'mysql') {
            $this->markTestSkipped('The installers install MySQL sites: this needs the rig\'s MySQL or MariaDB server.');
        }
        $this->connection = PrivateDatabase::mysql('installer', migrate: false);
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            PrivateDatabase::drop($this->connection);
            $this->connection = null;
        }
    }

    private static function installer(): Installer
    {
        return new class () extends Installer {
            public function run()
            {
            }

            public function check(string $host, string $dbname, ?string $user, ?string $pass): bool
            {
                return $this->installSchema($host, $dbname, $user, $pass);
            }
        };
    }

    public function testItConnectsAndCreatesNothing(): void
    {
        self::assertNotNull($this->connection);
        $config = Idno::site()->config();
        $host = $config->dbhost . (empty($config->dbport) ? '' : ';port=' . $config->dbport);

        $this->assertTrue(self::installer()->check($host, (string) $this->connection->getDatabase(), $config->dbuser, $config->dbpass));
        $this->assertSame([], (new SchemaCatalog($this->connection))->tables(), 'no table was created');
    }

    public function testADatabaseItCannotConnectToThrows(): void
    {
        $config = Idno::site()->config();
        $host = $config->dbhost . (empty($config->dbport) ? '' : ';port=' . $config->dbport);

        $this->expectException(\PDOException::class);
        self::installer()->check($host, 'idno_no_such_database_' . bin2hex(random_bytes(4)), $config->dbuser, $config->dbpass);
    }
}
