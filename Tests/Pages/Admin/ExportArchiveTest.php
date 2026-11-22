<?php

namespace Tests\Pages\Admin {

    use Idno\Core\Config;
    use Idno\Core\Idno;
    use Idno\Core\Migration;
    use Idno\Entities\User;
    use Idno\Pages\Admin\Export\Generate;

    /**
     * The archive made by the site export holds everything on the site, the users' password
     * hashes included. It is stored as a file like any upload, so the file page must serve it
     * to administrators only, and must never let a shared cache keep it. The copy of the
     * configuration inside it holds the settings saved in the database only, without the
     * secrets among them.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class ExportArchiveTest extends \Tests\IdnoTestCase
    {

        /// What the stored archives contain
        const CONTENTS = 'PK export archive test: this stands for a whole site';

        /// @var User A site administrator
        private static $admin;

        /// @var User A user who is not one
        private static $member;

        private static $password;

        /// Files holding the cookies of each user's browser, by handle
        private static $cookies = [];

        /// IDs of the files the tests stored
        private $files = [];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            static::$password = bin2hex(random_bytes(12));
            static::$admin = static::createUser('exportadmin', true);
            static::$member = static::createUser('exportmember', false);

            // Local file storage lives in a directory named after the site's host, and the web
            // server can know the site by a different host name than this process does. Pin the
            // directory name in the saved configuration so both look in the same place.
            Idno::site()->config()->file_path_host = Idno::site()->config()->pathHost();
            Idno::site()->config()->save();
        }

        public static function tearDownAfterClass(): void
        {
            foreach ([static::$admin, static::$member] as $user) {
                if ($user) {
                    $user->delete();
                }
            }
            static::$admin = static::$member = null;
            foreach (static::$cookies as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            static::$cookies = [];

            Idno::site()->config()->file_path_host = null;
            Idno::site()->config()->save();

            parent::tearDownAfterClass();
        }

        protected function tearDown(): void
        {
            foreach ($this->files as $id) {
                if ($file = Idno::site()->filesystem()->findOne($id)) {
                    $file->delete();
                }
            }
            $this->files = [];

            $config = Idno::site()->config();
            if (!empty($config->export_file_id) || !empty($config->smtp_password) || !empty($config->exporttest_api_key)) {
                unset($config->config['dbauthsrc']);
                $config->export_file_id = false;
                $config->export_filename = false;
                $config->smtp_password = '';
                $config->exporttest_api_key = '';
                $config->exporttest = [];
                $config->save();
            }
        }

        // -----------------------------------------------------------------
        // Who is served the archive
        // -----------------------------------------------------------------

        public function testArchiveIsNotServedToAVisitor()
        {
            $id = $this->storeArchive();

            $result = $this->request('file/' . $id . '/site.zip');

            $this->assertEquals(403, $result['status'], 'The export archive must not be served to a visitor who is not logged in.');
            $this->assertStringNotContainsString(self::CONTENTS, $result['body']);
            $this->assertNotCacheable($result);
        }

        public function testArchiveIsNotServedInPartToAVisitor()
        {
            $id = $this->storeArchive();

            $result = $this->request('file/' . $id, null, ['Range: bytes=0-9']);

            $this->assertEquals(403, $result['status']);
            $this->assertStringNotContainsString(substr(self::CONTENTS, 0, 10), $result['body']);
        }

        public function testArchiveIsNotServedToAUserWhoIsNotAnAdministrator()
        {
            $id = $this->storeArchive();

            $result = $this->request('file/' . $id . '/site.zip', static::$member);

            $this->assertEquals(403, $result['status'], 'The export archive must not be served to a user who is not an administrator.');
            $this->assertStringNotContainsString(self::CONTENTS, $result['body']);
        }

        public function testArchiveIsServedToAnAdministratorAndNotCached()
        {
            $id = $this->storeArchive();

            $result = $this->request('file/' . $id . '/site.zip', static::$admin);

            $this->assertEquals(200, $result['status'], 'An administrator should be able to download the export archive.');
            $this->assertEquals(self::CONTENTS, $result['body']);
            $this->assertMatchesRegularExpression('/^Cache-Control: private, no-store\s*$/mi', $result['headers'], 'No cache may keep the export archive.');
            $this->assertNotCacheable($result);
            $this->assertMatchesRegularExpression('/^Content-Disposition: attachment/mi', $result['headers']);
        }

        public function testArchiveStaysClosedWhenTheSiteNoLongerRemembersIt()
        {
            // The mark on the file itself is enough: the setting that names the current
            // archive is cleared when the next export starts, or can be lost
            $id = $this->storeArchive();
            Idno::site()->config()->export_file_id = false;
            Idno::site()->config()->save();

            $result = $this->request('file/' . $id . '/site.zip');

            $this->assertEquals(403, $result['status']);
            $this->assertStringNotContainsString(self::CONTENTS, $result['body']);
        }

        public function testArchiveStoredByAnEarlierVersionIsNotServedToAVisitor()
        {
            // Earlier versions stored the archive as an ordinary file, with no mark. The site
            // remembers which file it is.
            $path = $this->archiveOnDisk();
            $id = \Idno\Entities\File::createFromFile($path, 'site.zip');
            unlink($path);
            $this->assertNotEmpty($id, 'The file should have been stored.');
            $this->files[] = $id;
            Idno::site()->config()->export_filename = 'site.zip';
            Idno::site()->config()->export_file_id = $id;
            Idno::site()->config()->save();

            $result = $this->request('file/' . $id . '/site.zip');

            $this->assertEquals(403, $result['status'], 'An archive stored before archives were marked must not be served to a visitor either.');
            $this->assertStringNotContainsString(self::CONTENTS, $result['body']);
            $this->assertNotCacheable($result);

            $result = $this->request('file/' . $id . '/site.zip', static::$admin);

            $this->assertEquals(200, $result['status']);
            $this->assertEquals(self::CONTENTS, $result['body']);
            $this->assertMatchesRegularExpression('/^Cache-Control: private, no-store\s*$/mi', $result['headers']);
        }

        public function testAnOrdinaryFileIsStillPublic()
        {
            $id = Idno::site()->filesystem()->storeContent('an ordinary upload', ['filename' => 'ordinary.txt', 'mime_type' => 'text/plain']);
            $this->files[] = $id;
            $this->storeArchive();

            $result = $this->request('file/' . $id . '/ordinary.txt');

            $this->assertEquals(200, $result['status']);
            $this->assertEquals('an ordinary upload', $result['body']);
            $this->assertMatchesRegularExpression('/^Cache-Control: public\s*$/mi', $result['headers']);
        }

        // -----------------------------------------------------------------
        // What the archive says about the configuration
        // -----------------------------------------------------------------

        public function testExportedConfigurationHasNoBootConfigurationAndNoSecrets()
        {
            $config = Idno::site()->config();
            $config->smtp_password = 'exporttest-smtp-' . md5(rand());
            $config->exporttest_api_key = 'exporttest-key-' . md5(rand());
            $config->exporttest = ['account' => 'someone', 'client_secret' => 'exporttest-nested-' . md5(rand())];
            // Server settings can reach the saved record without being in the configuration
            // file: from the environment, or just by having been read
            $config->dbauthsrc = 'exporttest-authsrc';
            $config->dbport = $config->dbport ?: 'exporttest-port';
            $config->save();
            $secrets = [$config->smtp_password, $config->exporttest_api_key, $config->exporttest['client_secret'], $config->site_secret];
            $this->assertNotEmpty($config->site_secret);

            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'idno-export-test-' . md5(rand() . microtime(true));
            mkdir($dir);
            try {
                $path = Migration::exportToFolder($dir);
                $this->assertNotEmpty($path, 'The export should have been written.');
                $json = file_get_contents($path . DIRECTORY_SEPARATOR . 'config.json');
            } finally {
                Migration::cleanUpFolder($dir);
            }
            $exported = json_decode($json, true);

            $this->assertIsArray($exported);
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $json, 'The exported configuration must hold no secret.');
            }
            foreach (['ini_config', 'config', 'database', 'dbpass', 'dbuser', 'dbhost', 'dbport', 'dbname', 'dbauthsrc', 'uploadpath', 'path'] as $name) {
                $this->assertArrayNotHasKey($name, $exported, "The exported configuration must hold the saved settings only, not \"$name\" from the site's configuration file.");
            }
            // Only sites that keep uploads on disk have an upload path (not MongoDB's GridFS)
            $uploadPath = rtrim((string) $config->uploadpath, '/');
            if ($uploadPath !== '') {
                $this->assertStringNotContainsString($uploadPath, str_replace('\\/', '/', $json), 'The exported configuration must not say where on the server uploads are kept.');
            }

            // The saved settings are there
            $this->assertEquals($config->title, $exported['title'] ?? null);
            $this->assertEquals('someone', $exported['exporttest']['account'] ?? null);
            $this->assertEquals(Config::REDACTED, $exported['smtp_password'] ?? null, 'A secret is replaced, so that the archive shows it was set.');
        }

        public function testDiagnosticsAndExportAgreeOnWhatIsSecret()
        {
            $settings = [
                'title' => 'A site',
                'dbpass' => 'hidden-01',
                'site_secret' => 'hidden-02',
                'smtp_password' => 'hidden-03',
                'aws_key' => 'hidden-04',
                'aws_secret' => 'hidden-05',
                'someplugin_api_token' => 'hidden-06',
                'someplugin' => ['account' => 'someone', 'private_key' => 'hidden-07', 'credentials' => ['a' => 'hidden-08']],
                'dbstring' => 'mongodb://someone:hidden-09@db.example.com:27017',
                'proxy_string' => 'someone:hidden-10@proxy.example.com:3128',
                'hub' => 'https://someone:hidden-11@hub.example.com/',
                'smtp_host' => 'mail.example.com',
                'empty_password' => '',
                'form_token_expiry' => 3600,
            ];

            $redacted = Config::redactSecrets($settings);

            $this->assertStringNotContainsString('hidden-', json_encode($redacted), 'Every secret should have been removed.');
            $this->assertEquals('A site', $redacted['title']);
            $this->assertEquals('mail.example.com', $redacted['smtp_host']);
            $this->assertEquals('someone', $redacted['someplugin']['account']);
            $this->assertEquals('https://' . Config::REDACTED . '@hub.example.com/', $redacted['hub']);
            $this->assertSame('', $redacted['empty_password'], 'A secret that is not set is shown as not set.');
            $this->assertEquals(Config::REDACTED, $redacted['dbpass']);
            $this->assertEquals(3600, $redacted['form_token_expiry'], 'How long a token lasts is not a secret.');

            // The diagnostics report and the export are both made with this function
            foreach (['/Idno/Pages/Admin/Diagnostics.php', '/Idno/Core/Migration.php'] as $file) {
                $this->assertStringContainsString('redactSecrets(', file_get_contents(dirname(__DIR__, 3) . $file));
            }
        }

        public function testRecordDumpIsACompleteCopyOfTheSite()
        {
            // Not a defect, but worth knowing: the archive is the administrator's copy of
            // the whole site, so its dump of records holds every user's password hash. That is
            // why the archive itself must be served to administrators only.
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'idno-export-test-' . md5(rand() . microtime(true));
            mkdir($dir);
            try {
                $path = Migration::exportToFolder($dir);
                $dump = implode('', array_map('file_get_contents', glob($path . DIRECTORY_SEPARATOR . 'exported_data.*')));
            } finally {
                Migration::cleanUpFolder($dir);
            }

            $this->assertStringContainsString(static::$member->getHandle(), $dump);
            $this->assertStringContainsString(static::$member->password, str_replace('\\', '', $dump));
        }

        // -----------------------------------------------------------------
        // Helpers
        // -----------------------------------------------------------------

        private static function createUser($prefix, $admin)
        {
            $user = new User();
            $user->handle = $prefix . substr(md5(rand() . microtime(true)), 0, 8);
            $user->email = $user->handle . '@example.com';
            $user->setPassword(static::$password);
            $user->setTitle('Export Test User');
            if ($admin) {
                $user->setAdmin(true);
            }
            $user->save(true);

            return $user;
        }

        private function archiveOnDisk()
        {
            $path = tempnam(sys_get_temp_dir(), 'idno-export-test-');
            file_put_contents($path, self::CONTENTS);

            return $path;
        }

        /**
         * Store an archive as the export does, and have the site remember it
         */
        private function storeArchive()
        {
            $path = $this->archiveOnDisk();
            $id = Generate::storeArchive($path, 'site.zip');
            unlink($path);
            $this->assertNotEmpty($id, 'The archive should have been stored.');
            $this->files[] = $id;

            Idno::site()->config()->export_filename = 'site.zip';
            Idno::site()->config()->export_file_id = $id;
            Idno::site()->config()->save();

            return $id;
        }

