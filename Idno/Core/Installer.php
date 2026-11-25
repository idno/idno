<?php

namespace Idno\Core {

    /**
     * Installation tools for installing Idno and validating requirements.
     */
    abstract class Installer
    {

        /// Where Idno's code is: the schema, the configuration template, the sample web server files
        protected $root_path;

        /// Where the installation is written: configuration/config.ini and .htaccess
        protected $install_path;

        /// The permissions of the configuration file the installer saves; null leaves them to the umask
        protected $config_mode = null;

        /// The fields of the settings form, which are also the keys of a CLI manifest
        const FIELDS = ['site_title', 'database', 'mysql_name', 'mysql_user', 'mysql_pass', 'mysql_host', 'mysql_port', 'upload_path'];

        /**
         * @param string|null $install_path Where to write the installation. The folder of the
         *                                  code unless a test installs somewhere else.
         */
        public function __construct($install_path = null)
        {
            $this->root_path = dirname(dirname(dirname(__FILE__)));
            $this->install_path = $install_path ? rtrim($install_path, '/\\') : $this->root_path;
        }

        /**
         * The address of the web installer, from the address of the script that is serving
         * the request (index.php, which the web server runs for every page). It is beside
         * that script, so a request for /some/page is not sent to /some/warmup/.
         *
         * @param  string $script_name $_SERVER['SCRIPT_NAME']
         * @return string
         */
        public static function address($script_name)
        {
            $base = rtrim(dirname('/' . ltrim(str_replace('\\', '/', (string) $script_name), '/')), '/');
            if (!preg_match('#^(/[A-Za-z0-9._~-]+)*$#', $base)) {
                $base = '';
            }

            return $base . '/warmup/';
        }

