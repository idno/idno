<?php

declare(strict_types=1);

namespace Tests\Storage;

use Idno\Storage\Criteria;
use Idno\Storage\RecordStore;

/**
 * Adapter used only by tests: the legacy MySQL backend (Idno\Data\MySQL, which was
 * Idno::site()->db() before Task 7; the test constructs it, since db() no longer is one)
 * behind RecordStore. It translates Criteria back into the legacy search array
 * (toLegacyArray()) and the legacy return conventions (false for "nothing found") into the
 * interface's (null, empty array) and nothing else.
 */
final class LegacyBackendBinding implements RecordStore
{
    private readonly \Idno\Data\MySQL $db;

    /**
     * @param \Idno\Data\MySQL|null $db the legacy backend; by default a new one (its own connection)
     */
    public function __construct(?\Idno\Data\MySQL $db = null)
    {
        $this->db = $db ?? new \Idno\Data\MySQL();
    }

    public function save(string $collection, array $record): string|false
    {
        $id = $this->db->saveRecord($collection, $record);

        return $id === false ? false : (string)$id;
    }

    public function findById(string $collection, string $id): ?array
    {
        $record = $this->db->getRecord($id, $collection);

        return is_array($record) ? $record : null;
    }

    public function findByUuid(string $collection, string $uuid): ?array
    {
        $record = $this->db->getRecordByUUID($uuid, $collection);

        return is_array($record) ? $record : null;
    }

    public function findLatest(string $collection): ?array
    {
        $record = $this->db->getAnyRecord($collection);

        return is_array($record) ? $record : null;
    }

    public function find(string $collection, Criteria $criteria, int $limit, int $offset): array
    {
        $records = $this->db->getRecords([], $criteria->toLegacyArray(), $limit, $offset, $collection);

        return is_array($records) ? $records : [];
    }

    public function count(string $collection, Criteria $criteria): int
    {
        $count = $this->db->countRecords($criteria->toLegacyArray(), $collection);
        if ($count === false) {
            throw new \RuntimeException('countRecords() failed');
        }

        return (int)$count;
    }

    public function delete(string $collection, string $id): bool
    {
        return (bool)$this->db->deleteRecord($id, $collection);
    }

    public function deleteAll(string $collection): bool
    {
        return (bool)$this->db->deleteAllRecords($collection);
    }
}