        private function assertNotCacheable(array $result)
        {
            $this->assertDoesNotMatchRegularExpression('/^Cache-Control:.*public/mi', $result['headers'], 'The answer must not be marked as cacheable by anyone.');
            $this->assertDoesNotMatchRegularExpression('/^Pragma: public/mi', $result['headers']);
            if (preg_match('/^Expires: (.*)$/mi', $result['headers'], $match)) {
                $this->assertLessThanOrEqual(time(), strtotime(trim($match[1])), 'The answer must not be given a lifetime.');
            }
        }

        /**
         * Make a GET request, as a visitor or as the browser of a logged-in user.
         *
         * @param  string    $path    Path below the site's address
         * @param  User|null $user    The user whose browser makes the request, if any
         * @param  string[]  $headers
         * @return array 'status', 'headers' and 'body'
         */
        private function request($path, $user = null, array $headers = [])
        {
            $cookies = null;
            if ($user) {
                $cookies = $this->logIn($user);
            }

            return $this->send('GET', $path, null, $headers, $cookies);
        }

        private function send($method, $path, $body, array $headers, $cookies)
        {
            $curl = curl_init(Idno::site()->config()->getDisplayURL() . $path);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            if ($cookies) {
                curl_setopt($curl, CURLOPT_COOKIEFILE, $cookies);
                curl_setopt($curl, CURLOPT_COOKIEJAR, $cookies);
            }
            if ($body !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body));
            }
            if ($headers) {
                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
            }
            $response = (string) curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            unset($curl); // Writes the cookie jar

            return ['status' => $status, 'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize)];
        }

        /**
         * Log a user's browser in through the login form, unless it already is.
         *
         * @return string The file that holds the browser's cookies
         */
        private function logIn(User $user)
        {
            $handle = $user->getHandle();
            if (empty(static::$cookies[$handle])) {
                static::$cookies[$handle] = tempnam(sys_get_temp_dir(), 'idno-export-test-cookies-');
            }
            $cookies = static::$cookies[$handle];

            if ($this->send('GET', 'account/settings/', null, [], $cookies)['status'] == 200) {
                return $cookies;
            }

            $form = $this->send('GET', 'session/login', null, [], $cookies);
            $fields = [];
            foreach (['__bTs', '__bTk', '__bTa'] as $name) {
                if (preg_match('/name="' . $name . '" value="([^"]*)"/', $form['body'], $match)) {
                    $fields[$name] = html_entity_decode($match[1]);
                }
            }
            $this->assertCount(3, $fields, 'The login page should have a form token. If this is failing, you may need to set IDNO_DOMAIN.');

            $this->send('POST', 'session/login', $fields + ['email' => $handle, 'password' => static::$password], [], $cookies);
            $this->assertEquals(200, $this->send('GET', 'account/settings/', null, [], $cookies)['status'], 'The test user should be able to log in.');

            return $cookies;
        }
    }

}
