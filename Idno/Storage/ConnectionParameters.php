<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * Translates the legacy database settings into Doctrine DBAL connection parameters.
 *
 * The parameters reproduce the connection the legacy backend opens today
 * (Idno\Data\MySQL::buildConnectionString() and createPDOConnection(), and the
 * `beanstalk-mysql` mapping in Idno\Legacy\LegacyServiceFactory::db()): the same host, port,
 * database, user and password, the DSN charset `utf8` (MySQL's three-byte utf8mb3, which the
 * schema's tables use) and the same PDO attributes. Nothing is "improved": the connection must
 * behave exactly like the legacy one, session variables included.
 *
 * This class is a static helper, not a container service (see config/services.php).
 */
final class ConnectionParameters
{
    /** The settings the legacy Config falls back to when the configuration does not set them. */
    private const LEGACY_DEFAULTS = ['database' => 'mysql', 'dbname' => 'idno'];

    /** The DBAL parameters that identify the database; fromLegacyConfig() sets every one it needs. */
    public const IDENTITY_KEYS = ['driver', 'driverClass', 'url', 'host', 'port', 'unix_socket', 'dbname', 'user', 'password', 'charset', 'path', 'memory', 'driverOptions'];

    /**
     * Legacy config (BootConfig::all()) and the RDS_* server variables → Doctrine DBAL connection parameters.
     *
     * @param array<string, mixed> $config the boot configuration (BootConfig::all())
     * @param array<string, mixed> $server the RDS_* variables (RDS_HOSTNAME, RDS_USERNAME, RDS_PASSWORD,
     *                                     RDS_PORT, RDS_DB_NAME), read only for `beanstalk-mysql`
     *
     * @return array<string, mixed>
     *
     * @throws \LogicException when the configured database is not an SQL database (MongoDB, or a
     *                          backend supplied by a plugin)
     */
    public static function fromLegacyConfig(array $config, array $server): array
    {
        $config += self::LEGACY_DEFAULTS;
        $family = self::driverFamily($config);

        if ($family === 'sqlite') {
            $path = (string) ($config['dbname'] ?? '');

            return $path === ':memory:'
                ? ['driver' => 'pdo_sqlite', 'memory' => true]
                : ['driver' => 'pdo_sqlite', 'path' => $path];
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
            'charset' => 'utf8',
            'driverOptions' => [
                \Pdo\Mysql::ATTR_LOCAL_INFILE => 1,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ],
        ];
    }

    /**
     * 'mysql' | 'sqlite' | 'mongo' | 'other' — from the legacy `database` value, case-insensitively.
     *
     * @param array<string, mixed> $config
     */
    public static function driverFamily(array $config): string
    {
        return match (self::name($config + self::LEGACY_DEFAULTS)) {
            'mysql', 'beanstalk-mysql' => 'mysql',
            'sqlite' => 'sqlite',
            'mongo', 'mongodb' => 'mongo',
            default => 'other',
        };
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

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
