<?php

declare(strict_types=1);

namespace Tests\Storage;

use Idno\Storage\Criteria;
use Idno\Storage\RecordStore;

/**
 * Adapter used only by tests: today's Idno::site()->db() behind RecordStore. It
 * translates Criteria back into the legacy search array (toLegacyArray()) and the
 * legacy return conventions (false for "nothing found") into the interface's
 * (null, empty array) and nothing else.
 */
final class LegacyBackendBinding implements RecordStore
{
    public function save(string $collection, array $record): string|false
    {
        $id = \Idno\Core\Idno::site()->db()->saveRecord($collection, $record);

        return $id === false ? false : (string)$id;
    }

    public function findById(string $collection, string $id): ?array
    {
        $record = \Idno\Core\Idno::site()->db()->getRecord($id, $collection);

        return is_array($record) ? $record : null;
    }

    public function findByUuid(string $collection, string $uuid): ?array
    {
        $record = \Idno\Core\Idno::site()->db()->getRecordByUUID($uuid, $collection);

        return is_array($record) ? $record : null;
    }

    public function findLatest(string $collection): ?array
    {
        $record = \Idno\Core\Idno::site()->db()->getAnyRecord($collection);

        return is_array($record) ? $record : null;
    }

    public function find(string $collection, Criteria $criteria, int $limit, int $offset): array
    {
        $records = \Idno\Core\Idno::site()->db()->getRecords([], $criteria->toLegacyArray(), $limit, $offset, $collection);

        return is_array($records) ? $records : [];
    }

    public function count(string $collection, Criteria $criteria): int
    {
        $count = \Idno\Core\Idno::site()->db()->countRecords($criteria->toLegacyArray(), $collection);
        if ($count === false) {
            throw new \RuntimeException('countRecords() failed');
        }

        return (int)$count;
    }

    public function delete(string $collection, string $id): bool
    {
        return (bool)\Idno\Core\Idno::site()->db()->deleteRecord($id, $collection);
    }

    public function deleteAll(string $collection): bool
    {
        return (bool)\Idno\Core\Idno::site()->db()->deleteAllRecords($collection);
    }
}
