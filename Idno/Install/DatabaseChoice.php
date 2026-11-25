<?php

declare(strict_types=1);

namespace Idno\Install;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Config\BootConfig;
use Idno\Exceptions\ConfigurationException;
use Idno\Storage\AttributeTypeMap;
use Idno\Storage\ConnectionFactory;
use Idno\Storage\ConnectionParameters;
use Idno\Storage\DialectFactory;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Idno\Storage\SchemaCatalog;
use Idno\Storage\SchemaMigrator;
use Idno\Upgrade\LegacyDataCopier;
use Idno\Upgrade\PublicMessage;
use Idno\Upgrade\SchemaState;
use Idno\Upgrade\SchemaStatus;
use Idno\Upgrade\UpgradeLock;

/**
 * The database of a new installation: which kind was chosen, whether it can be used, the values
 * the configuration file gets for it, and what it already holds. It loads no SQL: the tables are
 * created by the upgrade (the upgrade page, or `php bin/console idno:upgrade`).
 */
final class DatabaseChoice
{
    /** How long an installer waits for a database server to answer the connection. */
    public const CONNECT_SECONDS = 5;

    /** @var \Closure(string): bool */
    private readonly \Closure $moduleLoaded;

    /**
     * @param (callable(string): bool)|null $moduleLoaded Whether a PHP module is loaded (extension_loaded() by default)
     */
    public function __construct(private readonly InstallPaths $paths, ?callable $moduleLoaded = null)
    {
        $this->moduleLoaded = ($moduleLoaded ?? static fn (string $module): bool => extension_loaded($module))(...);
    }

    /**
     * The kind of database a person chose: `sqlite` for "sqlite", "SQLite" or "sqlite3" (with
     * spaces around it), `mysql` for anything else (MySQL and MariaDB), except MongoDB.
     *
     * @throws ConfigurationException for "mongo" or "mongodb" (any case, with spaces around it):
     *                                Idno no longer runs on MongoDB, and a MySQL configuration is
     *                                never written in its place
     */
    public static function type(string $database): string
    {
        if (in_array(strtolower(trim($database)), ['mongo', 'mongodb'], true)) {
            throw new ConfigurationException('MongoDB is no longer supported. Choose MySQL (or MariaDB) or SQLite. To move an existing MongoDB site, see the upgrade notes: php bin/console idno:import:mongo copies its data into MySQL, MariaDB or SQLite.');
        }

        return in_array(strtolower(trim($database)), ['sqlite', 'sqlite3'], true) ? 'sqlite' : 'mysql';
    }

    /**
     * What to tell a person who has set up a site from the command line: the files are owned by
     * the user who ran the command, and the web server (and any cron or queue worker) must be able
     * to read the configuration and write to the database. The installers change no file mode.
     *
     * @param  string $path The SQLite database file
     * @return string[] Lines of text
     */
    public static function sqliteOwnershipNotes(string $path): array
    {
        $directory = dirname($path);

        return [
            'The SQLite database ' . $path . ' and its directory ' . $directory . ' are owned by the user who ran this command.',
            'SQLite and the upgrade also create files beside the database (-wal, -shm and .upgrade.lock), and the web server needs to write to the database, its directory and those files.',
            'So they must be owned by (or writable for) the web server\'s user. For example:',
            '    chown -R www-data ' . $directory,
            'Run later console commands on this site (php bin/console ...) as that user too, or the files they create will not be writable by the web server.',
        ];
    }

