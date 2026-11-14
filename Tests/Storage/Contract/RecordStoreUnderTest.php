<?php

declare(strict_types=1);

namespace Tests\Storage\Contract;

/**
 * The record storage operations the contract exercises.
 *
 * Test-only for now: Task 3 creates Idno\Storage\RecordStore with identical
 * signatures and deletes this interface. Until then the contract passes search
 * criteria as the legacy (MongoDB-style) search array, for example
 * ['slug' => 'x'], ['tags' => ['$in' => [...]]], ['$or' => [...]] or
 * ['$search' => ['q']].
 */
interface RecordStoreUnderTest
{
    /**
     * Insert or update a record; returns its _id, or false when it could not be saved.
     */
    public function save(string $collection, array $record): string|false;

    public function findById(string $collection, string $id): ?array;

    public function findByUuid(string $collection, string $uuid): ?array;

    /**
     * The record with the newest `created` in the collection.
     */
    public function findLatest(string $collection): ?array;

    /**
     * Records matching the criteria, newest `created` first. An empty result is [].
     *
     * @param array $legacySearch Legacy search array (Task 3 switches this to Idno\Storage\Criteria)
     * @return list<array>
     */
    public function find(string $collection, array $legacySearch, int $limit, int $offset): array;

    /**
     * Number of distinct records matching the criteria.
     *
     * @param array $legacySearch Legacy search array (Task 3 switches this to Idno\Storage\Criteria)
     */
    public function count(string $collection, array $legacySearch): int;

    public function delete(string $collection, string $id): bool;

    public function deleteAll(string $collection): bool;
}
