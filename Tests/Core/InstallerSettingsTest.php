<?php

namespace Tests\Core {

    use Idno\Core\Installer;
    use Idno\Exceptions\InstallerException;

    /**
     * The decisions of the installer that need no database: what the database server field
     * means, how a value is written into config.ini, which upload folder is accepted, and how
     * the configuration file is saved. The web pages and the command line installer both use
     * these (\Idno\Core\Installer), so they are checked here once.
     */
    class InstallerSettingsTest extends \Tests\IdnoTestCase
    {

        /// A folder that stands for an installation, and one outside it
        private $install;
        private $outside;

        protected function setUp(): void
        {
            $base = rtrim(sys_get_temp_dir(), '/') . '/idno-installer-test-' . bin2hex(random_bytes(6));
            $this->install = $base . '/site';
            $this->outside = $base . '/outside';
            mkdir($this->install . '/configuration', 0755, true);
            mkdir($this->outside, 0755, true);
        }

        protected function tearDown(): void
        {
            $base = dirname($this->install);
            @chmod($this->install . '/configuration', 0755);
            @chmod($this->outside . '/locked', 0755);
            exec('rm -rf ' . escapeshellarg($base));
        }

        /**
         * An installer that writes into the test's folder
         */
        private function installer(): Installer
        {
            return new class($this->install) extends Installer {
                public function run()
                {
                }
            };
        }

        public static function databaseHosts(): array
        {
            return [
                'a name' => ['localhost', '', 'localhost', ''],
                'an address and the port field' => ['127.0.0.1', '33456', '127.0.0.1', '33456'],
                'the port after a colon' => ['127.0.0.1:33456', '', '127.0.0.1', '33456'],
                'a name with the port after a colon' => ['db.example.com:3307', '', 'db.example.com', '3307'],
                'the same port twice' => ['db.example.com:3307', '3307', 'db.example.com', '3307'],
                'spaces around both' => [' db.example.com ', ' 3307 ', 'db.example.com', '3307'],
                'a port with leading zeros' => ['db', '03307', 'db', '3307'],
                'IPv6 in brackets with a port' => ['[::1]:3307', '', '::1', '3307'],
                'IPv6 in brackets' => ['[2001:db8::5]', '', '2001:db8::5', ''],
                'IPv6 as it is' => ['2001:db8::5', '3307', '2001:db8::5', '3307'],
            ];
        }

