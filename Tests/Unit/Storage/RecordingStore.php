<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use Idno\Storage\Criteria;
use Idno\Storage\RecordStore;

/**
 * A RecordStore that remembers the criteria and collection of the last count() or find().
 */
final class RecordingStore implements RecordStore
{
    public ?Criteria $lastCriteria = null;

    public ?string $lastCollection = null;

    public function __construct(private readonly RecordStore $inner)
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
        $this->lastCollection = $collection;
        $this->lastCriteria = $criteria;

        return $this->inner->find($collection, $criteria, $limit, $offset);
    }

    public function count(string $collection, Criteria $criteria): int
    {
        $this->lastCollection = $collection;
        $this->lastCriteria = $criteria;

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
