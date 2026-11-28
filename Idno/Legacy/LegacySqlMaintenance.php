<?php

// No strict_types here, on purpose: this is legacy backend code, and it passes database values to
// PHP functions as the legacy backend did.

namespace Idno\Legacy;

use Doctrine\DBAL\Connection;

/**
 * The SQL the legacy db() API needs beyond the record store, for RepositoryDataConcierge:
 * optimize() (statistics, not a rebuild), exportRecords() and the connection check of reconnectIfNeeded() (from the old
 * backend, Idno\Data\MySQL, since removed), on the tables of the schema migrations. It runs on the
 * native PDO of the shared DBAL connection.
 *
 * Not a container service: RepositoryDataConcierge builds it.
 */
final class LegacySqlMaintenance
{
    /** The tables optimize() works on: the collections, the settings and the sessions. */
    public const TABLES = ['idno_entities', 'idno_reader', 'idno_site', 'idno_settings', 'idno_sessions'];

    /**
     * The statistics refresh the old daily "optimise" ran (nothing calls it on a schedule now):
     * on MySQL and MariaDB `analyze table` on each of TABLES, which refreshes the index statistics
     * the query planner uses without rebuilding the table or blocking writes; SQLite's
     * `pragma optimize`. Not `optimize table`: on InnoDB that rebuilds the whole table (a copy that
     * holds up writes, and with the full-text index and the generated columns of the collection
     * tables a long one). A failure is written to PHP's error log and ends the work. Always false,
     * as the old backend's.
     *
     * @return bool
     */
    public function optimize(\PDO $client)
    {
        try {
            if ($client->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $client->query('pragma optimize');
            } else {
                foreach (self::TABLES as $table) {
                    $client->query("analyze table {$table}")->fetchAll();
                }
            }
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        return false;
    }

    /**
     * Export a page of a table's rows as SQL (as Idno\Data\MySQL::exportRecords() did for its own
     * tables): one `insert into <table> (<columns>) values (<values>);` line per row, each followed by
     * an empty line, in the table's own order; '' for a page past the end. Only the columns given are
     * exported (never a generated column, which an insert cannot set). The table and the columns
     * must already be sanitised. Values are quoted by the same PDO. False, logged through $logger,
     * when a statement fails (a table that does not exist, for example).
     *
     * @param list<string>             $columns
     * @param \Psr\Log\LoggerInterface $logger  the site's logger (legacy: Idno::site()->logging())
     *
     * @return bool|string
     */
    public function export(\PDO $client, $table, array $columns, $limit, $offset, \Psr\Log\LoggerInterface $logger)
    {
        try {
            $limit = (int)$limit;
            $offset = (int)$offset;

            $file   = tempnam(\Idno\Core\Idno::site()->config()->getTempDir(), 'sqldump');
            /* @var \PDO $client */
            $select = implode(', ', array_map(static fn ($column) => '`' . $column . '`', $columns));
            $statement = $client->prepare("select {$select} from {$table} limit {$offset},{$limit}");
            $output    = '';
            if ($response = $statement->execute()) {
                while ($object = $statement->fetch(\PDO::FETCH_ASSOC)) {
                    $fields = array_keys($object);
                    $fields = array_map(
                        function ($v) {
                            return '`' . $v . '`';
                        }, $fields
                    );
                    $object = array_map(
                        function ($v) use ($client) {
                            return $client->quote((string)$v);
                        }, $object
                    );
                    $line   = 'insert into ' . $table . ' ';
                    $line .= '(' . implode(',', $fields) . ')';
                    $line .= ' values ';
                    $line .= '(' . implode(',', $object) . ');';
                    $output .= $line . "\n";
                    $output .= "\n";
                    unset($object);
                    unset($fields);
                    gc_collect_cycles();    // Clean memory
                }
            }

            return $output;
        } catch (\Exception $e) {
            $logger->error($e->getMessage());

            return false;
        }
    }

    /**
     * Idno\Data\MySQL::reconnectIfNeeded(): checks that the connection is alive and, when it is not
     * (a long-running worker can outlive the server's wait_timeout), closes it so that DBAL opens a
     * new one. Returns the native PDO to use from now on (the same one when the connection was alive).
     * A failure to reconnect propagates, as it did.
     */
    public function reconnectIfNeeded(Connection $connection): \PDO
    {
        try {
            $connection->getNativeConnection()->query('SELECT 1');
        } catch (\Exception $e) {
            error_log('MySQL connection lost, reconnecting: ' . $e->getMessage());
            $connection->close();
        }

        return $connection->getNativeConnection();
    }
}
