<?php

declare(strict_types=1);

namespace Idno\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * What exists in the database: its tables, and the fields of each collection that have an index.
 *
 * Everything is read with ONE statement, at the first question, and kept until forget() (call it
 * after DDL): on MySQL and MariaDB `information_schema.columns` for the current database, on
 * SQLite `sqlite_master`. Constructing it queries nothing.
 *
 * An indexed field `f` of collection `c` is, on MySQL and MariaDB, a generated column `ix_<f>` of
 * the table `idno_<c>`; on SQLite, an index named `idno_<c>_ix_<f>` on that table. Field names
 * match `[a-z0-9_]{1,48}`; anything else of that shape is ignored. Names are compared without
 * regard to case and returned in lower case.
 */
final class SchemaCatalog
{
    private const FIELD = '[a-z0-9_]{1,48}';

    /** @var array<string, list<string>>|null per table (lower case), its indexed fields; null until read */
    private ?array $tables = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Every table in the database, in lower case, sorted (SQLite's internal tables left out).
     *
     * @return list<string>
     */
    public function tables(): array
    {
        return array_keys($this->read());
    }

    public function hasTable(string $table): bool
    {
        return array_key_exists(strtolower($table), $this->read());
    }

    /**
     * The fields of the collection that have an index on its table `idno_<collection>`, sorted;
     * [] when there are none or the table does not exist.
     *
     * @return list<string>
     */
    public function indexedFields(string $collection): array
    {
        return $this->read()['idno_' . strtolower($collection)] ?? [];
    }

    /**
     * Forgets what was read, so the next question reads the database again.
     */
    public function forget(): void
    {
        $this->tables = null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function read(): array
    {
        if ($this->tables !== null) {
            return $this->tables;
        }
        $platform = $this->connection->getDatabasePlatform();
        $tables = [];
        if ($platform instanceof AbstractMySQLPlatform) {
            $rows = $this->connection->fetchAllNumeric('select table_name, column_name from information_schema.columns where table_schema = database()');
            foreach ($rows as [$table, $column]) {
                $table = strtolower((string) $table);
                $tables[$table] ??= [];
                if (str_starts_with($table, 'idno_') && preg_match('/^ix_(' . self::FIELD . ')$/', strtolower((string) $column), $m) === 1) {
                    $tables[$table][] = $m[1];
                }
            }
        } elseif ($platform instanceof SQLitePlatform) {
            $rows = $this->connection->fetchAllNumeric("select type, name, tbl_name from sqlite_master where type in ('table', 'index')");
            $indexes = [];
            foreach ($rows as [$type, $name, $table]) {
                $name = strtolower((string) $name);
                $table = strtolower((string) $table);
                if (str_starts_with($table, 'sqlite_')) {
                    continue;
                }
                if ($type === 'table') {
                    $tables[$table] ??= [];
                } else {
                    $indexes[] = [$name, $table];
                }
            }
            foreach ($indexes as [$name, $table]) {
                if (isset($tables[$table]) && str_starts_with($table, 'idno_') && preg_match('/^' . preg_quote($table, '/') . '_ix_(' . self::FIELD . ')$/', $name, $m) === 1) {
                    $tables[$table][] = $m[1];
                }
            }
        } else {
            throw new \LogicException(sprintf('The schema catalog supports MySQL, MariaDB and SQLite only, not %s.', $platform::class));
        }
        ksort($tables);
        foreach ($tables as &$fields) {
            $fields = array_values(array_unique($fields));
            sort($fields);
        }
        unset($fields);

        return $this->tables = $tables;
    }
}
