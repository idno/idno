<?php

declare(strict_types=1);

namespace Idno\Upgrade;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * The lock that lets one process at a time work on the upgrade of a database. It never waits:
 * acquire() returns false at once when another process holds it.
 *
 *  - MySQL and MariaDB: the named lock `idno_upgrade_` + the first 16 hex digits of the SHA-1 of
 *    the database's name (GET_LOCK(name, 0)). A named lock belongs to the database session that took
 *    it: when the connection is lost the server releases it, and a new connection does not hold it.
 *    holds() asks the server (IS_USED_LOCK(name) = CONNECTION_ID()) on the connection as it is now,
 *    so after a reconnection it is false: LegacyDataCopier checks it inside each transaction it
 *    writes in, before writing. GET_LOCK() is re-entrant (a session that takes it twice must release
 *    it twice): acquire() never takes it on a session that holds it, and release() releases until
 *    the session holds it no more, so the two stay balanced even after a release() that failed.
 *  - SQLite: an exclusive, non-blocking flock() on `<database file>.upgrade.lock` (created when
 *    missing, never deleted: deleting a lock file would let two processes lock two different files).
 *    An in-memory database belongs to its connection alone, so it always gets the lock.
 *
 * Constructing it queries nothing.
 */
final class UpgradeLock
{
    private const PREFIX = 'idno_upgrade_';

    /** @var resource|null the open lock file (SQLite) */
    private $file = null;

    private bool $memory = false;

    /** RELEASE_LOCK() calls at most, to free a lock a session took more than once. */
    private const MAX_RELEASES = 100;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Takes the lock if no other process holds it: true when this instance holds it now (also when
     * it held it already), false at once otherwise.
     */
    public function acquire(): bool
    {
        if ($this->isMySql()) {
            // GET_LOCK() is re-entrant within a session (each call must be released once): never
            // take it on a session that holds it already.
            if ($this->holds()) {
                return true;
            }

            return (int) $this->connection->fetchOne('select get_lock(?, 0)', [$this->name()]) === 1;
        }

        $path = $this->databaseFile();
        if ($path === null) {
            return $this->memory = true;
        }
        if ($this->file !== null) {
            return true;
        }
        $file = @fopen($path . '.upgrade.lock', 'c');
        if ($file === false) {
            throw new \RuntimeException(sprintf('The upgrade lock file %s.upgrade.lock cannot be opened.', $path));
        }
        if (!flock($file, LOCK_EX | LOCK_NB)) {
            fclose($file);

            return false;
        }
        $this->file = $file;

        return true;
    }

    /**
     * Whether this instance holds the lock now. On MySQL and MariaDB it asks the server, on the
     * connection as it is now.
     */
    public function holds(): bool
    {
        if ($this->isMySql()) {
            return (int) $this->connection->fetchOne('select coalesce(is_used_lock(?) = connection_id(), 0)', [$this->name()]) === 1;
        }

        return $this->file !== null || $this->memory;
    }

    /**
     * Gives the lock up (nothing happens when this instance does not hold it).
     */
    public function release(): void
    {
        if ($this->isMySql()) {
            // RELEASE_LOCK() gives 1 while this session still held the lock (it counts how often it
            // was taken), 0 or NULL otherwise: release until the session holds it no more.
            for ($i = 0; $i < self::MAX_RELEASES; ++$i) {
                if ((int) $this->connection->fetchOne('select release_lock(?)', [$this->name()]) !== 1 || !$this->holds()) {
                    return;
                }
            }

            return;
        }
        $this->memory = false;
        if ($this->file !== null) {
            flock($this->file, LOCK_UN);
            fclose($this->file);
            $this->file = null;
        }
    }

    /**
     * The lock's name on MySQL and MariaDB (at most 64 characters, as MySQL requires).
     */
    public function name(): string
    {
        return self::PREFIX . substr(sha1((string) $this->connection->getDatabase()), 0, 16);
    }

    private function isMySql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    /**
     * The SQLite database file, or null for an in-memory database.
     */
    private function databaseFile(): ?string
    {
        $params = $this->connection->getParams();
        $path = (string) ($params['path'] ?? '');
        if (!empty($params['memory']) || $path === '' || $path === ':memory:') {
            return null;
        }

        return $path;
    }
}
