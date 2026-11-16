<?php

declare(strict_types=1);

namespace Idno\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Idno\Storage\Platform\Dialect;

/**
 * A RecordStore that serves the collection `config` from the key/value table `idno_settings` and
 * passes every other collection to the store it decorates, unchanged.
 *
 * The legacy application keeps the site's configuration as ONE document in `config` (it reads
 * "the latest record of `config`" and saves "this record to `config`"). Here that document is one
 * row of `idno_settings` per top-level key: `name` (the key, case-sensitive, at most 190 bytes),
 * `value` (the key's value as JSON text, encoded as JsonRecordStore encodes a document) and
 * `position` (the key's index in the document, so that the keys come back in the order they were
 * saved). The collection holds at most one document (a save with another _id replaces it).
 *
 *  - save() fills in the defaults as the inner store does, then, in one transaction, deletes EVERY
 *    row and inserts one row per key. The delete comes first because it is a write that depends on
 *    no earlier read: it takes the write lock before anything is decided, so two saves are
 *    serialised and the table always holds exactly one of the two documents (a read of the existing
 *    keys, then a delete of the ones that are gone, let a concurrent save's new key survive). A
 *    save that cannot get the lock in time (a lock wait timeout, a deadlock, SQLite's busy timeout)
 *    is refused data: false, the previous document intact. It returns the _id.
 *  - A save the data makes impossible returns false and leaves the previous document untouched:
 *    a value that cannot be encoded as JSON, an empty key, a key longer than 190 bytes or that is
 *    not UTF-8, a key ending in a space (utf8mb4_bin compares as if padded with spaces, so on MySQL
 *    and MariaDB it would be the same key as its neighbour without the space: refused on every
 *    engine instead of merged on one), and what the database refuses (Dialect::isRefusedData()).
 *    A `created` that is not a timestamp throws \InvalidArgumentException, as the other stores do.
 *    Every other failure is thrown after the transaction is rolled back. A missing `idno_settings`
 *    is such a failure.
 *  - findLatest(), findById(), findByUuid(), find() and count() assemble the document from the
 *    rows (json_decode($value, true), in the order of `position`): the document as
 *    JsonRecordStore would read it (an integer key of a document is a string key of the table and
 *    comes back as an int key, as json_decode() gives it). find() and count() evaluate the criteria
 *    on that one document with an InMemoryRecordStore (the reference implementation of the rules),
 *    so a collection of at most one record answers as the contract expects of one.
 *  - delete() is atomic: in one transaction, a write that deletes the `_id` row only if it still
 *    holds the id (it also takes that row's lock), then, when it did, every other row. Another
 *    id, or a document that replaced it a moment ago, is "no such record" (true) and nothing is
 *    removed. deleteAll() removes every row.
 *
 * Not registered by the container's directory scan (see config/services.php); built with new. The
 * site does not use it yet.
 */
final class SettingsRecordStore implements RecordStore
{
    /** The collection this store serves from `idno_settings`. */
    public const COLLECTION = 'config';

    public const TABLE = 'idno_settings';

    /** The longest key, in bytes (the column is VARCHAR(190)). */
    public const MAX_KEY_BYTES = 190;

    public function __construct(
        private readonly RecordStore $inner,
        private readonly Connection $connection,
        private readonly Dialect $dialect,
        private readonly RecordDefaults $defaults,
    )
    {
    }

