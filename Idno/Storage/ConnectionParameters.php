<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * Translates the legacy database settings into Doctrine DBAL connection parameters.
 *
 * The parameters reproduce the connection the old SQL backend opened (Idno\Data\MySQL's
 * buildConnectionString() and createPDOConnection(), since removed; and the
 * `beanstalk-mysql` mapping in Idno\Legacy\LegacyServiceFactory::db()): the same host, port,
 * database, user and password, the DSN charset `utf8mb4` (UTF-8 in full: the schema's search text
 * and indexed values are utf8mb4, so a four-byte character such as an emoji reaches them intact)
 * and the same PDO attributes. Nothing is "improved": the connection must
 * behave exactly like the legacy one, session variables included.
 *
 * This class is a static helper, not a container service (see config/services.php).
 */
final class ConnectionParameters
{
    /** The settings the legacy Config falls back to when the configuration does not set them. */
    private const LEGACY_DEFAULTS = ['database' => 'mysql', 'dbname' => 'idno'];

    /** What the legacy Idno::componentFactory() loads a database backend as (see backendClass()). */
    private const LEGACY_BACKEND_BASE = 'Idno\\Core\\DataConcierge';
    private const LEGACY_BACKEND_NAMESPACE = 'Idno\\Data\\';
    private const LEGACY_SQL_BACKEND = 'Idno\\Data\\MySQL';

    /** The DBAL parameters that identify the database; fromLegacyConfig() sets every one it needs. */
    public const IDENTITY_KEYS = ['driver', 'driverClass', 'url', 'host', 'port', 'unix_socket', 'dbname', 'user', 'password', 'charset', 'path', 'memory', 'driverOptions'];

    /**
     * Legacy config (BootConfig::all()) and the RDS_* server variables → Doctrine DBAL connection parameters.
     *
     * @param array<string, mixed> $config the boot configuration (BootConfig::all())
     * @param array<string, mixed> $server the RDS_* variables (RDS_HOSTNAME, RDS_USERNAME, RDS_PASSWORD,
     *                                     RDS_PORT, RDS_DB_NAME), read only for `beanstalk-mysql`
     * @param string|null $projectDir the project directory: an SQLite `dbname` that is neither `:memory:`
     *                                nor absolute (a leading `/` or a Windows drive) is a path under it;
     *                                with null a relative path is left as it is
     *
     * @return array<string, mixed>
     *
     * @throws \LogicException when the configured database is not an SQL database (MongoDB, or a
     *                          loadable backend class supplied by a plugin; see driverFamily())
     */
    public static function fromLegacyConfig(array $config, array $server, ?string $projectDir = null): array
    {
        $config += self::LEGACY_DEFAULTS;
        $family = self::driverFamily($config);

        if ($family === 'sqlite') {
            $path = (string) ($config['dbname'] ?? '');
            if ($path === ':memory:') {
                return ['driver' => 'pdo_sqlite', 'memory' => true];
            }
            if ($projectDir !== null && $projectDir !== '' && $path !== '' && !self::isAbsolutePath($path)) {
                $path = rtrim($projectDir, '/\\') . '/' . $path;
            }

            return ['driver' => 'pdo_sqlite', 'path' => $path];
        }
        if ($family !== 'mysql') {
            throw new \LogicException(sprintf(
                'The site\'s database is "%s", which is not an SQL database: there is no DBAL connection for it.',
                is_scalar($config['database']) ? (string) $config['database'] : get_debug_type($config['database']),
            ));
        }

        if (self::name($config) === 'beanstalk-mysql') {
            // A special instance of MySQL for Amazon Elastic Beanstalk (LegacyServiceFactory::db()).
            $config['dbhost'] = $server['RDS_HOSTNAME'] ?? null;
            $config['dbuser'] = $server['RDS_USERNAME'] ?? null;
            $config['dbpass'] = $server['RDS_PASSWORD'] ?? null;
            $config['dbport'] = $server['RDS_PORT'] ?? null;
            if (empty($config['dbname'])) {
                $config['dbname'] = $server['RDS_DB_NAME'] ?? null;
            }
        }

        $params = [
            'driver' => 'pdo_mysql',
            'host' => self::text($config['dbhost'] ?? null),
        ];
        // buildConnectionString() adds the port only when it is set.
        if (!empty($config['dbport'])) {
            $params['port'] = (int) $config['dbport'];
        }

        return $params + [
            'dbname' => self::text($config['dbname'] ?? null),
            'user' => self::text($config['dbuser'] ?? null),
            'password' => self::text($config['dbpass'] ?? null),
            'charset' => 'utf8mb4',
            'driverOptions' => [
                \Pdo\Mysql::ATTR_LOCAL_INFILE => 1,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ],
        ];
    }

