<?php

namespace Idno\Core {

    /**
     * Installation tools for installing Idno and validating requirements.
     */
    abstract class Installer
    {

        protected $root_path;

        public function __construct()
        {
            $this->root_path = dirname(dirname(dirname(__FILE__)));
        }

        /**
         * Is known installed (warmup complete - configuration correct)
         */
        public function isInstalled()
        {

            foreach ([
                $this->root_path . '/config.ini',
                $this->root_path . '/configuration/config.ini'
            ] as $location) {
                if (file_exists($location)) {
                    if ($config = @parse_ini_file($location)) {
                        if (!empty($config)) {
                            return true;
                        }
                    }
                }
            }

            return false;
        }

        /**
         * Run installation
         */
        abstract public function run();

        /**
         * When given a file, create a backup of it.
         *
         * @param type $file
         */
        protected function backupFile($file)
        {

            if (file_exists($file)) {

                $datepart = date('Ymd');
                $versionpart = 1;

                do {
                    $version = $datepart . sprintf('%02d', $versionpart);
                    $newname = "$file.$version.bak";
                    $versionpart ++;
                } while (file_exists($newname));

                @copy($file, $newname); // Create a backup

            }
        }

        /**
         * Check that the upload directory exists and is readable and writable
         *
         * @param  type $upload_path
         * @return boolean
         * @throws \RuntimeException
         */
        protected function checkUploadDirectory($upload_path)
        {
            if (file_exists($upload_path) && is_dir($upload_path)) {
                if (!is_readable($upload_path)) {
                    throw new \RuntimeException('We can\'t read data from ' . htmlspecialchars($upload_path) . ' - please check permissions and try again.');
                }
                if (!is_writable($upload_path)) {
                    throw new \RuntimeException('We can\'t write data to ' . htmlspecialchars($upload_path) . ' - please check permissions and try again.');
                }
            } else {
                throw new \RuntimeException('The upload path ' . htmlspecialchars($upload_path) . ' either doesn\'t exist or isn\'t a directory.');
            }

            return true;
        }

        /**
         * Write an apache config
         *
         * @todo Necessary?
         * @todo Handle nginx & Apache 2.4?
         */
        protected function writeApacheConfig()
        {

            $begin_mark = "## BEGIN Idno Webserver Config (don't remove)";
            $end_mark = "## END Idno Webserver Config (don't remove)";

            if (file_exists($this->root_path . '/.htaccess')) {
                if (is_writable($this->root_path . '/.htaccess')) {

                    $this->backupFile($this->root_path . '/.htaccess'); // Create a backup of the old file, for safety

                    $in2 = fopen($this->root_path . '/warmup/webserver-configs/htaccess.dist', 'r');
                    $in = fopen($this->root_path . '/.htaccess', 'r');

                    $out = "";

                    $ismerging = false;

                    while ($line = fgets($in)) {

                        if (strpos($line, $begin_mark) !== false) {
                            $ismerging = true;
                        }

                        // Merging or not, we need to write
                        if ($ismerging) {

                            while ($mergedline = fgets($in2)) {
                                $out .= $mergedline;
                            }

                            do { // fast forward merging file to end of merge
                                $line = fgets($in);
                            } while (!feof($in) && (strpos($line, $end_mark) === false));

                            $ismerging = false;
                        } else {
                            $out .= $line;
                        }

                    }

                    file_put_contents($this->root_path . '/.htaccess', $out);

                    fclose($in);
                    fclose($in2);

                }

            } else {
                @copy($this->root_path . '/warmup/webserver-configs/htaccess.dist', $this->root_path . '/.htaccess');
            }
        }

        /**
         * Write out the configuration
         *
         * @param  type $ini_file
         * @param  type $name
         * @throws \RuntimeException
         */
        protected function writeConfig($ini_file, $name = 'config.ini')
        {

            $this->backupFile($this->root_path. '/configuration/' . $name); // Create a backup of the existing file, if any.

            if ($fp = @fopen($this->root_path. '/configuration/' . $name, 'w')) {

                fwrite($fp, $ini_file);
                fclose($fp);

            } else {
                throw new \RuntimeException("Could not write config file");
            }

        }