        /**
         * Is Idno installed (warmup complete - configuration correct)
         */
        public function isInstalled()
        {

            foreach ([
                $this->install_path . '/config.ini',
                $this->install_path . '/configuration/config.ini'
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
         * An error of the installer: plain text for the person installing, and the shell
         * commands that would fix it, if any. Nothing in it is HTML; whoever prints it escapes it.
         *
         * @param  string   $text
         * @param  string[] $commands
         * @return array
         */
        protected static function problem($text, array $commands = [])
        {
            return ['text' => $text, 'commands' => $commands];
        }

        /**
         * Read the database server out of what was typed: a name or address, optionally with
         * the port after a colon ("db.example.com:3307", "[::1]:3307"), and the port field.
         *
         * A path to a socket file is refused: the MySQL back end connects by host and port
         * only. With the name "localhost" PHP uses the server's default socket by itself.
         *
         * @param  string $host
         * @param  string $port
         * @return array 'host', 'port' (a string of digits, or '' for the default, 3306),
         *               'error' ('' or what is wrong, in plain words) and 'field' (the field
         *               of the form the error is about)
         */
        public static function parseDatabaseHost($host, $port = '')
        {
            $host = trim((string) $host);
            $port = trim((string) $port);
            $result = ['host' => $host, 'port' => $port, 'error' => '', 'field' => 'mysql_host'];

            if ($host === '') {
                $result['error'] = 'Enter the name or address of the database server. If the database is on the same machine as the site, that is usually localhost.';

                return $result;
            }
            if (strpos($host, '/') !== false || stripos($host, 'unix:') === 0) {
                $result['error'] = 'A path to a socket file can\'t be used here. Enter localhost to use the server\'s default socket, or 127.0.0.1 and the port.';

                return $result;
            }

            $port_in_host = '';
            if (preg_match('/^\[([0-9a-fA-F:.]+)\](?::([0-9]+))?$/', $host, $matches)) {
                // An IPv6 address in brackets, with or without a port
                $host = $matches[1];
                $port_in_host = $matches[2] ?? '';
            } else if (substr_count($host, ':') === 1) {
                list($host, $port_in_host) = explode(':', $host);
            }

            if ($port_in_host !== '') {
                if ($port !== '' && $port !== $port_in_host) {
                    $result['field'] = 'mysql_port';
                    $result['error'] = 'The server name ends with the port ' . $port_in_host . ', but the port field says ' . $port . '. Give the port once.';

                    return $result;
                }
                $port = $port_in_host;
            }

            if (!preg_match('/^[A-Za-z0-9._-]+$/', $host) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $result['error'] = 'That doesn\'t look like the name or address of a server. Use a name such as localhost or db.example.com, or an IP address.';

                return $result;
            }
            if ($port !== '' && (!preg_match('/^[0-9]{1,5}$/', $port) || (int) $port < 1 || (int) $port > 65535)) {
                $result['field'] = 'mysql_port';
                $result['error'] = 'The port must be a number from 1 to 65535. Leave it empty for the usual port, 3306.';

                return $result;
            }

            $result['host'] = $host;
            $result['port'] = $port === '' ? '' : (string) (int) $port;

            return $result;
        }

        /**
         * Write a value for config.ini so that parse_ini_file() reads back exactly that value,
         * whatever it holds: quotes, $, #, ;, = or spaces. No value can end its own setting or
         * start another.
         *
         * Text goes in single quotes, where the ini format reads everything as it stands; a
         * single quote itself goes in double quotes, and the pieces are written side by side,
         * which the ini format joins.
         *
         * @param  string $value
         * @return string
         * @throws \InvalidArgumentException if the value holds a line break or another control character
         */
        public static function iniValue($value)
        {
            $value = (string) $value;
            if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new \InvalidArgumentException('A setting can\'t hold a line break or another control character.');
            }
            if ($value === '') {
                return "''";
            }

            $out = '';
            foreach (preg_split("/('+)/", $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
                $out .= $piece[0] === "'" ? '"' . $piece . '"' : "'" . $piece . "'";
            }

            return $out;
        }

        /**
         * The name of the user PHP runs as: the user that must be able to write to the upload
         * folder and, for the installer to save it, to the configuration folder.
         *
         * @return string Empty when it can't be found out
         */
        public static function serverUser()
        {
            if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
                $user = @posix_getpwuid(posix_geteuid());
                if (!empty($user['name'])) {
                    return $user['name'];
                }
            }

            return '';
        }

        /**
         * The upload folder as typed, made into one form: no space around it, one slash at the end.
         *
         * @param  string $path
         * @return string
         */
        public static function normaliseUploadPath($path)
        {
            $path = trim((string) $path);
            if ($path === '') {
                return '';
            }

            return rtrim($path, '/\\') . '/';
        }

        /**
         * The default upload folder: Uploads in the installation, which the shipped .htaccess
         * and the sample nginx configuration close to visitors.
         */
        public function defaultUploadPath()
        {
            return $this->install_path . '/Uploads/';
        }

        /**
         * Check what was typed into the settings form (or a CLI manifest) without touching
         * the file system or the database.
         *
         * @param  array $input Values by field name (see FIELDS)
         * @return array 'values' (every field, tidied; the password as typed) and 'errors'
         *               (field name => problem)
         */
        public function normaliseSettings(array $input)
        {
            $values = [];
            foreach (self::FIELDS as $field) {
                $values[$field] = isset($input[$field]) && is_scalar($input[$field]) ? (string) $input[$field] : '';
                if ($field !== 'mysql_pass') {
                    $values[$field] = trim($values[$field]);
                }
            }
            if ($values['database'] === '') {
                $values['database'] = 'MySQL';
            }
            $errors = [];

            foreach (self::FIELDS as $field) {
                if (preg_match('/[\x00-\x1F\x7F]/', $values[$field])) {
                    $errors[$field] = self::problem('This can\'t hold a line break or another control character.');
                    if ($field !== 'mysql_pass') {
                        $values[$field] = '';
                    }
                }
            }

            if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $values['database'])) {
                $errors['database'] = self::problem('The database type is a name such as MySQL.');
            }

            if (empty($errors['mysql_name'])) {
                if ($values['mysql_name'] === '') {
                    $errors['mysql_name'] = self::problem('Enter the name of the database. It has to exist already: the installer does not create it.');
                } else if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $values['mysql_name'])) {
                    $errors['mysql_name'] = self::problem('A database name can have letters, digits, _, $ and - only, and 64 characters at most.');
                }
            }

            if (empty($errors['mysql_user']) && $values['mysql_user'] === '') {
                $errors['mysql_user'] = self::problem('Enter the name of the database user.');
            }

