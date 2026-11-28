<?php

namespace Tests\Core {

    /**
     * A configuration that reads its files from a folder of the test's, so that a test can say what
     * is in them.
     */
    class ConfigInFolder extends \Idno\Core\Config
    {
        public static $folder = '';

        protected function configurationPaths()
        {
            return [static::$folder];
        }
    }

    /**
     * Where the configuration takes its settings from: the environment and the file named after
     * the site's host.
     */
    class ConfigLoadingTest extends \PHPUnit\Framework\TestCase
    {
        private $server = [];
        private $domain = false;
        private $timezone = '';

        protected function setUp(): void
        {
            $this->server   = $_SERVER;
            $this->domain   = getenv('IDNO_DOMAIN');
            $this->timezone = date_default_timezone_get();

            ConfigInFolder::$folder = sys_get_temp_dir() . '/idno-config-' . bin2hex(random_bytes(6));
            mkdir(ConfigInFolder::$folder);

            // Nothing of the environment the tests run in is a setting of these configurations
            foreach (array_keys($_SERVER) as $name) {
                if (strpos($name, 'IDNO_') === 0 || strpos($name, 'KNOWN_') === 0) {
                    unset($_SERVER[$name]);
                }
            }
            $_SERVER['SERVER_NAME'] = 'example.com';
            $_SERVER['SERVER_PORT'] = 80;
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->server;
            putenv($this->domain === false ? 'IDNO_DOMAIN' : 'IDNO_DOMAIN=' . $this->domain);
            date_default_timezone_set($this->timezone);

            foreach (glob(ConfigInFolder::$folder . '/*') as $file) {
                unlink($file);
            }
            rmdir(ConfigInFolder::$folder);
        }

        private function file(string $name, string $contents): void
        {
            file_put_contents(ConfigInFolder::$folder . '/' . $name, $contents);
        }

        public static function wordsForOff(): array
        {
            return [['false'], ['False'], ['FALSE'], ['off'], ['Off'], ['no'], ['NO'], ['none']];
        }

        /**
         * A site whose operator set IDNO_DEBUG=false showed visitors error messages and stack
         * traces: the word was a text that is not empty, so the setting counted as on.
         *
         * @dataProvider wordsForOff
         */
        public function testASettingTurnedOffInTheEnvironmentIsOff(string $word)
        {
            $_SERVER['IDNO_DEBUG']          = $word;
            $_SERVER['KNOWN_OLDER_SETTING'] = $word;
            $this->file('config.ini', "from_file = {$word}\n");

            $config = new ConfigInFolder();

            $this->assertTrue(empty($config->from_file), "{$word} in config.ini is off");
            $this->assertTrue(empty($config->debug), "IDNO_DEBUG={$word} leaves debug off");
            $this->assertFalse($config->debug, 'Code that asks for exactly false is answered too');
            $this->assertFalse($config->older_setting, 'A KNOWN_ variable reads the same words');
        }

        public static function wordsForOn(): array
        {
            return [['true'], ['True'], ['on'], ['ON'], ['yes']];
        }

        /**
         * The words that turn a setting on in config.ini turn it on from the environment, as a
         * boolean, so that code comparing a setting with true or with "1" sees it as on.
         *
         * @dataProvider wordsForOn
         */
        public function testASettingTurnedOnInTheEnvironmentIsOn(string $word)
        {
            $_SERVER['IDNO_EXPERIMENTAL'] = $word;

            $config = new ConfigInFolder();

            $this->assertTrue($config->experimental);
        }

        public static function valuesThatAreNotWords(): array
        {
            return [['0'], ['1'], [''], ['2'], ['nothing'], ['yes please'], [' false'], ['null'], ['secret=no']];
        }

        /**
         * A password, a name or a number from the environment must reach the site as it was set:
         * only a value that is exactly one of the words is read as on or off.
         *
         * @dataProvider valuesThatAreNotWords
         */
        public function testAnyOtherValueFromTheEnvironmentIsKeptAsItIs(string $value)
        {
            $_SERVER['IDNO_SMTP_PASSWORD'] = $value;

            $config = new ConfigInFolder();

            $this->assertSame($value, $config->smtp_password);
        }

        /**
         * A site at www.example.com ignored configuration/example.com.ini, the name its plugins,
         * themes, database and uploads go by, and ran on the settings of config.ini alone.
         */
        public function testASiteAtWwwReadsTheFileNamedForItsHostWithoutWww()
        {
            $_SERVER['SERVER_NAME'] = 'www.example.com';
            $this->file('config.ini', "title = \"Shared\"\n");
            $this->file('example.com.ini', "title = \"Example\"\n");

            $config = new ConfigInFolder();

            $this->assertSame('Example', $config->title);
            $this->assertSame('example.com', $config->host);
        }

        /**
         * A site that already has a file named with www. goes on reading that file and nothing of
         * the other one, as it did before both names were looked for.
         */
        public function testAFileNamedWithWwwIsReadInsteadOfTheOneWithout()
        {
            $_SERVER['SERVER_NAME'] = 'www.example.com';
            $this->file('example.com.ini', "title = \"Without\"\ndescription = \"Only in the file without\"\n");
            $this->file('www.example.com.ini', "title = \"With\"\n");

            $config = new ConfigInFolder();

            $this->assertSame('With', $config->title);
            $this->assertNotSame('Only in the file without', $config->description);
        }

        /**
         * The queue worker and other commands learn the host from IDNO_DOMAIN, and must find the
         * same file as the web requests of that site, or they work on another database.
         */
        public function testACommandFindsTheSameFileAsTheSite()
        {
            unset($_SERVER['SERVER_NAME']);
            putenv('IDNO_DOMAIN=www.example.com');
            $this->file('example.com.ini', "title = \"Example\"\n");

            $config = new ConfigInFolder();

            $this->assertSame('Example', $config->title);
        }
    }

}
