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

    /**
     * The web installer is open to anyone who reaches an uninstalled site, so it must not tell a visitor whether a host
     * answers, whether a user exists or whether a database is there: every failure to connect is one fixed sentence on the
     * page, and the reason goes to the server's log.
     */
    public function testTheWebInstallerSaysTheSameWhateverTheConnectionFailureIs(): void
    {
        $root = sys_get_temp_dir() . '/idno-install-probe-' . bin2hex(random_bytes(6));
        foreach (['configuration', 'data', 'Uploads', 'warmup/webserver-configs'] as $directory) {
            mkdir($root . '/' . $directory, 0755, true);
        }
        copy(dirname(__DIR__, 2) . '/warmup/webserver-configs/config.ini.template', $root . '/warmup/webserver-configs/config.ini.template');
        $cookie = str_repeat('ab', 32);
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<array{level: mixed, message: string}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };
        $installer = new \Idno\Install\WebInstaller(new InstallPaths($root), '', $logger);
        $config = Idno::site()->config();
        $pages = [];
        try {
            foreach ([
                'a wrong password' => ['mysql_name' => (string) $this->connection?->getDatabase(), 'mysql_user' => (string) $config->dbuser, 'mysql_pass' => 'not-the-password-' . bin2hex(random_bytes(4)), 'mysql_host' => self::host()],
                'an unknown database' => ['mysql_name' => 'no_such_database_' . bin2hex(random_bytes(4)), 'mysql_user' => (string) $config->dbuser, 'mysql_pass' => (string) $config->dbpass, 'mysql_host' => self::host()],
                'a host that does not answer' => ['mysql_name' => 'idno', 'mysql_user' => 'u', 'mysql_pass' => 'p', 'mysql_host' => '127.0.0.1:9'],
            ] as $case => $fields) {
                $request = \Symfony\Component\HttpFoundation\Request::create('https://example.com/', 'POST', $fields + ['step' => 'database', 'title' => 'Ada', 'domain' => 'example.com', 'database' => 'mysql', 'token' => \Idno\Install\WebInstaller::fieldFor($cookie)], [\Idno\Install\WebInstaller::COOKIE => $cookie]);
                $html = (string) $installer->handle($request)->getContent();
                $this->assertSame(1, preg_match('/<div class="errors" role="alert"><p>[^<]*<\/p><ul>(.*?)<\/ul>/', $html, $match), $case . ': ' . substr($html, 0, 300));
                $pages[$case] = $match[1];
                $this->assertFileDoesNotExist($root . '/configuration/config.ini', $case);
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }

        $this->assertCount(1, array_unique($pages), 'one sentence for every failure: ' . json_encode($pages));
        $this->assertCount(3, array_filter($logger->records, static fn (array $r): bool => str_contains($r['message'], 'could not connect')), 'each reason is logged');
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
