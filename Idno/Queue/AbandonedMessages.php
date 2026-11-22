<?php

declare(strict_types=1);

namespace Idno\Queue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Messages a worker took and never finished: a worker killed while it handled an event leaves its
 * row in `idno_messages` with `delivered_at` set. Nothing runs such a message again by itself (the
 * transports' `redeliver_timeout` is REDELIVER_TIMEOUT_SECONDS, ten years), because the event may
 * already have posted to another server. This service lets an operator see them and, knowing what
 * they do, put them back in the queue.
 *
 * "Abandoned" is a message of the queue taken more than $olderThanSeconds ago. It reads and writes
 * the transport's own table, binding every value as the transport does (UTC datetimes of whole
 * seconds).
 */
final class AbandonedMessages
{
    /**
     * Ten years in seconds, as `redeliver_timeout` of the queue's transports. The Doctrine transport
     * gives any message whose `delivered_at` is older than that to the next worker, and the
     * default is an hour: a worker killed in the middle of a message (an out-of-memory kill, a
     * deploy) would make its event run again, and what an event does may not be repeatable (it may
     * already have been sent to another server). Never in practice: see requeue().
     */
    public const REDELIVER_TIMEOUT_SECONDS = 315360000;

    private const TABLE = 'idno_messages';

    private const MAX_IDS = 100;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function count(string $queue = 'default', int $olderThanSeconds = 3600): int
    {
        return (int) $this->connection->fetchOne(
            'select count(*) from ' . self::TABLE . ' where queue_name = ? and delivered_at is not null and delivered_at < ?',
            [$queue, $this->cutoff($olderThanSeconds)],
            [Types::STRING, Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * The ids of the oldest abandoned messages, at most 100.
     *
     * @return list<string>
     */
    public function ids(string $queue = 'default', int $olderThanSeconds = 3600): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn(
            'select id from ' . self::TABLE . ' where queue_name = ? and delivered_at is not null and delivered_at < ? order by id limit ' . self::MAX_IDS,
            [$queue, $this->cutoff($olderThanSeconds)],
            [Types::STRING, Types::DATETIME_IMMUTABLE],
        ));
    }

    /**
     * Makes every abandoned message of the queue available again, in one statement (`delivered_at`
     * becomes NULL), so that the next worker runs them. Only an operator who knows what the events
     * did should call it. Returns how many rows it changed.
     */
    public function requeue(string $queue = 'default', int $olderThanSeconds = 3600): int
    {
        return (int) $this->connection->executeStatement(
            'update ' . self::TABLE . ' set delivered_at = null where queue_name = ? and delivered_at is not null and delivered_at < ?',
            [$queue, $this->cutoff($olderThanSeconds)],
            [Types::STRING, Types::DATETIME_IMMUTABLE],
        );
    }

    private function cutoff(int $olderThanSeconds): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . max(0, $olderThanSeconds) . ' seconds');
    }
}
