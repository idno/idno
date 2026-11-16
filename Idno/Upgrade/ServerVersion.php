<?php

declare(strict_types=1);

namespace Idno\Upgrade;

/**
 * The database servers Idno supports: MySQL 8.0 or later, MariaDB 10.6 or later (SQLite's floor is
 * checked when a connection is set up). The version is the one the server reports when the driver
 * connects (PDO::ATTR_SERVER_VERSION: no statement is sent), e.g. `8.0.46`, `10.6.28-MariaDB-ubu2004`
 * or, through older client libraries, `5.5.5-10.4.32-MariaDB` (MariaDB's replication prefix).
 *
 * A static helper, not a container service.
 */
final class ServerVersion
{
    public const MYSQL_FLOOR = '8.0';

    public const MARIADB_FLOOR = '10.6';

    public const REQUIREMENT = 'Idno needs MySQL ' . self::MYSQL_FLOOR . ' or later, or MariaDB ' . self::MARIADB_FLOOR . ' or later.';

    /**
     * Why a server that reports this version cannot be used, as a PublicMessage built from the parsed
     * product and version (PublicMessage::unsupportedServer()), or null when it is supported. A version
     * that cannot be read is not refused: the floors exist to give a clear message, never to lock a
     * working site out.
     */
    public static function refusal(string $reported): ?PublicMessage
    {
        $mariaDb = stripos($reported, 'mariadb') !== false;
        $version = $mariaDb ? (string) preg_replace('/^5\.5\.5-/', '', $reported) : $reported;
        if (preg_match('/^(\d{1,4})\.(\d{1,4})(?:\.(\d{1,6}))?/', $version, $m) !== 1) {
            return null;
        }
        $floor = $mariaDb ? self::MARIADB_FLOOR : self::MYSQL_FLOOR;
        if (version_compare($m[1] . '.' . $m[2], $floor, '>=')) {
            return null;
        }

        return PublicMessage::unsupportedServer($mariaDb ? 'MariaDB' : 'MySQL', $m[1] . '.' . $m[2] . (isset($m[3]) ? '.' . $m[3] : ''));
    }
}
