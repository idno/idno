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
        $path = $this->root . '/my data/idno-site.sqlite';
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

    /**
     * @return array<string, array{string}>
     */
    public static function refusedSqlitePaths(): array
    {
        return [
            'a php file' => ['shell.php'],
            'a php file in a directory' => ['public/shell.php'],
            'an absolute php file' => ['/var/www/html/shell.php'],
            'no suffix' => ['data/idno'],
            'another suffix' => ['data/idno.db'],
            'the suffix in another case' => ['data/idno.SQLite'],
            'the suffix in upper case' => ['data/IDNO.SQLITE'],
            'a php name that ends in sqlite after a dot only' => ['data/idno.sqlite.php'],
            'only the suffix' => ['data/.sqlite'],
            'a directory' => ['data/'],
            'a space in the file name' => ['data/my site.sqlite'],
            'dot dot first' => ['../idno.sqlite'],
            'dot dot inside' => ['data/../../idno.sqlite'],
            'absolute dot dot' => ['/tmp/a/../idno.sqlite'],
            'backslash dot dot' => ['data\\..\\idno.sqlite'],
            'a backslash php file' => ['C:\\site\\shell.php'],
        ];
    }

    /**
     * Only a file whose name ends in .sqlite is a database: every shipped web server configuration
     * refuses to serve that suffix, and PHP never executes it. The path is checked before anything is
     * created, so a refused one leaves nothing behind.
     *
     * @dataProvider refusedSqlitePaths
     */
    public function testAPathThatCouldBeServedOrEscapeItsDirectoryIsRefusedAndNothingIsCreated(string $path): void
    {
        try {
            $this->installer()->params('sqlite', ['path' => $path]);
            self::fail('Accepted ' . $path);
        } catch (ConfigurationException $e) {
            self::assertNotSame('', $e->getMessage());
        }
        self::assertSame([$this->root . '/warmup'], glob($this->root . '/*', GLOB_ONLYDIR) ?: [], 'no directory was created');
        self::assertFileDoesNotExist($this->root . '/shell.php');
        self::assertFileDoesNotExist($this->root . '/idno.sqlite');
        self::assertFileDoesNotExist($this->root . '/data/idno.sqlite');
    }

    public function testTheRefusalSaysWhy(): void
    {
        try {
            $this->installer()->params('sqlite', ['path' => 'shell.php']);
            self::fail('Accepted shell.php');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('.sqlite', $e->getMessage());
        }
        try {
            $this->installer()->params('sqlite', ['path' => 'data/idno.SQLite']);
            self::fail('Accepted .SQLite');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('lower case .sqlite exactly', $e->getMessage());
        }
        try {
            $this->installer()->params('sqlite', ['path' => 'data/../idno.sqlite']);
            self::fail('Accepted ..');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('..', $e->getMessage());
        }
    }

    public function testALowerCaseSuffixAndDotsDashesAndCapitalsInTheNameAreAccepted(): void
    {
        foreach (['data/My-Site_1.v2.sqlite', 'a.b.sqlite'] as $path) {
            self::assertSame($this->root . '/' . $path, $this->installer()->params('sqlite', ['path' => $path])['dbname']);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileValues(): array
    {
        $values = [
            "pw'\ndatabase = 'sqlite'\ndbname = '/var/www/html/shell.php",
            "'", '"', "a'b", "a\nb", "a\r\nb", "x\0y", 'a\\', '${foo}', 'a;b', 'a#b', 'a=b', '[section]', 'on', 'null',
            "a'\n[x]\ny='z", 'a\\b', "tab\there", "a\x7fb", 'yes', 'none', 'a b', '~!@%^&*()', '{x}', 'a|b', '', "a\x1fb", "a\\\nb",
            '%dbpass%', '%uploadpath%', '%database%', '%dbhost%%dbhost%', '\\%dbpass%',
        ];
        $sets = [];
        foreach ($values as $i => $value) {
            $sets[$i . ' ' . addcslashes($value, "\0..\37\177")] = [$value];
        }

        return $sets;
    }

    /**
     * The property that matters: whatever value a parameter holds, the configuration file built from it
     * either is refused (naming the setting) or reads back, as the site reads it (`parse_ini_file()` with
     * sections), with exactly that value for that key and the expected values for every other key, and no
     * key added.
     *
     * @dataProvider hostileValues
     */
    public function testAHostileValueNeverChangesAnotherSettingOfTheConfiguration(string $hostile): void
    {
        $installer = $this->installer();
        $base = ['database' => 'MySQL', 'dbname' => 'known', 'dbuser' => 'user', 'dbpass' => 'secret', 'dbhost' => 'localhost', 'filesystem' => 'local', 'uploadpath' => '/srv/uploads/'];
        $generated = ['build', 'version', 'datetime'];
        foreach (array_keys($base) as $key) {
            try {
                $text = $installer->config([$key => $hostile] + $base);
            } catch (ConfigurationException $e) {
                self::assertStringContainsString($key, $e->getMessage());
                if ($hostile !== '' && preg_match('/[\x00-\x1f\x7f]/', $hostile) !== 1) {
                    self::assertStringNotContainsString($hostile, $e->getMessage());
                }
                continue;
            }
            $read = parse_ini_string($text, true);
            self::assertIsArray($read, $key);
            $expected = [$key => $hostile] + $base;
            self::assertSame([], array_diff(array_keys($read), array_keys($base), $generated), 'a key was added for ' . $key);
            foreach ($expected as $name => $value) {
                self::assertArrayHasKey($name, $read, $key);
                self::assertSame($value, $read[$name], $key . ' / ' . $name);
            }
        }
    }

    public function testAValueThatNamesAPlaceholderIsWrittenAsGiven(): void
    {
        $text = $this->installer()->config(['database' => 'MySQL', 'dbname' => '%dbpass%', 'dbuser' => 'u', 'dbpass' => 'secret', 'dbhost' => '%dbhost%%dbhost%', 'uploadpath' => '/u/']);
        $read = parse_ini_string($text, true);

        self::assertIsArray($read);
        self::assertSame('%dbpass%', $read['dbname']);
        self::assertSame('secret', $read['dbpass']);
        self::assertSame('%dbhost%%dbhost%', $read['dbhost']);
    }

    public function testTheValuesCanBeCheckedWithoutBuildingTheFile(): void
    {
        $installer = $this->installer();
        $installer->assertWritableConfigValues(['dbpass' => 'fine', 'uploadpath' => '/u/']);
        try {
            $installer->assertWritableConfigValues(['dbpass' => "it's"]);
            self::fail('A quote was accepted.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('dbpass', $e->getMessage());
            self::assertStringNotContainsString("it's", $e->getMessage());
        }
    }

    public function testValuesThatAreHarmlessInSingleQuotesAreAccepted(): void
    {
        $installer = $this->installer();
        foreach (['a;b', 'a#b', 'a=b', '[section]', '${foo}', 'on', 'null', 'a b', 'p@ss/w:rd%', 'a\\b'] as $value) {
            $config = parse_ini_string($installer->config(['dbpass' => $value, 'database' => 'MySQL', 'dbname' => 'k', 'dbuser' => 'u', 'dbhost' => 'h', 'uploadpath' => '/u/']), true);
            self::assertIsArray($config);
            self::assertSame($value, $config['dbpass'], $value);
        }
    }

    public function testTheReviewersPayloadIsRefusedAndNamesTheSettingNotTheValue(): void
    {
        $payload = "pw'\ndatabase = 'sqlite'\ndbname = '/var/www/html/shell.php";
        try {
            $this->installer()->config(['dbpass' => $payload, 'database' => 'MySQL', 'dbname' => 'k', 'dbuser' => 'u', 'dbhost' => 'h', 'uploadpath' => '/u/']);
            self::fail('The payload was written into a configuration file.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('dbpass', $e->getMessage());
            self::assertStringNotContainsString('shell.php', $e->getMessage());
            self::assertStringContainsString('config.ini', $e->getMessage());
        }
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
        foreach (['postgres', 'Oracle', ''] as $typed) {
            self::assertSame('mysql', InstallerUnderTest::type($typed));
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mongoNames(): array
    {
        return ['mongo' => ['mongo'], 'mongodb' => ['mongodb'], 'MongoDB' => ['MongoDB'], 'padded' => ['  MONGO ']];
    }

    /**
     * MongoDB is no longer a database Idno runs on: both installers refuse it with a message that
     * says so and names the choices, instead of writing a MySQL configuration.
     *
     * @dataProvider mongoNames
     */
    public function testMongoIsRefusedWithAClearMessage(string $typed): void
    {
        foreach ([
            static fn () => InstallerUnderTest::type($typed),
            fn () => $this->installer()->params($typed, ['name' => 'known', 'user' => 'u', 'pass' => 'p', 'host' => 'localhost']),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('MongoDB was accepted');
            } catch (\Idno\Exceptions\ConfigurationException $e) {
                self::assertStringContainsString('MongoDB is no longer supported', $e->getMessage());
                self::assertStringContainsString('MySQL', $e->getMessage());
                self::assertStringContainsString('SQLite', $e->getMessage());
                self::assertStringContainsString('idno:import:mongo', $e->getMessage());
            }
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
