<?php

namespace Tests\Core {

    /**
     * A new installation from beginning to end: the web installer's pages are run in their own
     * PHP process (Tests/Core/fixtures/web_installer.php), writing into a temporary folder and
     * an empty scratch database, and the first administrator then registers on a site that is
     * served from that database (Tests/Core/fixtures/fresh_site_router.php).
     *
     * Needs the MySQL back end and a database user that may create a database, as
     * Tests/Data/MySQLUtf8mb4Test.php does.
     */
    class InstallerFlowTest extends \Tests\IdnoTestCase
    {

        /// A password with everything in it that once broke config.ini
        const PASSWORD = 'p"a$s #w;o\'rd = [x] ${y}';

        private $base;
        private $install;
        private $uploads;
        private $database;
        private $user = '';
        private $server;

        protected function setUp(): void
        {
            if (!(\Idno\Core\Idno::site()->db() instanceof \Idno\Data\MySQL)) {
                $this->markTestSkipped('The installer is tested on the MySQL back end.');
            }

            $this->database = \Idno\Core\Idno::site()->db()->getDatabase() . '_installer_' . bin2hex(random_bytes(3));
            try {
                $this->root()->exec("create database `{$this->database}`");
            } catch (\PDOException $e) {
                $this->markTestSkipped('The database user can not create a scratch database: ' . $e->getMessage());
            }

            $this->base = rtrim(sys_get_temp_dir(), '/') . '/idno-installer-flow-' . bin2hex(random_bytes(6));
            $this->install = $this->base . '/site';
            $this->uploads = $this->base . '/uploads outside';
            mkdir($this->install . '/configuration', 0755, true);
        }

        protected function tearDown(): void
        {
            if ($this->server) {
                proc_terminate($this->server);
                proc_close($this->server);
            }
            if ($this->base) {
                @chmod($this->install . '/configuration', 0755);
                exec('rm -rf ' . escapeshellarg($this->base));
            }
            if ($this->database && \Idno\Core\Idno::site()->db() instanceof \Idno\Data\MySQL) {
                try {
                    $this->root()->exec("drop database if exists `{$this->database}`");
                    if ($this->user !== '') {
                        $this->root()->exec("drop user if exists '{$this->user}'@'%'");
                    }
                } catch (\PDOException $e) {
                    // Nothing to clean up
                }
            }
        }

