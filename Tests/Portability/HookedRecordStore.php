<?php

declare(strict_types=1);

namespace Tests\Portability;

use Idno\Storage\Criteria;
use Idno\Storage\RecordStore;

/**
 * A RecordStore that forwards to another and tells a callback after each find() of a collection, so that a test
 * can change the data between two pages of an export.
 */
final class HookedRecordStore implements RecordStore
{
    public int $finds = 0;

    /** @param \Closure(int, list<array<string, mixed>>): void $afterFind */
    public function __construct(private readonly RecordStore $inner, private readonly string $collection, private readonly \Closure $afterFind)
    {
    }

    public function save(string $collection, array $record): string|false
    {
        return $this->inner->save($collection, $record);
    }

    public function findById(string $collection, string $id): ?array
    {
        return $this->inner->findById($collection, $id);
    }

    public function findByUuid(string $collection, string $uuid): ?array
    {
        return $this->inner->findByUuid($collection, $uuid);
    }

    public function findLatest(string $collection): ?array
    {
        return $this->inner->findLatest($collection);
    }

    public function find(string $collection, Criteria $criteria, int $limit, int $offset): array
    {
        $found = $this->inner->find($collection, $criteria, $limit, $offset);
        if ($collection === $this->collection) {
            $this->finds++;
            ($this->afterFind)($this->finds, array_values($found));
        }

        return $found;
    }

    public function count(string $collection, Criteria $criteria): int
    {
        return $this->inner->count($collection, $criteria);
    }

    public function delete(string $collection, string $id): bool
    {
        return $this->inner->delete($collection, $id);
    }

    public function deleteAll(string $collection): bool
    {
        return $this->inner->deleteAll($collection);
    }
}
