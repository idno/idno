<?php

declare(strict_types=1);

namespace Idno\Storage;

use Doctrine\DBAL\Connection;

/**
 * A collection's table as SQL, a few rows at a time: one `insert into idno_<collection> (<columns>) values
 * (<values>);` line per row, each followed by an empty line, in the order of the table's `id`. Only the
 * row's own columns are written, never a generated one (an insert cannot set it). The values are quoted
 * by the database's own driver and nothing is interpolated but the table name, which is reduced to
 * letters, digits and underscores. The records are as stored: a user's password hash and keys included, so
 * that the dump restores the site (the archive that holds it is for administrators only).
 *
 * A collection that has no table gives nothing.
 */
final class CollectionDump
{
    /** The columns of a collection's table that are not generated. */
    private const COLUMNS = ['id', 'uuid', 'type', 'owner', 'created', 'publish_status', 'data', 'search'];

    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaCatalog $catalog,
    )
    {
    }

    /**
     * @return \Generator<int, string> the lines of SQL of each batch of rows
     */
    public function inserts(string $collection, int $batch = 20): \Generator
    {
        $table = JsonRecordStore::tableName($collection);
        if ($table === null || !$this->catalog->hasTable($table)) {
            return;
        }
        $select = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', self::COLUMNS));
        $names = '(' . implode(',', array_map(static fn (string $column): string => '`' . $column . '`', self::COLUMNS)) . ')';
        $sql = 'select ' . $select . ' from ' . $table . ' where `id` > ? order by `id` limit ' . max(1, $batch);

        $after = '';
        while (true) {
            $rows = $this->connection->fetchAllAssociative($sql, [$after]);
            if ($rows === []) {
                return;
            }
            $output = '';
            foreach ($rows as $row) {
                $values = array_map(fn (mixed $value): string => $this->connection->quote((string) $value), array_values($row));
                $output .= 'insert into ' . $table . ' ' . $names . ' values (' . implode(',', $values) . ");\n\n";
            }
            yield $output;
            $after = (string) end($rows)['id'];
            if (count($rows) < $batch) {
                return;
            }
        }
    }
}