        /**
         * A connection as the test site's own database user, to a database or to none
         */
        private function root($database = null): \PDO
        {
            $config = \Idno\Core\Idno::site()->config();
            $dsn = 'mysql:host=' . $config->dbhost . ';charset=utf8mb4';
            if (!empty($config->dbport)) {
                $dsn .= ';port=' . $config->dbport;
            }
            if ($database) {
                $dsn .= ';dbname=' . $database;
            }

            return new \PDO($dsn, $config->dbuser, $config->dbpass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        }

        private function tables(): array
        {
            return $this->root($this->database)->query('show tables')->fetchAll(\PDO::FETCH_COLUMN);
        }

        /**
         * What a person would type into the settings form for the scratch database. The
         * server is given as the test site reaches it, with the port in the port field.
         */
        private function settings(array $change = []): array
        {
            $config = \Idno\Core\Idno::site()->config();

            return array_merge(
                [
                    'site_title' => 'Tom & Jerry\'s <site>',
                    'mysql_name' => $this->database,
                    'mysql_user' => (string) $config->dbuser,
                    'mysql_pass' => (string) $config->dbpass,
                    'mysql_host' => (string) $config->dbhost,
                    'mysql_port' => (string) $config->dbport,
                    'upload_path' => $this->uploads,
                ], $change
            );
        }

        /**
         * One request to the web installer
         *
         * @return string The page, or "REDIRECT: <address>"
         */
        private function request($method, $stage, array $post = [], $install = null): string
        {
            // 22527 is E_ALL without deprecations
            $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=1 '
                . escapeshellarg(__DIR__ . '/fixtures/web_installer.php') . ' ' . escapeshellarg($install ?: $this->install) . ' '
                . escapeshellarg($method) . ' ' . escapeshellarg($stage) . ' ' . escapeshellarg(json_encode($post)) . ' 2>&1';
            $page = (string) shell_exec($command);
            $this->assertDoesNotMatchRegularExpression('/(Warning|Notice|Fatal error|Deprecated)<\/b>:|^(PHP )?(Warning|Notice|Fatal error):/m', $page, 'The installer should raise no PHP error.');

            return $page;
        }

        private function configFile(): string
        {
            return $this->install . '/configuration/config.ini';
        }

        public function testTheSettingsPageAsksForAPortAndHoldsNoPassword()
        {
            $page = $this->request('GET', 'settings');

            $this->assertStringContainsString('name="mysql_port"', $page);
            $this->assertMatchesRegularExpression('/name="mysql_host"[^>]*value="localhost"/s', $page);
            $this->assertMatchesRegularExpression('/name="mysql_pass"[^>]*value=""/s', $page);
            $this->assertStringContainsString(htmlspecialchars($this->install . '/Uploads/'), $page, 'The suggested upload folder is Uploads in the installation.');
            $this->assertSame([], $this->tables());
        }

        public function testAGetRequestInstallsNothing()
        {
            // Settings in the address must not be acted on: only a submitted form is
            $page = $this->request('GET', 'settings', $this->settings());
            $this->assertStringNotContainsString('REDIRECT', $page);

            $this->assertFileDoesNotExist($this->configFile());
            $this->assertSame([], $this->tables());
        }

        public function testAWrongPasswordIsReportedBesideTheUserAndNothingIsLeftBehind()
        {
            $page = $this->request('POST', 'settings', $this->settings(['mysql_pass' => 'not-the-password <b>']));

            $this->assertStringNotContainsString('REDIRECT', $page);
            $this->assertStringContainsString('refused this user name and password', $page);
            $this->assertStringContainsString('nothing was changed', $page);
            // What was typed is kept, escaped, except the password
            $this->assertStringContainsString('value="Tom &amp; Jerry&#039;s &lt;site&gt;"', $page);
            $this->assertStringContainsString('value="' . htmlspecialchars($this->database) . '"', $page);
            $this->assertStringContainsString('value="' . htmlspecialchars($this->uploads . '/') . '"', $page);
            $this->assertStringNotContainsString('not-the-password', $page, 'The password must not be sent back.');
            // The error comes before the field it is about
            $this->assertLessThan(strpos($page, 'name="mysql_user"'), strpos($page, 'refused this user name'));
            $this->assertGreaterThan(strpos($page, 'name="mysql_name"'), strpos($page, 'refused this user name'));

            $this->assertFileDoesNotExist($this->configFile());
            $this->assertFileDoesNotExist($this->install . '/.htaccess');
            $this->assertSame([], $this->tables());
        }

        public function testAWrongPortIsReportedBesideTheServerWithThePortItTried()
        {
            $page = $this->request('POST', 'settings', $this->settings(['mysql_host' => '127.0.0.1', 'mysql_port' => '1']));

            $this->assertStringContainsString('No database server could be reached at 127.0.0.1, port 1.', $page);
            $this->assertMatchesRegularExpression('/name="mysql_host"[^>]*value="127\.0\.0\.1"/s', $page, 'The server that was typed is kept in its field.');
            $this->assertMatchesRegularExpression('/name="mysql_port"[^>]*value="1"/s', $page);
            $this->assertFileDoesNotExist($this->configFile());
        }

        public function testAnUploadFolderThatCannotBeUsedStopsTheInstallationBeforeTheDatabaseIsTouched()
        {
            file_put_contents($this->base . '/a-file', 'x');
            $page = $this->request('POST', 'settings', $this->settings(['upload_path' => $this->base . '/a-file']));

            $this->assertStringContainsString(htmlspecialchars($this->base . '/a-file/') . ' is a file, not a folder', $page);
            $this->assertFileDoesNotExist($this->configFile());
            $this->assertSame([], $this->tables(), 'No table should be made while a setting is wrong.');
        }

        public function testInstallingWritesTheConfigurationLoadsTheSchemaAndHandsOverToRegistration()
        {
            // A database user with an awkward password, where the test's own user may create one
            $settings = $this->settings();
            try {
                $this->user = 'idno_inst_' . bin2hex(random_bytes(3));
                $root = $this->root();
                $root->exec("create user '{$this->user}'@'%' identified by " . $root->quote(self::PASSWORD));
                $root->exec("grant all on `{$this->database}`.* to '{$this->user}'@'%'");
                $settings['mysql_user'] = $this->user;
                $settings['mysql_pass'] = self::PASSWORD;
            } catch (\PDOException $e) {
                $this->user = '';
            }
            // The port typed after the server's name, as people do
            if ($settings['mysql_port'] !== '') {
                $settings['mysql_host'] .= ':' . $settings['mysql_port'];
                $settings['mysql_port'] = '';
            }

            $page = $this->request('POST', 'settings', $settings);
            $this->assertSame('REDIRECT: ../begin/register?set_name=' . rawurlencode('Tom & Jerry\'s <site>'), $page);

            // The configuration holds exactly what was typed
            $config = parse_ini_file($this->configFile(), true);
            $expected = $this->settings();
            $this->assertSame('MySQL', $config['database']);
            $this->assertSame($this->database, $config['dbname']);
            $this->assertSame($settings['mysql_user'], $config['dbuser']);
            $this->assertSame($settings['mysql_pass'], $config['dbpass']);
            $this->assertSame($expected['mysql_host'], $config['dbhost']);
            $this->assertSame($expected['mysql_port'], $config['dbport'] ?? '');
            $this->assertSame($this->uploads . '/', $config['uploadpath']);
            $this->assertSame('local', $config['filesystem']);
            $this->assertCount($expected['mysql_port'] !== '' ? 8 : 7, $config, 'No value may have added a setting.');

            // The upload folder that was typed was made, and the schema is the current one
            $this->assertDirectoryExists($this->uploads);
            $this->assertSame([], array_values(array_diff(scandir($this->uploads), ['.', '..'])));
            $this->assertContains('entities', $this->tables());
            $this->assertContains('versions', $this->tables());
            $this->assertFileExists($this->install . '/.htaccess');

            // Installed: the installer now does nothing, whatever it is sent
            $before = file_get_contents($this->configFile());
            $this->assertSame('REDIRECT: ../', $this->request('POST', 'settings', $this->settings(['mysql_name' => 'another', 'upload_path' => $this->base . '/other'])));
            $this->assertSame('REDIRECT: ../', $this->request('GET', 'settings'));
            $this->assertSame('REDIRECT: ../', $this->request('GET', 'requirements'));
            $this->assertSame('REDIRECT: ../', $this->request('GET', ''));
            $this->assertSame($before, file_get_contents($this->configFile()));
            $this->assertDirectoryDoesNotExist($this->base . '/other');

            $this->firstVisitorBecomesTheAdministrator($config);
        }

        /**
         * Serve a site from the database the installer prepared, and register on it.
         */
        private function firstVisitorBecomesTheAdministrator(array $config)
        {
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);

            $environment = array_merge(
                getenv(), [
                    'FRESH_SITE_DBNAME' => $config['dbname'], 'FRESH_SITE_DBUSER' => $config['dbuser'], 'FRESH_SITE_DBPASS' => $config['dbpass'],
                    'FRESH_SITE_DBHOST' => $config['dbhost'], 'FRESH_SITE_DBPORT' => $config['dbport'] ?? '', 'FRESH_SITE_UPLOADPATH' => $config['uploadpath'],
                    'FRESH_SITE_SESSIONNAME' => 'idno_fresh',
                ]
            );
            $this->server = proc_open(
                [PHP_BINARY, '-d', 'error_reporting=22527', '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__, 2), __DIR__ . '/fixtures/fresh_site_router.php'],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, dirname(__DIR__, 2), $environment
            );
            $site = 'http://127.0.0.1:' . $port;
            for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
                usleep(100000);
            }

            $owner = $this->browser();
            list($status, $page) = $this->fetch($owner, $site . '/begin/register?set_name=' . rawurlencode('Fresh site'));
            $this->assertSame(200, $status, 'The first visitor should be offered the registration form: ' . substr(strip_tags($page), 0, 500));
            $this->assertStringContainsString('name="handle"', $page);

            preg_match('/name="__bTs" value="([^"]+)"/', $page, $ts);
            preg_match('/name="__bTk" value="([^"]+)"/', $page, $tk);
            list($status) = $this->fetch(
                $owner, $site . '/begin/register', [
                    '__bTs' => $ts[1], '__bTk' => $tk[1], '__bTa' => '/begin/register', 'handle' => 'owner', 'email' => 'owner@example.test',
                    'password' => 'correct horse battery', 'password2' => 'correct horse battery', 'set_name' => 'Fresh site',
                ]
            );
            $this->assertContains($status, [200, 302]);

            list($status, $page) = $this->fetch($owner, $site . '/admin/');
            $this->assertSame(200, $status, 'The first account should be an administrator, signed in.');
            list(, $page) = $this->fetch($owner, $site . '/');
            $this->assertStringContainsString('Fresh site', $page, 'The site has the name given to the installer.');

            // Nobody else can take the site afterwards: registration is closed
            list($status) = $this->fetch($this->browser(), $site . '/begin/register');
            $this->assertSame(403, $status, 'Once there is an administrator, a stranger may not register.');

            foreach ([$owner] as $handle) {
                curl_close($handle);
            }
        }