        /**
         * Generate a configuration file from a template.
         *
         * @param  array $params Name => Value array of configuration values, e.g. "dbname" => "known"
         * @return string The built ini file
         */
        protected function buildConfig(array $params = [])
        {

            // Set some defaults
            $defaults = [
                'build' => Version::build(),
                'version' => Version::version(),
                'datetime' => date('r'),

                'database' => 'MySQL',
                'dbname' => 'known',
                'dbhost' => 'localhost',

                'filesystem' => 'local',
                'uploadpath' => $this->root_path . '/Uploads/'
            ];

            // Merge parameters into defaults
            $params = array_merge($defaults, $params);

            // Load template
            $template = file_get_contents($this->root_path . '/warmup/webserver-configs/config.ini.template');
            if (empty($template)) {
                throw new \Idno\Exceptions\ConfigurationException('Configuration template could not be loaded.');
            }

            // Build config output
            foreach ($params as $name => $value) {
                $template = str_replace("%{$name}%", $value, $template);
            }

            return $template;
        }

        /**
         * The kind of database a person chose: `sqlite` for "sqlite", "SQLite" or "sqlite3" (with
         * spaces around it), `mysql` for anything else (MySQL and MariaDB).
         */
        protected static function databaseType(string $database): string
        {
            return in_array(strtolower(trim($database)), ['sqlite', 'sqlite3'], true) ? 'sqlite' : 'mysql';
        }

        /**
         * Whether a PHP module is loaded. Overridden by tests.
         */
        protected function moduleLoaded(string $module): bool
        {
            return extension_loaded($module);
        }

        /**
         * Where an SQLite site's database file is when no path is given.
         */
        protected function defaultSqlitePath(): string
        {
            return $this->root_path . '/data/idno.sqlite';
        }

        /**
         * The absolute path of an SQLite database file: the default for no path, a relative path
         * under the project directory (as the site reads it), an absolute one as it is. A path that
         * cannot be written in the single quotes of a configuration file is refused.
         *
         * @throws \Idno\Exceptions\ConfigurationException
         */
        protected function sqlitePath(string $path): string
        {
            $path = trim($path);
            if ($path === '') {
                return $this->defaultSqlitePath();
            }
            if (preg_match('/[\'\x00-\x1f\x7f]/', $path) === 1) {
                throw new \Idno\Exceptions\ConfigurationException('The path of the database file cannot contain a single quote or a control character.');
            }
            if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('#^[A-Za-z]:#', $path) === 1) {
                return $path;
            }

            return $this->root_path . '/' . $path;
        }

        /**
         * Check the chosen database and return the values `buildConfig()` takes for it. It loads no
         * SQL: the schema (the tables and their indexes) is created by the upgrade, on the site's
         * first page load in a browser or by the command `php bin/console idno:upgrade`.
         *
         * For MySQL and MariaDB the input is `name`, `user`, `pass` and `host`, and the database is
         * connected to. For SQLite it is `path` (the default is data/idno.sqlite under the project;
         * a relative one is under the project too): the directory and an empty file are created when
         * missing, both must be writable, and the file must open as an SQLite database (an existing
         * file that does not is refused and left as it is). The value written for SQLite is exactly
         * `sqlite`: `Sqlite3` in a configuration means MySQL.
         *
         * @param  string               $database `mysql` or `sqlite` (see databaseType())
         * @param  array<string, mixed> $input
         * @return array<string, string> database, dbname, dbuser, dbpass and dbhost
         * @throws \Idno\Exceptions\ConfigurationException with the message to show
         */
        protected function databaseParams(string $database, array $input): array
        {
            if (self::databaseType($database) === 'sqlite') {
                return $this->sqliteParams((string) ($input['path'] ?? ''));
            }

            $name = (string) ($input['name'] ?? '');
            $user = (string) ($input['user'] ?? '');
            $pass = (string) ($input['pass'] ?? '');
            $host = (string) ($input['host'] ?? '');
            try {
                $this->installSchema($host, $name, $user, $pass);
            } catch (\PDOException $e) {
                throw new \Idno\Exceptions\ConfigurationException('We couldn\'t connect to your database. Please check your settings and try again. Here\'s the error we got: ' . $e->getMessage(), 0, $e);
            }

            return [
                'database' => 'MySQL',
                'dbname' => $name,
                'dbuser' => $user,
                'dbpass' => $pass,
                'dbhost' => $host,
            ];
        }

        /**
         * @return array<string, string>
         * @throws \Idno\Exceptions\ConfigurationException
         */
        private function sqliteParams(string $input): array
        {
            if (!$this->moduleLoaded('pdo_sqlite')) {
                throw new \Idno\Exceptions\ConfigurationException('SQLite needs the pdo_sqlite extension for PHP, which is not installed.');
            }

            $path = $this->sqlitePath($input);
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \Idno\Exceptions\ConfigurationException('We couldn\'t create the directory ' . $directory . ' for the database file - please check permissions and try again.');
            }
            if (!is_writable($directory)) {
                throw new \Idno\Exceptions\ConfigurationException('We can\'t write data to ' . $directory . ' - SQLite keeps its database and its journal files there. Please check permissions and try again.');
            }
            if (is_dir($path)) {
                throw new \Idno\Exceptions\ConfigurationException($path . ' is a directory, not a database file.');
            }
            if (file_exists($path)) {
                if (!is_readable($path) || !is_writable($path)) {
                    throw new \Idno\Exceptions\ConfigurationException('We can\'t read and write ' . $path . ' - please check permissions and try again.');
                }
            } elseif (@file_put_contents($path, '') === false) {
                throw new \Idno\Exceptions\ConfigurationException('We couldn\'t create the database file ' . $path . ' - please check permissions and try again.');
            }

