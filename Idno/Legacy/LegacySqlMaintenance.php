<?php

// No strict_types here, on purpose: this is legacy backend code moved unchanged, and it passes
// database values to PHP functions as the legacy backend did.

namespace Idno\Legacy;

use Doctrine\DBAL\Connection;

/**
 * The SQL the legacy db() API needs beyond the record store, moved unchanged in substance from
 * Idno\Data\MySQL for RepositoryDataConcierge: optimize(), exportRecords() and the connection check
 * of reconnectIfNeeded(). It runs on the native PDO of the shared DBAL connection.
 *
 * The statements are MySQL's. On SQLite: export() works (SQLite accepts `limit <offset>,<count>` and
 * back-quoted names); optimize() fails on its first statement, which is logged and ignored, as on
 * MySQL it returns false whatever happens.
 *
 * Not a container service: RepositoryDataConcierge builds it.
 */
final class LegacySqlMaintenance
{
    /**
     * Optimize tables - this can reduce overall database storage space and query time
     * (Idno\Data\MySQL::optimize()). Always false, as before.
     *
     * @return bool
     */
    public function optimize(\PDO $client)
    {
        try {
            $client->query("optimize table entities");
            $client->query("optimize table entities_metadata");
            $client->query("optimize table entities_search");
            $client->query("optimize table config");
            $client->query("optimize table config_metadata");
            $client->query("optimize table config_search");
            $client->query("optimize table reader");
            $client->query("optimize table reader_metadata");
            $client->query("optimize table reader_search");
            $client->query("optimize table session");
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        return false;
    }

    /**
     * Export a collection as SQL (Idno\Data\MySQL::exportRecords()): `insert` statements for a page
     * of the main table's rows, each followed by its metadata rows. The collection must already be
     * sanitised. Values are quoted by the same PDO (legacy quoted them with db()->getClient(), which
     * is this PDO). False, logged through $logger, when a statement fails.
     *
     * @param \Psr\Log\LoggerInterface $logger the site's logger (legacy: Idno::site()->logging())
     *
     * @return bool|string
     */
    public function export(\PDO $client, $collection, $limit, $offset, \Psr\Log\LoggerInterface $logger)
    {
        try {
            $limit = (int)$limit;
            $offset = (int)$offset;

            $file   = tempnam(\Idno\Core\Idno::site()->config()->getTempDir(), 'sqldump');
            /* @var \PDO $client */
            $statement = $client->prepare("select * from {$collection} limit {$offset},{$limit}");
            $output    = '';
            if ($response = $statement->execute()) {
                while ($object = $statement->fetch(\PDO::FETCH_ASSOC)) {
                    $uuid   = $object['uuid'];
                    $fields = array_keys($object);
                    $fields = array_map(
                        function ($v) {
                            return '`' . $v . '`';
                        }, $fields
                    );
                    $object = array_map(
                        function ($v) use ($client) {
                            return $client->quote($v);
                        }, $object
                    );
                    $line   = 'insert into ' . $collection . ' ';
                    $line .= '(' . implode(',', $fields) . ')';
                    $line .= ' values ';
                    $line .= '(' . implode(',', $object) . ');';
                    $output .= $line . "\n";
                    $metadata_statement = $client->prepare("select * from {$collection}_metadata where `_id` = :uuid");
                    if ($metadata_response = $metadata_statement->execute([':uuid' => $uuid])) {
                        while ($object = $metadata_statement->fetch(\PDO::FETCH_ASSOC)) {
                            $fields = array_keys($object);
                            $fields = array_map(
                                function ($v) {
                                    return '`' . $v . '`';
                                }, $fields
                            );
                            $object = array_map(
                                function ($v) use ($client) {
                                    return $client->quote($v);
                                }, $object
                            );
                            $line   = "insert into {$collection}_metadata ";
                            $line .= '(' . implode(',', $fields) . ')';
                            $line .= ' values ';
                            $line .= '(' . implode(',', $object) . ');';
                            $output .= $line . "\n";
                        }
                        unset($metadata_statement);
                        gc_collect_cycles();    // Clean memory
                    }
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