        private function browser()
        {
            $handle = curl_init();
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);

            return $handle;
        }

        private function fetch($handle, $url, ?array $post = null): array
        {
            curl_setopt($handle, CURLOPT_URL, $url);
            if ($post === null) {
                curl_setopt($handle, CURLOPT_HTTPGET, true);
            } else {
                curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
                curl_setopt($handle, CURLOPT_REFERER, $url);
            }
            $body = (string) curl_exec($handle);

            return [(int) curl_getinfo($handle, CURLINFO_HTTP_CODE), $body];
        }

        public function testWhenTheConfigurationCannotBeSavedItsContentsAreShownAndTheInstallerWaitsForTheFile()
        {
            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                $this->markTestSkipped('root may write anywhere');
            }
            chmod($this->install . '/configuration', 0555);

            $page = $this->request('POST', 'settings', $this->settings());
            $this->assertStringNotContainsString('REDIRECT', $page);
            $this->assertStringContainsString(htmlspecialchars($this->configFile()), $page, 'The page says where the file goes.');
            $this->assertFileDoesNotExist($this->configFile());
            $this->assertContains('entities', $this->tables(), 'The database is ready all the same.');

            // The contents to paste are a complete configuration
            $this->assertSame(1, preg_match('/<textarea[^>]*>(.*?)<\/textarea>/s', $page, $matches));
            $contents = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
            $pasted = $this->base . '/pasted.ini';
            file_put_contents($pasted, $contents);
            $config = parse_ini_file($pasted);
            $this->assertSame($this->database, $config['dbname']);
            $this->assertSame($this->uploads . '/', $config['uploadpath']);

