<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * Persistence of records (the JSON documents behind entities, reader items, configuration and
 * so on), grouped in named collections.
 *
 * The semantics are those of today's MySQL backend (Idno\Data\MySQL), odd corners included,
 * as pinned by Tests/Storage/Contract/RecordStoreContract. A collection name is reduced to
 * letters, digits and underscores; a name that is not a known collection makes writes fail
 * (false) and reads find nothing, it never reaches another collection.
 *
 * Implementations are not services in the container: they are built by the code that wires
 * the storage (see config/services.php).
 */
interface RecordStore
{
    /**
     * Inserts or replaces by _id. Returns the _id, or false on failure (as saveRecord() does today).
     *
     * A missing _id, siteid, uuid or owner is filled in first (see RecordDefaults). It returns
     * false only when nothing was stored (for example another record already has the uuid) and
     * the id only when the record was stored.
     *
     * @param array<string, mixed> $record
     */
    public function save(string $collection, array $record): string|false;

    /**
     * @return array<string, mixed>|null null when there is no such record
     */
    public function findById(string $collection, string $id): ?array;

    /**
     * @return array<string, mixed>|null null when there is no such record
     */
    public function findByUuid(string $collection, string $uuid): ?array;

    /**
     * The record with the newest `created` in the collection.
     *
     * @return array<string, mixed>|null null when the collection is empty
     */
    public function findLatest(string $collection): ?array;

    /**
     * Distinct records matching the criteria, newest `created` first, then paged.
     *
     * @return list<array<string, mixed>> newest first by `created`
     */
    public function find(string $collection, Criteria $criteria, int $limit, int $offset): array;

    /**
     * The number of records find() would return without paging.
     */
    public function count(string $collection, Criteria $criteria): int;

    /**
     * Deletes the record (and everything indexed for it). Deleting an unknown id succeeds.
     */
    public function delete(string $collection, string $id): bool;

    /**
     * Deletes every record in the collection.
     */
    public function deleteAll(string $collection): bool;
}