            // Open it as the site will (SqliteConnectionSetup refuses SQLite below 3.38).
            $connection = null;
            try {
                $connection = \Doctrine\DBAL\DriverManager::getConnection(
                    ['driver' => 'pdo_sqlite', 'path' => $path],
                    (new \Doctrine\DBAL\Configuration())->setMiddlewares([new \Idno\Storage\Platform\SqliteConnectionSetup()])
                );
                $connection->executeQuery('select count(*) from sqlite_master')->fetchOne();
            } catch (\Throwable $e) {
                throw new \Idno\Exceptions\ConfigurationException('We couldn\'t open ' . $path . ' as an SQLite database. Here\'s the error we got: ' . $e->getMessage(), 0, $e);
            } finally {
                $connection?->close();
            }

            return [
                'database' => 'sqlite',
                'dbname' => $path,
                'dbuser' => '',
                'dbpass' => '',
                'dbhost' => '',
            ];
        }

        /**
         * Check that the database can be connected to. It loads no SQL: the schema (the tables and
         * their indexes) is created by the upgrade, on the site's first page load in a browser or by
         * the command `php bin/console idno:upgrade`.
         *
         * @param  type $host
         * @param  type $dbname
         * @param  type $user
         * @param  type $pass
         * @param  type $schema
         * @return boolean
         * @throws \PDOException when the database cannot be connected to
         */
        protected function installSchema(
            $host,
            $dbname,
            $user,
            $pass,
            $schema = 'mysql'
        )
        {

            $dbname = preg_replace("/[^a-zA-Z0-9\_\.]/", "", $dbname); // Sanitise $dbname
            $schema = preg_replace("/[^a-zA-Z0-9\_\.]/", "", strtolower($schema)); // Sanitise $schema

            // Skip the check for mongo, which has no schema
            if ($schema == 'mongo' || $schema == 'mongodb') {
                return true;
            }

            // Crufty hack to alias schemas if they're different from class. TODO: do this nicer
            $dbscheme = "";
            switch ($schema) {

                case 'sqlite3' : $dbscheme = 'sqlite';
                    break;
                case 'postgres': $dbscheme = 'pgsql';
                    break;
                default:
                    $dbscheme = $schema;
            }

            $database_string = $dbscheme . ':';
            $database_string .= 'host=' . $host . ';';
            $database_string .= 'dbname=' . $dbname;

            $dbh = new \PDO($database_string, $user, $pass);
            $dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            return true;
        }

        /**
         * Return whether mod rewrite is available
         */
        public static function rewriteAvailable()
        {
            $modules = apache_get_modules();
            if (in_array('mod_rewrite', apache_get_modules())) {
                return true;
            } else {
                return false;
            }
        }

        /**
         * Check the PHP version: 8.4 or later, as index.php and composer.json require.
         *
         * @param  string|null $version The version to check; the running PHP's by default.
         * @return 'ok','fail','warn'
         */
        public static function checkPHPVersion($version = null)
        {
            if (version_compare($version ?? phpversion(), '8.4') >= 0) {
                return 'ok';
            } else {
                return 'fail';
            }
        }

        /**
         * The PHP modules for the databases Idno can use: at least one of them is required.
         *
         * @return string[]
         */
        public static function databaseModules(): array
        {
            return ['pdo_mysql', 'pdo_sqlite'];
        }

        /**
         * Whether at least one of the databaseModules() is loaded.
         *
         * @param callable|null $loaded Takes a module name, says whether it is loaded (extension_loaded() by default)
         */
        public static function databaseModuleAvailable(?callable $loaded = null): bool
        {
            $loaded ??= 'extension_loaded';
            foreach (self::databaseModules() as $module) {
                if ($loaded($module)) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Return the required modules (not the database ones: see databaseModules())
         */
        public static function requiredModules()
        {
            return [
                'curl',
                'date',
                'dom',
                'gd',
                'json',
                'libxml',
                'mbstring',
                'pdo',
                'reflection',
                'session',
                'simplexml',
                'openssl',
                'gettext',
            ];
        }

    }

}