    /**
     * The absolute path of an SQLite database file: the default (data/idno.sqlite) for no path, a
     * relative path under the project directory (as the site reads it), an absolute one as it is.
     * Nothing is created or touched: the path is only checked, so a refused one leaves nothing
     * behind. A path is refused when it cannot be written in the single quotes of a configuration
     * file, when one of its segments is `..`, and when its file name is not letters, digits, dots,
     * underscores and dashes ending in `.sqlite`: the suffix every shipped web server configuration
     * refuses to serve and PHP never executes.
     *
     * @throws ConfigurationException
     */
    public function sqlitePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $this->paths->sqliteFile();
        }
        if (preg_match('/[\'\x00-\x1f\x7f]/', $path) === 1) {
            throw new ConfigurationException('The path of the database file cannot contain a single quote or a control character.');
        }
        $segments = preg_split('#[/\\\\]#', $path) ?: [];
        if (in_array('..', $segments, true)) {
            throw new ConfigurationException('The path of the database file cannot contain "..".');
        }
        if (!ConnectionParameters::isSqliteFileName($path)) {
            throw new ConfigurationException('The name of the database file must end in lower case .sqlite exactly (not .SQLite or .Sqlite) and hold only letters, digits, dots, underscores and dashes (for example idno.sqlite): web servers are set up never to serve or run a file with that name.');
        }
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('#^[A-Za-z]:#', $path) === 1) {
            return $path;
        }

        return $this->paths->root() . '/' . $path;
    }

    /**
     * Checks the chosen database and returns the values the configuration file takes for it. For
     * MySQL and MariaDB the input is `name`, `user`, `pass` and `host` (which may end in `:port`), and the database is
     * connected to. For SQLite it is `path` (see sqlitePath()): the directory and an empty file
     * are created when missing, both must be writable, and the file must open as an SQLite database
     * (an existing file that does not is refused and left as it is). The value written for SQLite
     * is exactly `sqlite`.
     *
     * @param  string               $type  `mysql` or `sqlite` (see type())
     * @param  array<string, mixed> $input
     * @return array{database: string, dbname: string, dbuser: string, dbpass: string, dbhost: string, dbport?: string}
     * @throws ConfigurationException with a message that may be shown to anyone
     */
    public function params(string $type, array $input): array
    {
        if (self::type($type) === 'sqlite') {
            return $this->sqliteParams((string) ($input['path'] ?? ''));
        }
        if (!($this->moduleLoaded)('pdo_mysql')) {
            throw new ConfigurationException('MySQL and MariaDB need the pdo_mysql extension for PHP, which is not installed.');
        }
        $params = [
            'database' => 'MySQL',
            'dbname' => (string) ($input['name'] ?? ''),
            'dbuser' => (string) ($input['user'] ?? ''),
            'dbpass' => (string) ($input['pass'] ?? ''),
            'dbhost' => (string) ($input['host'] ?? ''),
        ];
        if ($params['dbname'] === '' || $params['dbhost'] === '') {
            throw new ConfigurationException('Idno needs the name of the database and the name of the server it is on.');
        }
        // A server typed as host:port or [ipv6]:port (some hosts give it that way) is a host and a port.
        if (preg_match('/\A([^:\[\]]+|\[[^\[\]]+\]):([0-9]{1,5})\z/', $params['dbhost'], $match) === 1) {
            $params['dbhost'] = $match[1];
            $params['dbport'] = $match[2];
        }
        // The server and the database's name become parts of the driver's connection string: only what
        // a host name, an address or a database name can hold is accepted, so that neither can add an
        // option to it (a socket, a file).
        if (preg_match('/\A(?:[A-Za-z0-9._-]{1,253}|\[[0-9A-Fa-f:.]{2,45}\])\z/', $params['dbhost']) !== 1) {
            throw new ConfigurationException('The database server must be a host name or an IP address, optionally followed by a colon and a port (for example localhost, or db.example.com:3307).');
        }
        if (preg_match('/\A[^;=\s\x00-\x1f\x7f]{1,64}\z/', $params['dbname']) !== 1) {
            throw new ConfigurationException('The name of the database cannot contain a space, a semicolon or an equals sign.');
        }
        $params['dbhost'] = trim($params['dbhost'], '[]');
        $connection = self::connection($params, $this->paths->root());
        try {
            $connection->getServerVersion();
        } catch (\Throwable $e) {
            throw new DatabaseConnectionFailed(self::connectionProblem($e), 0, $e);
        } finally {
            $connection->close();
        }

        return $params;
    }

    /**
     * The connection an installer checks a candidate database with. It is the site's connection for
     * those settings, except that for MySQL and MariaDB the server may NOT ask for a local file (the
     * site's own connection allows LOAD DATA LOCAL; here the server is whatever a visitor of an
     * uninstalled site typed, and a hostile one could ask the driver for any file the web server can
     * read) and that connecting gives up after CONNECT_SECONDS.
     *
     * @param array<string, mixed> $params database, dbname, dbuser, dbpass, dbhost, dbport
     */
    public static function connection(array $params, ?string $projectDir = null): Connection
    {
        $dbal = ConnectionFactory::parameters($params, [], $projectDir);
        if (($dbal['driver'] ?? null) === 'pdo_mysql') {
            $dbal['driverOptions'] = [
                \Pdo\Mysql::ATTR_LOCAL_INFILE => 0,
                \PDO::ATTR_TIMEOUT => self::CONNECT_SECONDS,
            ] + ($dbal['driverOptions'] ?? []);
        }

        return DriverManager::getConnection($dbal, ConnectionFactory::configuration($dbal));
    }

    /**
     * Why a MySQL or MariaDB database could not be connected to, as one of a fixed set of sentences:
     * the driver's own message (which can hold the user name and the host) is never shown.
     */
    public static function connectionProblem(\Throwable $e): string
    {
        $code = 0;
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException && isset($cause->errorInfo[1])) {
                $code = (int) $cause->errorInfo[1];
                break;
            }
            if ($cause instanceof \Doctrine\DBAL\Driver\Exception) {
                $code = (int) $cause->getCode();
            }
        }

        return match (true) {
            $code === 1045, $code === 1698 => 'Idno could not sign in to the database: the user name or the password was not accepted. Check both and try again.',
            $code === 1044 => 'That database user cannot use a database with that name: either the name is wrong, or the user has not been added to the database. Check the name, and in your hosting control panel add the user to the database with all privileges.',
            $code === 1049 => 'There is no database with that name on the server. Create it first (your hosting control panel has a "MySQL databases" page), or check the name.',
            in_array($code, [2002, 2003, 2005, 2006], true) => 'Idno could not reach the database server. Check the server name: it is almost always localhost, and your hosting control panel says if it is something else.',
            default => 'Idno could not connect to the database' . ($code > 0 ? ' (the server answered with error ' . $code . ')' : '') . '. Check the name, the user, the password and the server name.',
        };
    }

    /**
     * What the database of these params() already holds, as the upgrade itself reads it, so that an
     * installer never writes the configuration of a database Idno cannot start on and never treats an
     * existing site as a new one.
     *
     * @param array<string, string> $params What params() returned
     */
    public function inspect(array $params): DatabaseFinding
    {
        $config = new BootConfig($params, false);
        $connection = self::connection($params, $this->paths->root());
        try {
            $catalog = new SchemaCatalog($connection);
            $state = new SchemaState(
                $config,
                static fn () => $connection,
                static fn () => $catalog,
                static fn () => new SchemaMigrator($connection),
                static fn () => new LegacyDataCopier($connection, (new DialectFactory($config))->create(), new AttributeTypeMap(), new UpgradeLock($connection)),
            );
            $status = $state->status();
            $message = PublicMessage::forStatus($status, $state->refusal());

            return match (true) {
                $status->refusesTheUpgrade(), $status === SchemaStatus::Unreachable => new DatabaseFinding(DatabaseFinding::REFUSED, $message->text ?? 'Idno cannot use this database.'),
                $status === SchemaStatus::Legacy => new DatabaseFinding(DatabaseFinding::IDNO_ONE_X),
                $status === SchemaStatus::Empty => new DatabaseFinding(DatabaseFinding::EMPTY),
                default => new DatabaseFinding(DatabaseFinding::IDNO),
            };
        } finally {
            $connection->close();
        }
    }

    /**
     * @return array{database: string, dbname: string, dbuser: string, dbpass: string, dbhost: string}
     * @throws ConfigurationException
     */
    private function sqliteParams(string $input): array
    {
        if (!($this->moduleLoaded)('pdo_sqlite')) {
            throw new ConfigurationException('SQLite needs the pdo_sqlite extension for PHP, which is not installed.');
        }

        // Everything that can be checked without touching the disk first (the path, the directory,
        // the file); the directory and the file are created last.
        $path = $this->sqlitePath($input);
        $directory = dirname($path);
        if (is_dir($path)) {
            throw new ConfigurationException($path . ' is a directory, not a database file.');
        }
        if (is_dir($directory) && !is_writable($directory)) {
            throw new ConfigurationException('The web server cannot write to ' . $directory . ': SQLite keeps its database and its journal files there. Check the folder\'s permissions and try again.');
        }
        if (file_exists($path) && (!is_readable($path) || !is_writable($path))) {
            throw new ConfigurationException('The web server cannot read and write ' . $path . ': check its permissions and try again.');
        }

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new ConfigurationException('The directory ' . $directory . ' for the database file could not be created: check permissions and try again.');
        }
        if (!is_writable($directory)) {
            throw new ConfigurationException('The web server cannot write to ' . $directory . ': SQLite keeps its database and its journal files there. Check the folder\'s permissions and try again.');
        }
        if (!file_exists($path) && @file_put_contents($path, '') === false) {
            throw new ConfigurationException('The database file ' . $path . ' could not be created: check permissions and try again.');
        }

        // Open it as the site will (SqliteConnectionSetup refuses SQLite below 3.38).
        $connection = null;
        try {
            $connection = DriverManager::getConnection(
                ['driver' => 'pdo_sqlite', 'path' => $path],
                (new Configuration())->setMiddlewares([new SqliteConnectionSetup()])
            );
            $connection->executeQuery('select count(*) from sqlite_master')->fetchOne();
        } catch (\Throwable $e) {
            throw new ConfigurationException($path . ' could not be opened as an SQLite database: ' . ($e instanceof \LogicException ? $e->getMessage() : 'it is not an SQLite database file.'), 0, $e);
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
}
