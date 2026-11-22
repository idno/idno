<?php

declare(strict_types=1);

namespace Idno\Queue;

use Doctrine\DBAL\Connection;
use Idno\Storage\Criteria;
use Idno\Storage\JsonRecordStore;
use Idno\Storage\RecordStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Moves the events the previous queue stored (entities of the class Idno\Entities\AsynchronousQueuedEvent
 * that are not complete: it kept one entity per queued event) into the queue of messages, so that
 * events a site had waiting when it was upgraded are not lost. The worker runs it before it consumes.
 *
 * Each pending record, oldest first, is moved in ONE database transaction: the record is deleted
 * (the delete is the claim: a worker that finds it already gone, deleted by another worker that
 * started together with it, sends nothing) and a QueuedEvent with the same queue, event name, data
 * and user is sent; a failure of either rolls both back, so the record is never lost and never sent
 * twice. A record whose data does not unserialise, or whose send fails, is left as it is, counted in
 * `left`, and logged once with its id and event name (never its data).
 *
 * It reads the records through the RecordStore, as documents, and never builds an entity (so it
 * needs nothing of the legacy application). The one statement of its own is the claim, on the
 * entities table, because RecordStore::delete() does not say whether it removed a row.
 */
final class LegacyQueueDrain
{
    /** The class the previous queue's events were stored under (the stored `entity_subtype`). */
    public const RECORD_CLASS = 'Idno\\Entities\\AsynchronousQueuedEvent';

    private const COLLECTION = 'entities';

    private const PAGE = 200;

    public function __construct(
        private readonly Connection $connection,
        private readonly RecordStore $store,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    )
    {
    }

    /**
     * @param (\Closure(): bool)|null $shouldStop asked before each record: true ends the drain there (the
     *                                            records not yet reached stay pending; a record is never half moved)
     *
     * @return array{moved: int, left: int}
     */
    public function drain(?\Closure $shouldStop = null): array
    {
        $moved = 0;
        $left = 0;
        foreach ($this->pendingIds() as $id) {
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }
            $record = $this->store->findById(self::COLLECTION, $id);
            if ($record === null || !$this->isPending($record)) {
                continue; // moved by another worker, or completed, since the list was made
            }
            $result = $this->move($id, $record);
            if ($result === true) {
                ++$moved;
            } elseif ($result === false) {
                ++$left;
            }
        }

        return ['moved' => $moved, 'left' => $left];
    }

    /**
     * Deletes records of the previous queue that are complete (they have no further use: nothing
     * reads them, and the old garbage collector is gone), at most $limit of them, and returns how
     * many. Pending records and every other entity are untouched.
     */
    public function removeCompleted(int $limit = 500): int
    {
        $criteria = Criteria::fromLegacyArray(['entity_subtype' => self::RECORD_CLASS, 'complete' => true]);
        $removed = 0;
        foreach ($this->store->find(self::COLLECTION, $criteria, max(0, $limit), 0) as $record) {
            if (is_string($record['_id'] ?? null) && ($record['entity_subtype'] ?? null) === self::RECORD_CLASS && !empty($record['complete']) && $this->store->delete(self::COLLECTION, $record['_id'])) {
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * The ids of the pending records, oldest queued first (then by id). Only the id and the time are
     * kept, a page at a time, so the list is small however large the data of the events.
     *
     * @return list<string>
     */
    private function pendingIds(): array
    {
        $criteria = Criteria::fromLegacyArray(['entity_subtype' => self::RECORD_CLASS, 'complete' => ['$not' => ['$in' => [true]]]]);
        $keys = [];
        for ($offset = 0;; $offset += self::PAGE) {
            $page = $this->store->find(self::COLLECTION, $criteria, self::PAGE, $offset);
            foreach ($page as $record) {
                if (is_string($record['_id'] ?? null) && $this->isPending($record)) {
                    $keys[$record['_id']] = [(int) ($record['queuedTs'] ?? 0), $record['_id']];
                }
            }
            if (count($page) < self::PAGE) {
                break;
            }
        }
        uasort($keys, static fn (array $a, array $b): int => $a <=> $b);

        return array_map('strval', array_keys($keys));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function isPending(array $record): bool
    {
        return ($record['entity_subtype'] ?? null) === self::RECORD_CLASS && empty($record['complete']);
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return bool|null true: moved; false: left (reported); null: another worker moved it
     */
    private function move(string $id, array $record): ?bool
    {
        $event = is_string($record['event'] ?? null) ? $record['event'] : '';
        $data = self::unserialiseData($record['eventData'] ?? null);
        if ($data === null) {
            $this->logger->error('A queued event of the previous queue could not be moved: its data cannot be read. Event ' . self::printable($id) . ' (' . self::printable($event) . ') was left where it is.');

            return false;
        }
        $queue = is_string($record['queue'] ?? null) && $record['queue'] !== '' ? $record['queue'] : 'default';
        $runAs = is_string($record['runAsContext'] ?? null) && $record['runAsContext'] !== '' ? $record['runAsContext'] : null;

        $table = JsonRecordStore::tableName(self::COLLECTION);
        try {
            $this->connection->beginTransaction();
            try {
                // The claim: whoever deletes the row moves it.
                try {
                    $claimed = (int) $this->connection->executeStatement('delete from ' . $table . ' where id = ?', [$id]);
                } catch (\Throwable $e) {
                    if (!ResilientTransport::isInterruption($e)) {
                        throw $e;
                    }
                    // Another worker holds the record's lock (it is moving it) past the busy timeout:
                    // not ours, and not a problem.
                    $this->connection->rollBack();
                    $this->logger->debug('A queued event of the previous queue is being moved by another worker: ' . self::printable($id));

                    return null;
                }
                if ($claimed === 0) {
                    $this->connection->commit();

                    return null;
                }
                $this->bus->dispatch(new QueuedEvent($queue, $event, $data, $runAs));
                $this->connection->commit();
            } catch (\Throwable $e) {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
                throw $e;
            }
        } catch (\Throwable $e) {
            $this->logger->error('A queued event of the previous queue could not be moved: ' . $e::class . '. Event ' . self::printable($id) . ' (' . self::printable($event) . ') was left where it is.');

            return false;
        }

        return true;
    }

    /**
     * The data of an event as the previous queue stored it (`serialize($eventData)`), null when it
     * does not unserialise to an array.
     *
     * @return array<mixed>|null
     */
    private static function unserialiseData(mixed $text): ?array
    {
        if (!is_string($text) || $text === '') {
            return null;
        }
        $failed = false;
        set_error_handler(static function () use (&$failed): bool {
            $failed = true;

            return true;
        });
        try {
            $data = unserialize($text);
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }

        return !$failed && is_array($data) && !self::holdsIncompleteClass($data) ? $data : null;
    }

    /**
     * Whether the value holds an object of a class that does not exist (an entity of a removed plugin):
     * the event could not run with it, so the record is unreadable.
     */
    private static function holdsIncompleteClass(mixed $value, int $depth = 0): bool
    {
        if ($value instanceof \__PHP_Incomplete_Class) {
            return true;
        }
        if ($depth > 64) {
            return true; // deeper than any event's data: not trusted
        }
        if (is_array($value)) {
            foreach ($value as $each) {
                if (self::holdsIncompleteClass($each, $depth + 1)) {
                    return true;
                }
            }
        } elseif (is_object($value)) {
            foreach ((array) $value as $each) {
                if (self::holdsIncompleteClass($each, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function printable(string $value): string
    {
        return substr(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value) ?? '', 0, 200);
    }
}