        /**
         * @dataProvider databaseHosts
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('databaseHosts')]
        public function testDatabaseServerIsReadWithItsPort($host, $port, $expectedHost, $expectedPort)
        {
            $server = Installer::parseDatabaseHost($host, $port);
            $this->assertSame('', $server['error']);
            $this->assertSame($expectedHost, $server['host']);
            $this->assertSame($expectedPort, $server['port']);
        }

        public static function badDatabaseHosts(): array
        {
            return [
                'nothing' => ['', '', 'mysql_host'],
                'a socket path' => ['/var/run/mysqld/mysqld.sock', '', 'mysql_host'],
                'a socket after localhost' => ['localhost:/tmp/mysql.sock', '', 'mysql_host'],
                'a unix: address' => ['unix:/tmp/mysql.sock', '', 'mysql_host'],
                'something that would add to the connection string' => ['db;unix_socket=/tmp/x', '', 'mysql_host'],
                'a space inside' => ['db example', '', 'mysql_host'],
                'two different ports' => ['db:3307', '3308', 'mysql_port'],
                'a port that is no number' => ['db', 'abc', 'mysql_port'],
                'port 0' => ['db', '0', 'mysql_port'],
                'a port that is too large' => ['db', '70000', 'mysql_port'],
                'a port after the colon that is too large' => ['db:70000', '', 'mysql_port'],
            ];
        }

        /**
         * @dataProvider badDatabaseHosts
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('badDatabaseHosts')]
        public function testDatabaseServerThatCannotBeUsedIsRefusedOnItsField($host, $port, $field)
        {
            $server = Installer::parseDatabaseHost($host, $port);
            $this->assertNotSame('', $server['error']);
            $this->assertSame($field, $server['field']);
        }

        public static function awkwardValues(): array
        {
            $values = [
                'plain', '', ' ', "'", "''", "'''x'''", "a'b'c", '"', 'p"a$s #w;o\'rd', '${dbuser}', '{$x}', 'x\\', "\\'", 'back\\slash"q',
                'a=b [c] ; d # e', ' lead and trail ', 'null', 'yes', 'no', 'on', 'off', 'true', 'false', 'none', '0', '!~|&^()',
                "ünï 🎉", "' \ndbhost = 'evil", '%dbuser%', '%dbport_setting%', "x'\"'\"'y", '[section]', ';comment', '#comment',
            ];

            return array_combine(array_map('json_encode', $values), array_map(function ($value) {
                return [$value];
            }, $values));
        }

        /**
         * @dataProvider awkwardValues
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('awkwardValues')]
        public function testAPasswordIsReadBackFromTheConfigurationExactlyAndChangesNoOtherSetting($password)
        {
            if (preg_match('/[\x00-\x1F]/', $password)) {
                // A line break can't be written at all: it is refused, not smuggled in
                $this->expectException(\InvalidArgumentException::class);
            }
            $ini = $this->installer()->buildConfig(
                [
                    'dbname' => 'idno', 'dbuser' => 'someone', 'dbpass' => $password, 'dbhost' => 'db.example.com', 'dbport' => '3307',
                    'uploadpath' => '/srv/idno uploads/',
                ]
            );

            $file = $this->outside . '/config.ini';
            file_put_contents($file, $ini);
            $read = parse_ini_file($file, true);

            $this->assertSame(
                [
                    'database' => 'MySQL', 'dbname' => 'idno', 'dbuser' => 'someone', 'dbpass' => $password, 'dbhost' => 'db.example.com',
                    'dbport' => '3307', 'filesystem' => 'local', 'uploadpath' => '/srv/idno uploads/',
                ],
                $read,
                'The configuration should hold these settings and no others: ' . $ini
            );
        }

        public function testThePortIsASettingOnlyWhenOneWasGiven()
        {
            $ini = $this->installer()->buildConfig(['dbname' => 'idno', 'dbhost' => 'localhost', 'dbport' => '']);
            $this->assertStringNotContainsString('dbport', $ini);
            $this->assertStringNotContainsString('%', $ini, 'No placeholder should be left in the file.');

            $ini = $this->installer()->buildConfig(['dbname' => 'idno', 'dbhost' => '127.0.0.1', 'dbport' => '33456']);
            $this->assertStringContainsString("dbport = '33456'", $ini);
        }

        public function testSettingsAreTidiedAndThePortIsSplitFromTheServer()
        {
            $checked = $this->installer()->normaliseSettings(
                [
                    'site_title' => ' My site ', 'mysql_name' => ' idno ', 'mysql_user' => 'idno', 'mysql_pass' => ' pass with spaces ',
                    'mysql_host' => '127.0.0.1:33456', 'upload_path' => $this->outside . '/uploads',
                ]
            );
            $this->assertSame([], $checked['errors']);
            $this->assertSame('My site', $checked['values']['site_title']);
            $this->assertSame('idno', $checked['values']['mysql_name']);
            $this->assertSame(' pass with spaces ', $checked['values']['mysql_pass'], 'A password is kept exactly as typed.');
            $this->assertSame('127.0.0.1', $checked['values']['mysql_host']);
            $this->assertSame('33456', $checked['values']['mysql_port']);
            $this->assertSame($this->outside . '/uploads/', $checked['values']['upload_path']);
            $this->assertSame('MySQL', $checked['values']['database']);
        }

        public function testEachWrongSettingIsReportedOnItsOwnField()
        {
            $checked = $this->installer()->normaliseSettings(
                [
                    'mysql_name' => 'idno; drop', 'mysql_user' => '', 'mysql_host' => 'db', 'mysql_port' => '99999', 'upload_path' => 'uploads',
                ]
            );
            $this->assertSame(['mysql_name', 'mysql_user', 'mysql_port', 'upload_path'], array_keys($checked['errors']));
            foreach ($checked['errors'] as $problem) {
                $this->assertNotSame('', $problem['text']);
            }

            $checked = $this->installer()->normaliseSettings(['mysql_name' => 'idno', 'mysql_user' => "a\nb", 'mysql_host' => 'db', 'upload_path' => '/srv/x']);
            $this->assertSame(['mysql_user'], array_keys($checked['errors']));
        }

        public function testTheUploadFolderThatWasTypedIsTheOneChecked()
        {
            $path = $this->outside . '/files';
            mkdir($path);

            $this->assertSame($path . '/', $this->installer()->checkUploadDirectory($path), 'A folder without a slash at the end is accepted.');
            $this->assertSame($path . '/', $this->installer()->checkUploadDirectory($path . '/'));
            $this->assertSame([], array_values(array_diff(scandir($path), ['.', '..'])), 'The file saved to check the folder should be gone.');
        }

        public function testAnUploadFolderThatDoesNotExistIsCreated()
        {
            $path = $this->outside . '/new/deeper';
            $this->assertSame($path . '/', $this->installer()->checkUploadDirectory($path));
            $this->assertDirectoryExists($path);
        }

        public function testAnUploadFolderThatCannotBeWrittenIsRefusedWithWhatToRun()
        {
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                $this->markTestSkipped('root may write anywhere');
            }
            $path = $this->outside . '/locked';
            mkdir($path, 0555);

            try {
                $this->installer()->checkUploadDirectory($path);
                $this->fail('A folder that can\'t be written to should be refused.');
            } catch (InstallerException $e) {
                $problem = $e->getProblem();
                $this->assertStringContainsString($path . '/', $problem['text'], 'The message should name the folder that was typed.');
                $this->assertStringContainsString('chown', implode("\n", $problem['commands']));
                $this->assertStringContainsString(escapeshellarg($path), implode("\n", $problem['commands']));
                if (Installer::serverUser() !== '') {
                    $this->assertStringContainsString(Installer::serverUser(), $problem['text'], 'The message should name the user that needs to write.');
                }
            }

            // And one that can't be created
            try {
                $this->installer()->checkUploadDirectory($path . '/inside');
                $this->fail('A folder that can\'t be created should be refused.');
            } catch (InstallerException $e) {
                $this->assertStringContainsString('mkdir -p ' . escapeshellarg($path . '/inside'), implode("\n", $e->getProblem()['commands']));
            }
        }

        public function testAFileIsNotAnUploadFolder()
        {
            file_put_contents($this->outside . '/a-file', 'x');
            $this->expectException(InstallerException::class);
            $this->installer()->checkUploadDirectory($this->outside . '/a-file');
        }

        public function testAnUploadFolderInsideTheInstallationIsRefusedExceptUploads()
        {
            $this->assertSame($this->install . '/Uploads/', $this->installer()->checkUploadDirectory($this->install . '/Uploads'), 'Uploads is closed to visitors by the shipped web server configuration.');

            foreach ([$this->install, $this->install . '/files', $this->install . '/Uploads/../files', $this->install . '/css/uploads'] as $inside) {
                try {
                    $this->installer()->checkUploadDirectory($inside);
                    $this->fail($inside . ' is served by the web server and should be refused.');
                } catch (InstallerException $e) {
                    $this->assertStringContainsString('inside the folder the web server serves', $e->getProblem()['text']);
                }
            }
            $this->assertDirectoryDoesNotExist($this->install . '/files', 'A refused folder should not have been created.');
        }

        public function testTheConfigurationFileIsWrittenOnceAndNeverReplaced()
        {
            $installer = $this->installer();
            $this->assertFalse($installer->isInstalled());
            $this->assertTrue($installer->canWriteConfig());

            $installer->writeConfig("dbname = 'first'\n");
            $file = $this->install . '/configuration/config.ini';
            $this->assertSame("dbname = 'first'\n", file_get_contents($file));
            $this->assertTrue($installer->isInstalled());
            $this->assertSame(['.', '..', 'config.ini'], scandir(dirname($file)), 'Nothing but the file should be left in the folder.');

            try {
                $installer->writeConfig("dbname = 'second'\n");
                $this->fail('A configuration file that exists should not be replaced.');
            } catch (InstallerException $e) {
                $this->assertSame('config_exists', $e->getField());
            }
            $this->assertSame("dbname = 'first'\n", file_get_contents($file));
            $this->assertSame(['.', '..', 'config.ini'], scandir(dirname($file)));
        }

        public function testAConfigurationFolderThatCannotBeWrittenIsKnownBeforehand()
        {
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                $this->markTestSkipped('root may write anywhere');
            }
            chmod($this->install . '/configuration', 0555);
            $installer = $this->installer();

            $this->assertFalse($installer->canWriteConfig());
            try {
                $installer->writeConfig("dbname = 'x'\n");
                $this->fail('The file can\'t be saved here.');
            } catch (InstallerException $e) {
                $this->assertSame('config', $e->getField());
            }
            $this->assertSame(['.', '..'], scandir($this->install . '/configuration'));
        }

        public function testOnlyAnIniFileInTheConfigurationFolderCanBeNamed()
        {
            $this->assertSame($this->install . '/configuration/my.domain.ini', $this->installer()->configPath('my.domain.ini'));
            foreach (['../config.ini', 'config.php', '.ini', 'a/b.ini', ''] as $name) {
                try {
                    $this->installer()->configPath($name);
                    $this->fail($name . ' should be refused.');
                } catch (InstallerException $e) {
                    $this->assertSame('config', $e->getField());
                }
            }
        }

        public function testNothingIsDoneWhenASettingIsWrong()
        {
            $result = $this->installer()->install(
                ['mysql_name' => 'idno', 'mysql_user' => 'idno', 'mysql_host' => 'db:99999', 'upload_path' => $this->outside . '/never'],
            );
            $this->assertFalse($result['ok']);
            $this->assertSame(['mysql_port'], array_keys($result['errors']));
            $this->assertFalse($result['config_written']);
            $this->assertSame(['.', '..'], scandir($this->install . '/configuration'));
            $this->assertFileDoesNotExist($this->install . '/.htaccess');
        }

        public function testASiteWithoutConfigurationSendsEveryAddressToTheInstallerBesideIndexPhp()
        {
            $this->assertSame('/warmup/', Installer::address('/index.php'));
            $this->assertSame('/warmup/', Installer::address('index.php'));
            $this->assertSame('/community/warmup/', Installer::address('/community/index.php'));
            $this->assertSame('/a/b/warmup/', Installer::address('\\a\\b\\index.php'));
            $this->assertSame('/warmup/', Installer::address(''));
            $this->assertSame('/warmup/', Installer::address("/x\r\nSet-Cookie: a=b/index.php"), 'Nothing but a plain path goes into the header.');
        }

        public function testTheInstallerChecksPhpAsComposerDoes()
        {
            $composer = json_decode(file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
            $this->assertSame('>=8.3.0', $composer['require']['php'], 'When the required PHP version changes, change Installer::checkPHPVersion() too.');
            $this->assertSame('ok', Installer::checkPHPVersion());

            $extensions = [];
            foreach (array_keys($composer['require']) as $name) {
                if (strpos($name, 'ext-') === 0) {
                    $extensions[] = strtolower(substr($name, 4));
                }
            }
            sort($extensions);
            $required = Installer::requiredModules();
            sort($required);
            $this->assertSame($extensions, $required, 'The requirements page should check the extensions composer.json requires.');
        }
    }
}
