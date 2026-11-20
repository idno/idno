<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * What the web installer's settings page decides from a request (Installer::checkWebSettings()). The
 * installer of an uninstalled site is reachable by anyone, so it must never create a file or a
 * directory at a place the request chose, nor anything at all unless the request is a POST whose other
 * fields have passed their checks.
 */
final class WebSettingsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is needed.');
        }
        $this->root = sys_get_temp_dir() . '/idno-websettings-test-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/warmup/webserver-configs', 0755, true);
        mkdir($this->root . '/Uploads', 0755, true);
        copy(dirname(__DIR__, 3) . '/warmup/webserver-configs/config.ini.template', $this->root . '/warmup/webserver-configs/config.ini.template');
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
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

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function sqliteRequest(array $extra = []): array
    {
        return array_merge(['database' => 'sqlite', 'upload_path' => $this->root . '/Uploads/'], $extra);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostilePaths(): array
    {
        return [
            'a php file' => ['shell.php'],
            'an absolute php file' => ['/tmp/shell.php'],
            'a relative escape' => ['../escaped.sqlite'],
            'an absolute path' => ['/tmp/idno-elsewhere/idno.sqlite'],
            'a nested path' => ['public/db/idno.sqlite'],
        ];
    }

    /**
     * @dataProvider hostilePaths
     */
    public function testTheSqliteLocationIsAlwaysTheDefaultWhateverThePathTheRequestCarries(string $path): void
    {
        $result = (new InstallerUnderTest($this->root))->settings('POST', $this->sqliteRequest(['sqlite_path' => $path]));

        self::assertTrue($result['ok'], (string) $result['messages']);
        self::assertSame($this->root . '/data/idno.sqlite', $result['dbParams']['dbname']);
        self::assertFileExists($this->root . '/data/idno.sqlite');
        self::assertFileDoesNotExist($this->root . '/shell.php');
        self::assertFileDoesNotExist($this->root . '/public');
        self::assertFileDoesNotExist('/tmp/shell.php');
        self::assertFileDoesNotExist('/tmp/idno-elsewhere');
        self::assertSame($this->root . '/data/idno.sqlite', $result['sqlitePath']);
    }

    public function testNothingIsCreatedOnAGet(): void
    {
        $installer = new InstallerUnderTest($this->root);
        foreach ([$this->sqliteRequest(), $this->sqliteRequest(['sqlite_path' => 'shell.php'])] as $request) {
            foreach (['GET', 'HEAD', ''] as $method) {
                $result = $installer->settings($method, $request);
                self::assertFalse($result['submitted']);
                self::assertSame([], $result['dbParams']);
            }
        }
        self::assertSame([$this->root . '/Uploads', $this->root . '/warmup'], glob($this->root . '/*', GLOB_ONLYDIR) ?: []);
        self::assertFileDoesNotExist($this->root . '/data');
        self::assertFileDoesNotExist($this->root . '/shell.php');
    }

    public function testNothingIsCreatedWhenTheUploadPathFails(): void
    {
        $installer = new InstallerUnderTest($this->root);
        foreach ([$this->root . '/nowhere', '', '  '] as $upload) {
            $result = $installer->settings('POST', $this->sqliteRequest(['upload_path' => $upload]));

            self::assertFalse($result['ok']);
            self::assertSame([], $result['dbParams']);
            self::assertNotSame('', $result['messages']);
            self::assertFileDoesNotExist($this->root . '/data');
        }
    }

    public function testAMissingUploadPathIsExplainedForSqliteToo(): void
    {
        $result = (new InstallerUnderTest($this->root))->settings('POST', $this->sqliteRequest(['upload_path' => '']));

        self::assertStringContainsString('You need to specify an upload path.', $result['messages']);
        self::assertSame('', $result['uploadPath']);
    }

    public function testAnArrayWhereAStringIsExpectedIsEmptyAndRaisesNoWarning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $installer = new InstallerUnderTest($this->root);
            $result = $installer->settings('POST', $this->sqliteRequest(['upload_path' => ['x'], 'site_title' => ['a'], 'sqlite_path' => ['b']]));
            $other = $installer->settings('POST', ['database' => ['sqlite'], 'upload_path' => $this->root . '/Uploads/', 'mysql_name' => ['n'], 'mysql_host' => ['h']]);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertStringContainsString('You need to specify an upload path.', $result['messages']);
        self::assertSame('', $result['uploadPath']);
        self::assertSame('', $result['values']['site_title']);
        self::assertFalse($other['submitted'] && $other['ok'] && $other['database'] === 'sqlite');
        self::assertFileDoesNotExist($this->root . '/data');
    }

    public function testAValidPostCreatesTheDatabaseAndSaysItIsReady(): void
    {
        $result = (new InstallerUnderTest($this->root))->settings('POST', $this->sqliteRequest(['site_title' => 'My site']));

        self::assertTrue($result['ok']);
        self::assertSame('sqlite', $result['database']);
        self::assertSame(realpath($this->root . '/Uploads') . '/', $result['uploadPath']);
        self::assertSame('sqlite', $result['dbParams']['database']);
        self::assertSame('My site', $result['values']['site_title']);
        self::assertFileExists($this->root . '/data/idno.sqlite');
    }

    public function testMysqlNeedsItsNameAndHostAndConnectsOnlyOnAPost(): void
    {
        $installer = new InstallerUnderTest($this->root);
        $post = $installer->settings('POST', ['database' => 'mysql', 'upload_path' => $this->root . '/Uploads/']);
        self::assertFalse($post['ok']);
        self::assertStringContainsString('name of your MySQL database', $post['messages']);

        $get = $installer->settings('GET', ['database' => 'mysql', 'mysql_name' => 'a', 'mysql_host' => '127.0.0.1', 'mysql_user' => 'u', 'mysql_pass' => 'p', 'upload_path' => $this->root . '/Uploads/']);
        self::assertSame([], $get['dbParams']);
        self::assertSame('', $get['messages'], 'a GET connects to nothing and says nothing');
    }

    public function testAMongoChoiceIsRefused(): void
    {
        $result = (new InstallerUnderTest($this->root))->settings('POST', $this->sqliteRequest(['database' => 'mongodb']));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('MongoDB is no longer supported', $result['messages']);
        self::assertFileDoesNotExist($this->root . '/data');
    }

    public function testAMysqlPasswordWithAQuoteIsRefusedBeforeAnythingIsDone(): void
    {
        $secret = "pa'ss-word-1234";
        $result = (new InstallerUnderTest($this->root))->settings('POST', [
            'database' => 'mysql', 'upload_path' => $this->root . '/Uploads/', 'mysql_name' => 'known', 'mysql_user' => 'u',
            'mysql_host' => 'invalid.invalid', 'mysql_pass' => $secret,
        ]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('dbpass', (string) $result['messages']);
        self::assertStringNotContainsString($secret, (string) $result['messages']);
        self::assertSame([], $result['dbParams'], 'the database was not checked');
        self::assertSame(['Uploads', 'warmup'], array_values(array_diff(scandir($this->root) ?: [], ['.', '..'])), 'nothing was created');
    }

    public function testAnSqliteInstallWithAnUploadPathThatCannotBeWrittenIsRefusedBeforeTheDatabaseIsCreated(): void
    {
        $upload = $this->root . "/Up'loads";
        mkdir($upload, 0755);
        $result = (new InstallerUnderTest($this->root))->settings('POST', $this->sqliteRequest(['upload_path' => $upload . '/']));

        self::assertFalse($result['ok']);
        self::assertStringContainsString('uploadpath', (string) $result['messages']);
        self::assertStringNotContainsString("Up'loads", (string) $result['messages']);
        self::assertSame([], $result['dbParams']);
        self::assertDirectoryDoesNotExist($this->root . '/data');
        self::assertFileDoesNotExist($this->root . '/data/idno.sqlite');
    }
}
