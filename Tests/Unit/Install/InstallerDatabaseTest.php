<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use Idno\Core\Installer;
use Idno\Exceptions\ConfigurationException;
use Idno\Storage\ConnectionParameters;
use PHPUnit\Framework\TestCase;

/**
 * What both installers do with the choice of database: Installer::databaseParams() checks it and gives
 * the values the configuration file is built from. It needs nothing but PHP: the installer's root is a
 * temporary directory holding a copy of the configuration template, and SQLite files live in it.
 */
final class InstallerDatabaseTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is needed.');
        }
        $this->root = sys_get_temp_dir() . '/idno-installer-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/warmup/webserver-configs', 0755, true);
        copy(dirname(__DIR__, 3) . '/warmup/webserver-configs/config.ini.template', $this->root . '/warmup/webserver-configs/config.ini.template');
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            chmod($path, 0755);
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    private function installer(bool $sqliteLoaded = true): InstallerUnderTest
    {
        return new InstallerUnderTest($this->root, $sqliteLoaded);
    }

    public function testSqliteCreatesTheDatabaseFileAndReturnsItsAbsolutePath(): void
    {
        $params = $this->installer()->params('sqlite', ['path' => 'data/site.sqlite']);

        self::assertFileExists($this->root . '/data/site.sqlite');
        self::assertSame($this->root . '/data/site.sqlite', $params['dbname']);
        self::assertSame('sqlite', $params['database']);
        self::assertSame('', $params['dbuser']);
        self::assertSame('', $params['dbpass']);
        self::assertSame('', $params['dbhost']);
    }

    public function testTheDefaultSqlitePathIsDataIdnoSqlite(): void
    {
        foreach ([[], ['path' => ''], ['path' => '  ']] as $input) {
            $params = $this->installer()->params('sqlite', $input);
            self::assertSame($this->root . '/data/idno.sqlite', $params['dbname']);
        }
        self::assertFileExists($this->root . '/data/idno.sqlite');
        self::assertSame('/data/idno.sqlite', substr($this->installer()->defaultSqlitePath(), strlen($this->root)));
    }

    public function testAPathWithSpacesIsInstalled(): void
    {
        $path = $this->root . '/my data/idno site.sqlite';
        $params = $this->installer()->params('sqlite', ['path' => $path]);

        self::assertSame($path, $params['dbname']);
        self::assertFileExists($path);

        $config = parse_ini_string($this->installer()->config($params), false, INI_SCANNER_RAW);
        self::assertIsArray($config);
        self::assertSame($path, trim((string) $config['dbname'], "'"));
    }

    public function testADirectoryIsCreatedWithMode0755(): void
    {
        $this->installer()->params('sqlite', ['path' => 'a/b/idno.sqlite']);

        self::assertDirectoryExists($this->root . '/a/b');
        self::assertSame('0755', substr(sprintf('%o', fileperms($this->root . '/a/b')), -4));
    }

    public function testAnUnwritableDirectoryIsRefusedWithItsPath(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write to any directory.');
        }
        $dir = $this->root . '/readonly';
        mkdir($dir, 0555);

        try {
            $this->installer()->params('sqlite', ['path' => $dir . '/idno.sqlite']);
            self::fail('A directory that cannot be written to was accepted.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString($dir, $e->getMessage());
        } finally {
            chmod($dir, 0755);
        }
        self::assertFileDoesNotExist($dir . '/idno.sqlite');
    }

    public function testAnExistingFileThatIsNotADatabaseIsRefused(): void
    {
        $path = $this->root . '/notes.sqlite';
        $bytes = "This is a text file, not an SQLite database.\n" . str_repeat('x', 5000);
        file_put_contents($path, $bytes);

        try {
            $this->installer()->params('sqlite', ['path' => $path]);
            self::fail('A file that is not a database was accepted.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString($path, $e->getMessage());
        }

        self::assertSame($bytes, file_get_contents($path));
        self::assertSame([], glob($path . '-*'), 'no journal file was left beside it');
    }

    public function testAnEmptyExistingFileIsAValidNewDatabase(): void
    {
        $path = $this->root . '/empty.sqlite';
        touch($path);

        self::assertSame($path, $this->installer()->params('sqlite', ['path' => $path])['dbname']);
    }

    public function testAPathThatCannotBeWrittenInAConfigurationFileIsRefused(): void
    {
        foreach (["data/it's.sqlite", "data/new\nline.sqlite", "data/nul\0.sqlite"] as $path) {
            try {
                $this->installer()->params('sqlite', ['path' => $path]);
                self::fail('Accepted ' . json_encode($path));
            } catch (ConfigurationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertDirectoryDoesNotExist($this->root . '/data');
    }

    public function testSqliteWithoutThePdoModuleIsRefused(): void
    {
        try {
            $this->installer(false)->params('sqlite', []);
            self::fail('SQLite was accepted without pdo_sqlite.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('pdo_sqlite', $e->getMessage());
        }
        self::assertDirectoryDoesNotExist($this->root . '/data');
    }

    public function testTheWrittenConfigurationIsReadAsSqlite(): void
    {
        $installer = $this->installer();
        $text = $installer->config($installer->params('sqlite', ['path' => 'data/idno.sqlite']));

        $config = parse_ini_string($text);
        self::assertIsArray($config);
        self::assertSame('sqlite', $config['database'], 'exactly sqlite: Sqlite3 means MySQL');
        self::assertSame('sqlite', ConnectionParameters::driverFamily($config));
        self::assertSame($this->root . '/data/idno.sqlite', $config['dbname']);
        self::assertSame(
            ['driver' => 'pdo_sqlite', 'path' => $this->root . '/data/idno.sqlite'],
            ConnectionParameters::fromLegacyConfig($config, [], $this->root),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function databaseNames(): array
    {
        return [
            'sqlite' => ['sqlite', 'sqlite'],
            'SQLite' => ['SQLite', 'sqlite'],
            'sqlite3' => ['sqlite3', 'sqlite'],
            'Sqlite3' => ['Sqlite3', 'sqlite'],
            'spaces' => ['  SQLite ', 'sqlite'],
            'mysql' => ['mysql', 'mysql'],
            'MySQL' => ['MySQL', 'mysql'],
            'mariadb' => ['mariadb', 'mysql'],
            'empty' => ['', 'mysql'],
            'mongo' => ['mongo', 'mysql'],
            'nonsense' => ['sqlite; drop table', 'mysql'],
        ];
    }

    /**
     * @dataProvider databaseNames
     */
    public function testTheDatabaseTypeIsReadFromWhatTheUserTyped(string $typed, string $expected): void
    {
        self::assertSame($expected, InstallerUnderTest::type($typed));
    }

    public function testSqlite3AndSQLiteAreReadAsSqlite(): void
    {
        foreach (['SQLite', 'sqlite3', ' Sqlite3 '] as $typed) {
            self::assertSame('sqlite', $this->installer()->params($typed, ['path' => 'data/idno.sqlite'])['database']);
        }
    }

    public function testAnythingElseIsMysql(): void
    {
        foreach (['postgres', 'mongo', 'Oracle', ''] as $typed) {
            self::assertSame('mysql', InstallerUnderTest::type($typed));
        }
    }

    public function testAtLeastOneDatabaseModuleIsRequired(): void
    {
        self::assertNotContains('pdo_mysql', Installer::requiredModules());
        self::assertNotContains('pdo_sqlite', Installer::requiredModules());
        self::assertContains('pdo', Installer::requiredModules());
        self::assertSame(['pdo_mysql', 'pdo_sqlite'], Installer::databaseModules());

        self::assertFalse(Installer::databaseModuleAvailable(static fn (string $module): bool => false));
        self::assertTrue(Installer::databaseModuleAvailable(static fn (string $module): bool => $module === 'pdo_sqlite'));
        self::assertTrue(Installer::databaseModuleAvailable(static fn (string $module): bool => $module === 'pdo_mysql'));
    }
}