    /**
     * The rows a document is stored as: key => the key's value as JSON text, in the order of the
     * document (the index of a row is its `position`). Pure: it reads and writes nothing and
     * applies no defaults. An integer key is a string key of the table (PHP shows it as an int
     * again in the array this returns: cast it).
     *
     * @param array<int|string, mixed> $document
     *
     * @return array<int|string, string>
     *
     * @throws \InvalidArgumentException when the table cannot hold the document: a value that cannot
     *                                    be encoded as JSON, an empty key, a key over 190 bytes or
     *                                    not UTF-8, or a key ending in a space
     */
    public static function rowsOf(array $document): array
    {
        $rows = [];
        foreach ($document as $key => $value) {
            $name = (string) $key;
            if ($name === '') {
                throw new \InvalidArgumentException('A setting\'s name cannot be empty.');
            }
            if (strlen($name) > self::MAX_KEY_BYTES) {
                throw new \InvalidArgumentException(sprintf('A setting\'s name is at most %d bytes (this one has %d).', self::MAX_KEY_BYTES, strlen($name)));
            }
            if (!mb_check_encoding($name, 'UTF-8')) {
                throw new \InvalidArgumentException('A setting\'s name must be UTF-8.');
            }
            if (str_ends_with($name, ' ')) {
                throw new \InvalidArgumentException('A setting\'s name cannot end in a space.');
            }
            $json = json_encode($value);
            if ($json === false) {
                throw new \InvalidArgumentException(sprintf('The value of the setting "%s" cannot be encoded as JSON: %s.', $name, json_last_error_msg()));
            }
            $rows[$key] = $json;
        }

        return $rows;
    }

    public function save(string $collection, array $record): string|false
    {
        if (!self::serves($collection)) {
            return $this->inner->save($collection, $record);
        }

        if (empty($record['_id'])) {
            $record['_id'] = $this->defaults->generateId();
        }
        if (empty($record['siteid'])) {
            $record['siteid'] = $this->defaults->currentSiteId();
        }
        if (empty($record['uuid'])) {
            $record['uuid'] = $this->defaults->siteUrl() . 'view/' . (is_scalar($record['_id']) ? (string) $record['_id'] : '');
        }
        if (empty($record['owner'])) {
            $record['owner'] = '';
        }

        try {
            $rows = self::rowsOf($record);
        } catch (\InvalidArgumentException) {
            return false;
        }
        // The same record the other stores refuse: `created` must be a timestamp (throws).
        $id = RecordIndex::columns($record, time())['_id'];

        $table = $this->dialect->quoteIdentifier(self::TABLE);
        $insert = 'insert into ' . $table . ' (' . implode(', ', array_map($this->dialect->quoteIdentifier(...), ['name', 'value', 'position'])) . ') values (?, ?, ?)';

        try {
            try {
                $this->connection->beginTransaction();
                // The first statement is a write that depends on no earlier read: it takes the write
                // lock (every row, on MySQL and MariaDB; the database, on SQLite) before anything is
                // decided, so two saves are serialised and the table holds one document, never a mix.
                $this->connection->executeStatement('delete from ' . $table);
                $position = 0;
                foreach ($rows as $key => $json) {
                    $this->connection->executeStatement($insert, [(string) $key, $json, (string) $position++]);
                }
                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->rollBack();
                throw $e;
            }
        } catch (DriverException $e) {
            if (!$this->dialect->isRefusedData($e)) {
                throw $e;
            }

            return false;
        }

        return $id;
    }

    public function findById(string $collection, string $id): ?array
    {
        if (!self::serves($collection)) {
            return $this->inner->findById($collection, $id);
        }
        $document = $this->document();

        return $document !== null && is_scalar($document['_id'] ?? null) && (string) $document['_id'] === $id ? $document : null;
    }

    public function findByUuid(string $collection, string $uuid): ?array
    {
        if (!self::serves($collection)) {
            return $this->inner->findByUuid($collection, $uuid);
        }
        $document = $this->document();

        return $document !== null && is_scalar($document['uuid'] ?? null) && (string) $document['uuid'] === $uuid ? $document : null;
    }

    public function findLatest(string $collection): ?array
    {
        return self::serves($collection) ? $this->document() : $this->inner->findLatest($collection);
    }

    public function find(string $collection, Criteria $criteria, int $limit, int $offset): array
    {
        if (!self::serves($collection)) {
            return $this->inner->find($collection, $criteria, $limit, $offset);
        }
        if ($limit <= 0 || $offset < 0) {
            return [];
        }
        $document = $this->document();
        if ($document === null) {
            return [];
        }

        return $this->evaluator($document)->find(self::COLLECTION, $criteria, $limit, $offset) === [] ? [] : [$document];
    }

    public function count(string $collection, Criteria $criteria): int
    {
        if (!self::serves($collection)) {
            return $this->inner->count($collection, $criteria);
        }
        $document = $this->document();

        return $document === null ? 0 : $this->evaluator($document)->count(self::COLLECTION, $criteria);
    }

