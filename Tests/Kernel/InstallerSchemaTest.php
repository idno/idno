<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Doctrine\DBAL\Connection;
use Idno\Config\BootConfig;
use Idno\Core\Idno;
use Idno\Exceptions\ConfigurationException;
use Idno\Install\DatabaseChoice;
use Idno\Install\DatabaseFinding;
use Idno\Install\InstallPaths;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use PHPUnit\Framework\TestCase;
use Tests\Storage\PrivateDatabase;

/**
 * The installers' MySQL and MariaDB side, Idno\Install\DatabaseChoice: params() only checks that it
 * can connect to the database and creates no table (the schema is made by the first page load or
 * `bin/console idno:upgrade`), and inspect() says what the database holds. On an empty private
 * database on the rig's MySQL or MariaDB server; skipped on any other site's database.
 */
final class InstallerSchemaTest extends TestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        $config = Idno::container()->get(BootConfig::class)->all();
        if (ConnectionParameters::driverFamily($config) !== 'mysql') {
            $this->markTestSkipped('This checks the installers\' MySQL path: it needs the rig\'s MySQL or MariaDB server.');
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

    private static function choice(): DatabaseChoice
    {
        return new DatabaseChoice(new InstallPaths((string) Idno::container()->getParameter('kernel.project_dir')));
    }

    /**
     * The server as a person types it: the host, with `:port` when the site's configuration has one.
     */
    private static function host(): string
    {
        $config = Idno::site()->config();

        return $config->dbhost . (empty($config->dbport) ? '' : ':' . $config->dbport);
    }

    /**
     * @return array<string, string>
     */
    private function input(?string $name = null): array
    {
        self::assertNotNull($this->connection);
        $config = Idno::site()->config();

        return ['name' => $name ?? (string) $this->connection->getDatabase(), 'user' => (string) $config->dbuser, 'pass' => (string) $config->dbpass, 'host' => self::host()];
    }

    public function testItConnectsAndCreatesNothing(): void
    {
        self::assertNotNull($this->connection);
        $config = Idno::site()->config();
        $name = (string) $this->connection->getDatabase();
        $expected = ['database' => 'MySQL', 'dbname' => $name, 'dbuser' => (string) $config->dbuser, 'dbpass' => (string) $config->dbpass, 'dbhost' => (string) $config->dbhost];
        if (!empty($config->dbport)) {
            $expected['dbport'] = (string) $config->dbport;
        }

        // The values written for MySQL, whatever the type was called.
        foreach (['mysql', 'MySQL', 'mariadb', ''] as $type) {
            $this->assertSame($expected, self::choice()->params($type, $this->input()));
        }
        $this->assertSame([], (new SchemaCatalog($this->connection))->tables(), 'no table was created');
    }

    public function testAnEmptyDatabaseIsEmptyAndAMigratedOneHoldsASite(): void
    {
        self::assertNotNull($this->connection);
        $choice = self::choice();
        $params = $choice->params('mysql', $this->input());

        $this->assertSame(DatabaseFinding::EMPTY, $choice->inspect($params)->kind);
        $this->assertSame([], (new SchemaCatalog($this->connection))->tables(), 'looking creates no table');

        (new SchemaMigrator($this->connection))->migrate();
        $finding = $choice->inspect($params);
        $this->assertSame(DatabaseFinding::IDNO, $finding->kind);
        $this->assertTrue($finding->holdsASite());
    }

    public function testAnotherApplicationsTablesDoNotMakeItAnIdnoSite(): void
    {
        self::assertNotNull($this->connection);
        $this->connection->executeStatement('create table wp_posts (id int primary key)');
        $choice = self::choice();

        $this->assertSame(DatabaseFinding::EMPTY, $choice->inspect($choice->params('mysql', $this->input()))->kind);
    }

    public function testADatabaseItCannotConnectToIsRefusedWithAFixedSentence(): void
    {
        $config = Idno::site()->config();
        try {
            self::choice()->params('mysql', $this->input('idno_no_such_database_' . bin2hex(random_bytes(4))));
            $this->fail('A database that does not exist was accepted.');
        } catch (ConfigurationException $e) {
            // The server says "unknown database" to a user who may see every database, "access denied" to one who may not.
            $this->assertMatchesRegularExpression('/There is no database with that name|not allowed to use that database|could not sign in/', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
            if ((string) $config->dbpass !== '') {
                $this->assertStringNotContainsString((string) $config->dbpass, $e->getMessage());
            }
            $this->assertStringNotContainsString('SQLSTATE', $e->getMessage(), 'the driver\'s own message is never shown');
        }

        try {
            self::choice()->params('mysql', ['name' => 'idno', 'user' => 'u', 'pass' => 'p', 'host' => '127.0.0.1:9']);
            $this->fail('A server that is not there was accepted.');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('could not reach the database server', $e->getMessage());
        }
    }

    public function testTheNameAndTheServerAreNeeded(): void
    {
        foreach ([['name' => '', 'host' => 'localhost'], ['name' => 'idno', 'host' => '']] as $input) {
            try {
                self::choice()->params('mysql', $input + ['user' => 'u', 'pass' => 'p']);
                $this->fail('Accepted without a name or a server.');
            } catch (ConfigurationException $e) {
                $this->assertStringContainsString('the name of the database and the name of the server', $e->getMessage());
            }
        }
    }
}