    /**
     * A database URL (`IDNO_DATABASE_URL`) → the legacy settings it sets: `database`, `dbname`, `dbuser`,
     * `dbpass`, `dbhost` and `dbport` (null for one the URL does not carry). The one place that reads it.
     *
     * Every scheme but `sqlite` is read as it always was: the scheme is `database`, the last segment
     * of the path is `dbname`. For `sqlite`, as in Doctrine, the file is the URL's path less ONE leading
     * slash: `sqlite:///data/idno.sqlite` is `data/idno.sqlite` (relative to the project directory, see
     * fromLegacyConfig()), `sqlite:////var/lib/idno.sqlite` is `/var/lib/idno.sqlite` and
     * `sqlite:///:memory:` is `:memory:`.
     *
     * @return array{database: ?string, dbname: string, dbuser: ?string, dbpass: ?string, dbhost: ?string, dbport: ?int}
     */
    public static function fromUrl(string $url): array
    {
        if (preg_match('#^sqlite://([^?\#]*)#i', $url, $matches) === 1) {
            return [
                'database' => 'sqlite',
                'dbname' => str_starts_with($matches[1], '/') ? substr($matches[1], 1) : $matches[1],
                'dbuser' => null,
                'dbpass' => null,
                'dbhost' => null,
                'dbport' => null,
            ];
        }

        $parsed = parse_url($url);
        $parsed = is_array($parsed) ? $parsed : [];

        return [
            'database' => $parsed['scheme'] ?? null,
            'dbname' => basename($parsed['path'] ?? ''),
            'dbuser' => $parsed['user'] ?? null,
            'dbpass' => $parsed['pass'] ?? null,
            'dbhost' => $parsed['host'] ?? null,
            'dbport' => $parsed['port'] ?? null,
        ];
    }

    /**
     * What the legacy `database` value means: 'mysql' | 'sqlite' | 'mongo' | 'other'. This is the one
     * place that decides it, for the legacy db() service (Idno\Legacy\LegacyServiceFactory::db()), the
     * DBAL connection (BootConfigConnectionFactory) and the record store (LegacyRecordStoreFactory).
     *
     *  - 'mysql', 'beanstalk-mysql' (case-insensitive, trimmed) → 'mysql'; 'sqlite' → 'sqlite';
     *    'mongo', 'mongodb' → 'mongo';
     *  - any other value is a backend class name, as the legacy Idno::componentFactory() reads it:
     *    when backendClass() finds a loadable backend class of that name, 'other' (the class is
     *    built as before, and has no DBAL connection or record store);
     *  - otherwise 'mysql': componentFactory() fell back to the old MySQL backend for a name it could
     *    not load, so such a site has always run on MySQL.
     *
     * @param array<string, mixed> $config
     */
    public static function driverFamily(array $config): string
    {
        return match (self::name($config + self::LEGACY_DEFAULTS)) {
            'mysql', 'beanstalk-mysql' => 'mysql',
            'sqlite' => 'sqlite',
            'mongo', 'mongodb' => 'mongo',
            default => self::backendClass($config) === null ? 'mysql' : 'other',
        };
    }

    /**
     * The backend class the legacy Idno::componentFactory() would build for the `database` value,
     * or null when there is none (or when the value is one of the names driverFamily() knows): such a
     * site is on MySQL. componentFactory() takes the value untrimmed and with its case: first as a
     * full class name that extends the backend base class, then as a class in the backend namespace
     * that does. The old SQL backend, Idno\Data\MySQL (since removed), counts as no class: a
     * site configured with it is on MySQL, as it always was, even where an upgrade by overwriting
     * files has left the old class file behind.
     *
     * Only the names of the legacy classes are used (class_exists(), is_subclass_of()); nothing of
     * the legacy application is called.
     *
     * @param array<string, mixed> $config
     */
    public static function backendClass(array $config): ?string
    {
        $database = ($config + self::LEGACY_DEFAULTS)['database'] ?? null;
        if (!is_scalar($database) || in_array(self::name(['database' => $database]), ['mysql', 'beanstalk-mysql', 'sqlite', 'mongo', 'mongodb'], true)) {
            return null;
        }
        $database = (string) $database;
        if ($database === '') {
            return null;
        }

        $class = null;
        if (class_exists($database) && is_subclass_of($database, self::LEGACY_BACKEND_BASE)) {
            $class = $database;
        } elseif (class_exists(self::LEGACY_BACKEND_NAMESPACE . $database) && is_subclass_of(self::LEGACY_BACKEND_NAMESPACE . $database, self::LEGACY_BACKEND_BASE)) {
            $class = self::LEGACY_BACKEND_NAMESPACE . $database;
        }
        if ($class === null || strcasecmp(ltrim($class, '\\'), self::LEGACY_SQL_BACKEND) === 0) {
            return null;
        }

        return $class;
    }

    /**
     * The `database` setting as LegacyServiceFactory::db() reads it.
     *
     * @param array<string, mixed> $config
     */
    private static function name(array $config): string
    {
        $database = $config['database'] ?? '';

        return is_scalar($database) ? trim(strtolower((string) $database)) : '';
    }

    /** A leading `/` or a Windows drive (`C:\`, `C:/`, `C:`). */
    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) === 1;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
