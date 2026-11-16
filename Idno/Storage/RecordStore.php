<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * Persistence of records (the JSON documents behind entities, reader items, configuration and
 * so on), grouped in named collections.
 *
 * The semantics are those of the old MySQL backend (Idno\Data\MySQL, since removed), odd corners
 * included, as pinned by Tests/Storage/Contract/RecordStoreContract. A collection name is reduced to
 * letters, digits and underscores; a name that is not a known collection makes writes fail
 * (false) and reads find nothing, it never reaches another collection.
 *
 * Errors. Every implementation keeps to this contract:
 *  - a query the database cannot answer because of its operands (a rejected operand, an
 *    unparseable search term, a condition that can never hold) matches nothing: find() returns
 *    [], count() 0;
 *  - a write whose data the database refuses (a constraint, an invalid or too long value, a
 *    deadlock or lock wait) returns false (save(), delete(), deleteAll()); a record that cannot be
 *    encoded as JSON is refused the same way;
 *  - a record whose `created` is not a timestamp makes save() throw \InvalidArgumentException
 *    (RecordIndex cannot index it);
 *  - any other failure (a lost connection, a broken statement, a missing table of a known
 *    collection, a full disk) is THROWN, from every method, never turned into "not found",
 *    "no results" or false. A save() that fails leaves nothing of the record stored.
 * The return values for "nothing there" (null, [], 0) are never used for a failure.
 *
 * The container's RecordStore service is a DbalRecordStore built at runtime by a factory, on the
 * site's database connection (see config/services.php); other implementations are built with new.
 */
interface RecordStore
{
    /**
     * Inserts or replaces by _id. Returns the _id when the record was stored, false when the
     * database refused its data (for example another record already has the uuid) or it cannot be
     * encoded as JSON; nothing is stored then.
     *
     * A missing _id, siteid, uuid or owner is filled in first (see RecordDefaults).
     *
     * @param array<string, mixed> $record
     *
     * @throws \InvalidArgumentException when `created` is not a timestamp
     * @throws \Throwable                when the database fails otherwise (nothing is stored)
     */
    public function save(string $collection, array $record): string|false;

    /**
     * @return array<string, mixed>|null null when there is no such record
     *
     * @throws \Throwable when the database fails
     */
    public function findById(string $collection, string $id): ?array;

    /**
     * @return array<string, mixed>|null null when there is no such record
     *
     * @throws \Throwable when the database fails
     */
    public function findByUuid(string $collection, string $uuid): ?array;

    /**
     * The record with the newest `created` in the collection.
     *
     * @return array<string, mixed>|null null when the collection is empty
     *
     * @throws \Throwable when the database fails
     */
    public function findLatest(string $collection): ?array;

    /**
     * Distinct records matching the criteria, newest `created` first, then paged.
     *
     * @return list<array<string, mixed>> newest first by `created`; [] when nothing matches,
     *                                     including a query the database cannot answer because of
     *                                     its operands
     *
     * @throws \Throwable when the database fails otherwise
     */
    public function find(string $collection, Criteria $criteria, int $limit, int $offset): array;

    /**
     * The number of records find() would return without paging (0 for a query the database cannot
     * answer because of its operands).
     *
     * @throws \Throwable when the database fails otherwise
     */
    public function count(string $collection, Criteria $criteria): int;

    /**
     * Deletes the record (and everything indexed for it). Deleting an unknown id succeeds (true);
     * false when the database refuses the deletion.
     *
     * @throws \Throwable when the database fails otherwise
     */
    public function delete(string $collection, string $id): bool;

    /**
     * Deletes every record in the collection: true, or false when the database refuses it.
     *
     * @throws \Throwable when the database fails otherwise
     */
    public function deleteAll(string $collection): bool;
}