            if (empty($errors['mysql_host']) && empty($errors['mysql_port'])) {
                $server = self::parseDatabaseHost($values['mysql_host'], $values['mysql_port']);
                if ($server['error']) {
                    $errors[$server['field']] = self::problem($server['error']);
                } else {
                    $values['mysql_host'] = $server['host'];
                    $values['mysql_port'] = $server['port'];
                }
            }

            if (empty($errors['upload_path'])) {
                $values['upload_path'] = self::normaliseUploadPath($values['upload_path']);
                if ($values['upload_path'] === '') {
                    $errors['upload_path'] = self::problem('Enter the full path of the folder where uploaded files are kept.');
                } else if (!preg_match('#^(/|[A-Za-z]:[\\\\/])#', $values['upload_path'])) {
                    $errors['upload_path'] = self::problem('Enter the full path of the folder, starting from the top: ' . $this->defaultUploadPath() . ' for example.');
                }
            }

            return ['values' => $values, 'errors' => $errors];
        }

        /**
         * Is this database type one the installer connects to and loads a schema into?
         * MongoDB needs no schema, and the installer leaves it alone, as it always has.
         */
        protected static function usesSchema($database)
        {
            return !in_array(strtolower((string) $database), ['mongo', 'mongodb'], true);
        }

        /**
         * A path with "." and ".." taken out and symbolic links followed as far as the path
         * exists, to compare where two folders really are.
         */
        protected static function resolvePath($path)
        {
            $path = rtrim(str_replace('\\', '/', $path), '/');
            $missing = [];
            while ($path !== '' && !file_exists($path)) {
                array_unshift($missing, basename($path));
                $parent = dirname($path);
                if ($parent === $path) {
                    break;
                }
                $path = $parent;
            }
            $real = $path === '' ? '' : (string) realpath($path);
            $parts = [];
            foreach ($missing as $part) {
                if ($part === '..') {
                    $real = dirname($real);
                } else if ($part !== '.' && $part !== '') {
                    $parts[] = $part;
                }
            }

            return rtrim(rtrim($real, '/') . '/' . implode('/', $parts), '/');
        }

        /**
         * Check the upload folder: exactly the folder that was typed. It is created when it
         * does not exist, and the check is made by saving a file in it and deleting the file
         * again, as the user PHP runs as.
         *
         * A folder inside the installation is refused, because the web server would hand out
         * what is saved there to anybody who asks; except Uploads itself, which the shipped
         * .htaccess and the sample nginx configuration close to visitors.
         *
         * @param  string $upload_path
         * @return string The folder, with a slash at the end
         * @throws \Idno\Exceptions\InstallerException saying what is wrong and what to run to fix it
         */
        public function checkUploadDirectory($upload_path)
        {
            $path = self::normaliseUploadPath($upload_path);
            if ($path === '') {
                throw new \Idno\Exceptions\InstallerException(self::problem('Enter the full path of the folder where uploaded files are kept.'));
            }
            $bare = rtrim($path, '/\\');
            $user = self::serverUser();
            $who = $user !== '' ? 'the user the web server runs as (' . $user . ')' : 'the user the web server runs as';
            $owner = $user !== '' ? $user : 'www-data';
            $fix = [
                'sudo mkdir -p ' . escapeshellarg($bare),
                'sudo chown ' . escapeshellarg($owner) . ' ' . escapeshellarg($bare),
                'sudo chmod 750 ' . escapeshellarg($bare),
            ];

            $real = self::resolvePath($bare);
            $install = self::resolvePath($this->install_path);
            if ($real === $install || strpos($real . '/', $install . '/') === 0) {
                if ($real !== $install . '/Uploads') {
                    throw new \Idno\Exceptions\InstallerException(self::problem(
                        $path . ' is inside the folder the web server serves (' . $install . '), so anybody could download what is saved there. '
                        . 'Choose a folder outside it, or use ' . $install . '/Uploads/, which the web server configuration that comes with Idno closes to visitors.'
                    ));
                }
            }

            if (file_exists($bare) && !is_dir($bare)) {
                throw new \Idno\Exceptions\InstallerException(self::problem($path . ' is a file, not a folder. Choose another path.'));
            }
            if (!is_dir($bare)) {
                if (!@mkdir($bare, 0750, true) && !is_dir($bare)) {
                    throw new \Idno\Exceptions\InstallerException(self::problem(
                        'The folder ' . $path . ' does not exist, and ' . $who . ' is not allowed to create it. Create it and give it to that user:',
                        $fix
                    ));
                }
            }

            $probe = $bare . '/.idno-write-test-' . bin2hex(random_bytes(8));
            $written = @file_put_contents($probe, 'idno');
            $read = $written === false ? false : @file_get_contents($probe);
            if ($written !== false) {
                @unlink($probe);
            }
            if ($written === false || $read !== 'idno' || file_exists($probe)) {
                throw new \Idno\Exceptions\InstallerException(self::problem(
                    'Files can\'t be saved in ' . $path . '. The folder has to belong to ' . $who . ', or be writable by it:',
                    array_slice($fix, 1)
                ));
            }

            return $path;
        }

        /**
         * Connect to the MySQL database that was described.
         *
         * @param  array $values Settings as normaliseSettings() returns them
         * @return \PDO
         * @throws \Idno\Exceptions\InstallerException naming the field the problem is in
         */
        public function connectDatabase(array $values)
        {
            $dsn = 'mysql:host=' . $values['mysql_host'];
            if ($values['mysql_port'] !== '') {
                $dsn .= ';port=' . $values['mysql_port'];
            }
            $dsn .= ';dbname=' . $values['mysql_name'] . ';charset=utf8mb4';
            $where = $values['mysql_host'] . ', port ' . ($values['mysql_port'] !== '' ? $values['mysql_port'] : '3306');

            try {
                return new \PDO($dsn, $values['mysql_user'], $values['mysql_pass'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 10,
                ]);
            } catch (\PDOException $e) {
                $code = (int) ($e->errorInfo[1] ?? 0);
                if (!$code && preg_match('/\[(\d{4})\]/', $e->getMessage(), $matches)) {
                    $code = (int) $matches[1];
                }
                // The server's own words, without the password (which they never hold) and on one line
                $detail = ' The database server said: ' . trim(preg_replace('/\s+/', ' ', str_replace($values['mysql_pass'] !== '' ? $values['mysql_pass'] : "\0", '', $e->getMessage())));

                if ($code === 1045 || $code === 1698) {
                    throw new \Idno\Exceptions\InstallerException(self::problem('The database server at ' . $where . ' refused this user name and password. Check both.' . $detail), 'mysql_user');
                }
                if ($code === 1044 || $code === 1049) {
                    throw new \Idno\Exceptions\InstallerException(self::problem(
                        'There is no database called ' . $values['mysql_name'] . ' on the server at ' . $where . ', or the user ' . $values['mysql_user'] . ' may not use it. '
                        . 'Create the database first and give the user all rights on it.' . $detail
                    ), 'mysql_name');
                }
                $hint = $values['mysql_host'] === 'localhost'
                    ? ' With the name localhost, PHP connects through a socket file and ignores the port: if the server listens on a port, enter 127.0.0.1 instead.'
                    : '';
                throw new \Idno\Exceptions\InstallerException(self::problem('No database server could be reached at ' . $where . '. Check the server name and the port.' . $hint . $detail), 'mysql_host');
            }
        }

        /**
         * Load the schema into a database that has none.
         *
         * A database that already holds an Idno site (its posts, its settings and a note of its
         * schema version) is left exactly as it is: Idno applies any upgrades it needs when it
         * next connects. One that holds part of a site is refused. Empty tables with Idno's
         * names and no site (what an earlier attempt can leave behind) are replaced. If the
         * schema can't be loaded, the tables this attempt created are removed again.
         *
         * @param  \PDO   $dbh
         * @param  string $schema
         * @return string 'created' or 'existing'
         * @throws \Idno\Exceptions\InstallerException
         */
        public function installSchema(\PDO $dbh, $schema = 'mysql')
        {
            $schema = preg_replace("/[^a-zA-Z0-9\_\.]/", "", strtolower($schema)); // Sanitise $schema
            $dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $tables = function () use ($dbh) {
                return $dbh->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            };

            $sql = @file_get_contents("{$this->root_path}/warmup/schemas/$schema/$schema.sql");
            if (empty($sql)) {
                throw new \Idno\Exceptions\InstallerException(self::problem('The file that describes the database tables (warmup/schemas/' . $schema . '/' . $schema . '.sql) is missing from this copy of Idno.'), 'mysql_name');
            }
            // The tables that are Idno's, by the schema's own account
            preg_match_all('/CREATE TABLE IF NOT EXISTS `([A-Za-z0-9_]+)`/', $sql, $matches);
            $ours = $matches[1];

            try {
                $before = $tables();

                if (in_array('entities', $before, true)) {
                    // A site lives here. It is left exactly as it is, if it is whole.
                    $version = false;
                    if (in_array('versions', $before, true)) {
                        $version = $dbh->query("SELECT `value` FROM `versions` WHERE `label` = 'schema'")->fetchColumn();
                    }
                    if ($version !== false && in_array('config', $before, true)) {
                        return 'existing';
                    }
                    throw new \Idno\Exceptions\InstallerException(self::problem(
                        'This database holds part of an Idno site: it has posts, but other tables are missing. The installer changes nothing in it. Use an empty database, or restore this one from a backup.'
                    ), 'mysql_name');
                }

                // No site, but perhaps what an earlier attempt left: empty tables with Idno's names,
                // and a note of a schema version. They are in the way of the schema, so they go;
                // a table with anything in it is somebody's data and stops the installation.
                $leftovers = array_values(array_intersect($before, $ours));
                foreach ($leftovers as $table) {
                    if ($table !== 'versions' && $dbh->query('SELECT 1 FROM `' . $table . '` LIMIT 1')->fetchColumn() !== false) {
                        throw new \Idno\Exceptions\InstallerException(self::problem(
                            'This database has a table called ' . $table . ' with data in it, which is not an Idno site that can be used. Use an empty database.'
                        ), 'mysql_name');
                    }
                }
                if ($leftovers) {
                    $dbh->exec('SET FOREIGN_KEY_CHECKS = 0');
                    foreach ($leftovers as $table) {
                        $dbh->exec('DROP TABLE IF EXISTS `' . $table . '`');
                    }
                    $dbh->exec('SET FOREIGN_KEY_CHECKS = 1');
                    $before = array_values(array_diff($before, $leftovers));
                }
            } catch (\PDOException $e) {
                throw new \Idno\Exceptions\InstallerException(self::problem('The database could not be read. The database server said: ' . $e->getMessage()), 'mysql_name');
            }

            $statements = explode(";\n", $sql); // Explode statements; only mysql can support multiple statements per line, and then not safely.
            foreach ($statements as $sql) {
                $sql = trim($sql);
                if (!empty($sql)) {
                    try {
                        $dbh->exec($sql);
                    } catch (\PDOException $e) {
                        // MySQL can't roll back CREATE TABLE, so take away what this attempt made
                        try {
                            $created = array_diff($tables(), $before);
                            if ($created) {
                                $dbh->exec('SET FOREIGN_KEY_CHECKS = 0');
                                foreach ($created as $table) {
                                    $dbh->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`');
                                }
                                $dbh->exec('SET FOREIGN_KEY_CHECKS = 1');
                            }
                        } catch (\PDOException $ignored) {
                            // Nothing more can be done; the message below says what happened
                        }
                        throw new \Idno\Exceptions\InstallerException(self::problem(
                            'The database tables could not be created, and nothing was left behind. Check that the database user has all rights on the database. The database server said: ' . $e->getMessage()
                        ), 'mysql_name');
                    }
                }
            }

            return 'created';
        }

        /**
         * The .htaccess file that Apache needs. One that is there is never changed; one that
         * is missing is copied from the sample, if the folder can be written to.
         *
         * @return bool Is there a .htaccess file now?
         */
        protected function ensureHtaccess()
        {
            $target = $this->install_path . '/.htaccess';
            if (!file_exists($target)) {
                @copy($this->root_path . '/warmup/webserver-configs/htaccess.dist', $target);
            }

            return file_exists($target);
        }

        /**
         * The full path of a configuration file
         *
         * @param  string $name
         * @return string
         * @throws \Idno\Exceptions\InstallerException if the name is not that of an .ini file
         */
        public function configPath($name = 'config.ini')
        {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.ini$/', (string) $name)) {
                throw new \Idno\Exceptions\InstallerException(self::problem('The name of a configuration file ends in .ini and has letters, digits, dots and dashes only: config.ini, for example.'), 'config');
            }

            return $this->install_path . '/configuration/' . $name;
        }

        /**
         * Can the installer save the configuration file itself? Asked before anything else is
         * done, so that a server that keeps its code read-only is told what to do by hand.
         *
         * @param  string $name
         * @return bool
         */
        public function canWriteConfig($name = 'config.ini')
        {
            $directory = dirname($this->configPath($name));
            if (is_dir($directory)) {
                return is_writable($directory);
            }

            return !file_exists($directory) && is_writable(dirname($directory));
        }

        /**
         * Save the configuration file. It appears complete or not at all (it is written beside
         * its place and then put there), and a file that is already there is never replaced.
         *
         * @param  string $ini_file The contents, from buildConfig()
         * @param  string $name
         * @throws \Idno\Exceptions\InstallerException when there is a file already, or it can't be written
         */
        public function writeConfig($ini_file, $name = 'config.ini')
        {
            $target = $this->configPath($name);
            $directory = dirname($target);

            if (file_exists($target)) {
                throw new \Idno\Exceptions\InstallerException(self::problem('There is already a configuration file at ' . $target . '. The installer never replaces one: correct it by hand, or remove it and start again.'), 'config_exists');
            }
            if (!is_dir($directory)) {
                @mkdir($directory, 0755);
            }

            $temporary = is_dir($directory) && is_writable($directory) ? @tempnam($directory, '.config-') : false;
            if ($temporary === false || dirname($temporary) !== $directory && realpath(dirname($temporary)) !== realpath($directory)) {
                if ($temporary !== false) {
                    @unlink($temporary);
                }
                throw new \Idno\Exceptions\InstallerException(self::problem('The configuration file could not be saved in ' . $directory . '.'), 'config');
            }

            $saved = @file_put_contents($temporary, $ini_file) === strlen($ini_file);
            if ($saved) {
                @chmod($temporary, $this->config_mode !== null ? $this->config_mode : 0666 & ~umask());
                // link() fails when the name is taken, so two installers can't both win
                $saved = @link($temporary, $target);
                if (!$saved && !file_exists($target)) {
                    $saved = @rename($temporary, $target);
                }
            }
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
            if (!$saved) {
                throw new \Idno\Exceptions\InstallerException(self::problem('The configuration file could not be saved at ' . $target . '.'), file_exists($target) ? 'config_exists' : 'config');
            }
        }

        /**
         * Generate a configuration file from a template. Every value is written with
         * iniValue(), and all are put in at once, so that no value can be read as a
         * placeholder or as a setting of its own.
         *
         * @param  array $params Name => Value array of configuration values, e.g. "dbname" => "idno"
         * @return string The built ini file
         */
        public function buildConfig(array $params = [])
        {

            // Set some defaults
            $defaults = [
                'database' => 'MySQL',
                'dbname' => 'idno',
                'dbuser' => '',
                'dbpass' => '',
                'dbhost' => 'localhost',
                'dbport' => '',

                'filesystem' => 'local',
                'uploadpath' => $this->defaultUploadPath()
            ];

            // Merge parameters into defaults
            $params = array_merge($defaults, array_intersect_key($params, $defaults));

            // Load template
            $template = file_get_contents($this->root_path . '/warmup/webserver-configs/config.ini.template');
            if (empty($template)) {
                throw new \Idno\Exceptions\ConfigurationException('Configuration template could not be loaded.');
            }

            // Build config output
            $replace = [
                '%version%' => preg_replace('/[^A-Za-z0-9._ -]/', '', (string) Version::version()),
                '%build%' => preg_replace('/[^A-Za-z0-9._ -]/', '', (string) Version::build()),
                '%datetime%' => date('r'),
            ];
            foreach ($params as $name => $value) {
                $replace["%{$name}%"] = self::iniValue($value);
            }
            // The port is a setting only when one was given: without it MySQL's own default applies
            $replace['%dbport_setting%'] = (string) $params['dbport'] !== '' ? 'dbport = ' . self::iniValue($params['dbport']) . "\n" : '';

            return strtr($template, $replace);
        }

        /**
         * The whole installation, for the web pages and the command line alike: check what
         * was entered, check the upload folder, connect to the database, load the schema and
         * save the configuration file.
         *
         * Nothing is done when anything is wrong. When everything is right but the
         * configuration file can't be saved, the result holds its contents to be saved by hand.
         *
         * @param  array  $input       Values by field name (see FIELDS)
         * @param  string $config_name
         * @return array 'ok' (nothing was wrong), 'values', 'errors' (field => problem), 'config'
         *               (the file's contents), 'config_path', 'config_written', 'htaccess'
         *               (is there a .htaccess file) and 'schema' ('created', 'existing' or '')
         */
        public function install(array $input, $config_name = 'config.ini')
        {
            $checked = $this->normaliseSettings($input);
            $result = [
                'ok' => false,
                'values' => $checked['values'],
                'errors' => $checked['errors'],
                'config' => '',
                'config_path' => '',
                'config_written' => false,
                'htaccess' => file_exists($this->install_path . '/.htaccess'),
                'schema' => '',
            ];
            $values =& $result['values'];
            $errors =& $result['errors'];

            try {
                $result['config_path'] = $this->configPath($config_name);
                if (file_exists($result['config_path'])) {
                    $errors['config'] = self::problem('There is already a configuration file at ' . $result['config_path'] . '. The installer never replaces one: correct it by hand, or remove it and start again.');
                }
            } catch (\Idno\Exceptions\InstallerException $e) {
                $errors['config'] = $e->getProblem();
            }
            // Asked before anything is changed
            $can_write = empty($errors['config']) && $this->canWriteConfig($config_name);

            if (empty($errors['upload_path'])) {
                try {
                    $values['upload_path'] = $this->checkUploadDirectory($values['upload_path']);
                } catch (\Idno\Exceptions\InstallerException $e) {
                    $errors['upload_path'] = $e->getProblem();
                }
            }

            $database_fields = ['database', 'mysql_name', 'mysql_user', 'mysql_pass', 'mysql_host', 'mysql_port'];
            if (!array_intersect_key($errors, array_flip($database_fields)) && self::usesSchema($values['database'])) {
                try {
                    $dbh = $this->connectDatabase($values);
                    // The tables are made only when nothing else stands in the way
                    if (empty($errors)) {
                        $result['schema'] = $this->installSchema($dbh);
                    }
                    $dbh = null;
                } catch (\Idno\Exceptions\InstallerException $e) {
                    $errors[$e->getField() ?: 'mysql_host'] = $e->getProblem();
                }
            }

            if (!empty($errors)) {
                return $result;
            }

            $result['config'] = $this->buildConfig(
                [
                    'database' => $values['database'],
                    'dbname' => $values['mysql_name'],
                    'dbuser' => $values['mysql_user'],
                    'dbpass' => $values['mysql_pass'],
                    'dbhost' => $values['mysql_host'],
                    'dbport' => $values['mysql_port'],
                    'uploadpath' => $values['upload_path'],
                ]
            );
            $result['htaccess'] = $this->ensureHtaccess();

            if ($can_write) {
                try {
                    $this->writeConfig($result['config'], $config_name);
                    $result['config_written'] = true;
                } catch (\Idno\Exceptions\InstallerException $e) {
                    if ($e->getField() === 'config_exists') {
                        $errors['config'] = $e->getProblem();

                        return $result;
                    }
                    // It could not be saved after all: it is saved by hand
                }
            }
            $result['ok'] = true;

            return $result;
        }

        /**
         * Return whether mod rewrite is available
         */
        public static function rewriteAvailable()
        {
            if (in_array('mod_rewrite', apache_get_modules())) {
                return true;
            } else {
                return false;
            }
        }

        /**
         * Check the PHP version.
         *
         * @return 'ok','fail','warn'
         */
        public static function checkPHPVersion()
        {
            if (version_compare(phpversion(), '8.3') >= 0) {
                return 'ok';
            } else {
                return 'fail';
            }
        }

        /**
         * Return the required modules
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
                'pdo_mysql',
                'reflection',
                'session',
                'simplexml',
                'openssl',
                'gettext',
            ];
        }

    }

}