            // Continuing before the file is there says so, and where it goes
            $page = $this->request('POST', 'finish', ['site_title' => 'Later']);
            $this->assertStringContainsString('There is no configuration file', $page);
            $this->assertStringContainsString(htmlspecialchars($this->configFile()), $page);

            // Once it is saved by hand, the installer moves on
            chmod($this->install . '/configuration', 0755);
            file_put_contents($this->configFile(), $contents);
            $this->assertSame('REDIRECT: ../begin/register?set_name=Later', $this->request('POST', 'finish', ['site_title' => 'Later']));
        }

        public function testADatabaseThatAlreadyHoldsASiteIsLeftAsItIs()
        {
            $client = $this->root($this->database);
            foreach (explode(";\n", file_get_contents(dirname(__DIR__, 2) . '/warmup/schemas/mysql/mysql.sql')) as $statement) {
                if (trim($statement) !== '') {
                    $client->exec($statement);
                }
            }
            // An older site, with a post in it
            $client->exec("update `versions` set `value` = '2020120301' where `label` = 'schema'");
            $client->exec("insert into `entities` (`uuid`, `_id`, `siteid`, `owner`, `entity_subtype`, `contents`) values ('http://example.com/view/1', '1', 's', '', 'x', '{}')");

            $this->assertStringStartsWith('REDIRECT: ../begin/register', $this->request('POST', 'settings', $this->settings()));

            $this->assertSame('2020120301', $client->query("select `value` from `versions` where `label` = 'schema'")->fetchColumn(), 'The schema version of an existing site must not be overwritten: its upgrades would be skipped.');
            $this->assertSame('1', (string) $client->query('select count(*) from `entities`')->fetchColumn());
        }

        public function testASchemaThatCannotBeLoadedLeavesNoTablesBehindAndSaysWhy()
        {
            // A table in the way that the schema's foreign keys can't refer to
            $client = $this->root($this->database);
            $client->exec('create table `config` (`something` int)');

            $page = $this->request('POST', 'settings', $this->settings());

            $this->assertStringContainsString('The database tables could not be created', $page);
            $this->assertStringContainsString('The database server said: SQLSTATE', $page, 'The server\'s own reason should be shown.');
            $this->assertSame(['config'], $this->tables(), 'Only the table that was there before should remain.');
            $this->assertFileDoesNotExist($this->configFile());
        }

        public function testTheCommandLineInstallerUsesTheSameChecksAndWritesTheSameFile()
        {
            $manifest = $this->base . '/manifest.ini';
            $write = function (array $settings) use ($manifest) {
                $lines = '';
                foreach ($settings + ['database' => 'MySQL'] as $name => $value) {
                    $lines .= $name . ' = ' . \Idno\Core\Installer::iniValue($value) . "\n";
                }
                file_put_contents($manifest, $lines);
            };
            $cli = function ($arguments) {
                $command = 'IDNO_INSTALL_PATH=' . escapeshellarg($this->install) . ' ' . escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 '
                    . escapeshellarg(dirname(__DIR__, 2) . '/warmup/CLI/CLIInstaller.php') . ' ' . $arguments . ' 2>&1';
                exec($command, $output, $status);

                return [$status, implode("\n", $output)];
            };

            // makeconfig: the same file, without touching anything
            $write($this->settings(['mysql_host' => 'db.example.com:3307', 'mysql_port' => '', 'mysql_pass' => self::PASSWORD]));
            list($status, $output) = $cli('makeconfig ' . escapeshellarg($manifest));
            $this->assertSame(0, $status, $output);
            file_put_contents($this->base . '/made.ini', $output);
            $made = parse_ini_file($this->base . '/made.ini');
            $this->assertSame('db.example.com', $made['dbhost']);
            $this->assertSame('3307', $made['dbport']);
            $this->assertSame(self::PASSWORD, $made['dbpass']);
            $this->assertSame($this->uploads . '/', $made['uploadpath']);
            $this->assertDirectoryDoesNotExist($this->uploads);

            // A wrong setting stops the installation with the web installer's words
            $write($this->settings(['mysql_pass' => 'not-the-password']));
            list($status, $output) = $cli('install config.ini ' . escapeshellarg($manifest));
            $this->assertSame(1, $status, $output);
            $this->assertStringContainsString('refused this user name and password', $output);
            $this->assertFileDoesNotExist($this->configFile());
            $this->assertSame([], $this->tables());

            // And a right one installs
            $write($this->settings());
            list($status, $output) = $cli('install config.ini ' . escapeshellarg($manifest));
            $this->assertSame(0, $status, $output);
            $config = parse_ini_file($this->configFile());
            $this->assertSame($this->database, $config['dbname']);
            $this->assertSame($this->settings()['mysql_port'], $config['dbport'] ?? '');
            $this->assertSame($this->uploads . '/', $config['uploadpath']);
            $this->assertContains('entities', $this->tables());

            // Installed: it refuses to run again
            list($status, $output) = $cli('install config.ini ' . escapeshellarg($manifest));
            $this->assertNotSame(0, $status);
            $this->assertStringContainsString('already installed', $output);
        }
    }
}