    public function delete(string $collection, string $id): bool
    {
        if (!self::serves($collection)) {
            return $this->inner->delete($collection, $id);
        }
        $json = json_encode($id);
        $candidates = $json === false ? [] : [$json];
        if (preg_match('/^(0|-?[1-9][0-9]{0,17})$/', $id) === 1) {
            $candidates[] = $id; // a document whose _id is the integer
        }
        if ($candidates === []) {
            return true;
        }
        $table = $this->dialect->quoteIdentifier(self::TABLE);

        try {
            try {
                $this->connection->beginTransaction();
                // One conditional write is the check and the lock: it removes the `_id` row only
                // when it still holds this id, and holds that row's lock until the rest is gone, so
                // a save that replaced the document first makes it match nothing, and a save that
                // comes after waits for the commit.
                $matched = (int) $this->connection->executeStatement(
                    'delete from ' . $table . ' where ' . $this->dialect->quoteIdentifier('name') . ' = ? and '
                    . $this->dialect->quoteIdentifier('value') . ' in (' . implode(', ', array_fill(0, count($candidates), '?')) . ')',
                    ['_id', ...$candidates],
                );
                if ($matched > 0) {
                    $this->connection->executeStatement('delete from ' . $table);
                }
                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->rollBack();
                throw $e;
            }
        } catch (DriverException $e) {
            if (!$this->dialect->isRefusedData($e)) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    public function deleteAll(string $collection): bool
    {
        return self::serves($collection) ? $this->deleteRows() : $this->inner->deleteAll($collection);
    }

    /**
     * Whether the collection is the settings: its name reduced to letters, digits and underscores
     * and lower-cased, as the other stores reduce a collection name.
     */
    private static function serves(string $collection): bool
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $collection) ?? '') === self::COLLECTION;
    }

    /**
     * The document the rows make, in the order of `position`, or null when there are none.
     *
     * @return array<int|string, mixed>|null
     *
     * @throws \JsonException when a row's value is not JSON (a database written by something else)
     */
    private function document(): ?array
    {
        $rows = $this->connection->fetchAllAssociative(
            'select ' . $this->dialect->quoteIdentifier('name') . ', ' . $this->dialect->quoteIdentifier('value')
            . ' from ' . $this->dialect->quoteIdentifier(self::TABLE)
            . ' order by ' . $this->dialect->quoteIdentifier('position') . ', ' . $this->dialect->quoteIdentifier('name'),
        );
        if ($rows === []) {
            return null;
        }
        $document = [];
        foreach ($rows as $row) {
            $document[(string) $row['name']] = json_decode((string) $row['value'], true, 512, JSON_THROW_ON_ERROR);
        }

        return $document;
    }

    /**
     * An in-memory store holding the document, which evaluates criteria as the contract says.
     * A document it cannot hold (a number in a row that decodes to INF, which has no JSON text) is
     * a failure, thrown by find() and count(): never taken for an empty collection. The plain
     * finders return such a document as it is.
     *
     * @param array<int|string, mixed> $document
     */
    private function evaluator(array $document): InMemoryRecordStore
    {
        $memory = new InMemoryRecordStore($this->defaults, null, [self::COLLECTION]);
        if ($memory->save(self::COLLECTION, $document) === false) {
            throw new \UnexpectedValueException('The stored settings cannot be searched: a value of the document cannot be encoded as JSON again (a number too large for a float, for example).');
        }

        return $memory;
    }

    /**
     * `delete from idno_settings`: true, or false when the database refuses it.
     */
    private function deleteRows(): bool
    {
        try {
            $this->connection->executeStatement('delete from ' . $this->dialect->quoteIdentifier(self::TABLE));
        } catch (DriverException $e) {
            if (!$this->dialect->isRefusedData($e)) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    private function rollBack(): void
    {
        if (!$this->connection->isTransactionActive()) {
            return;
        }
        try {
            $this->connection->rollBack();
        } catch (\Throwable) {
            // The original error is what the caller needs to see (a lost connection fails both).
        }
    }
}
