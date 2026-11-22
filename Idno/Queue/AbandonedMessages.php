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
 * "Abandoned" is a message of the queue taken more than $olderThanSeconds ago; $queue is the
 * transport's queue name (QUEUE), and an age below MINIMUM_AGE_SECONDS is an
 * \InvalidArgumentException from every method. It reads and writes
 * the transport's own table, binding every value as the transport does (UTC datetimes of whole
 * seconds).
 */
final class AbandonedMessages
{
    /**
     * The transport's own name for the queue the site's events are sent to: the `queue_name` column
     * (and option) of the `idno` transport. The other one is `failed`, where a message whose handler
     * threw is kept. This is NOT the legacy queue name an event is enqueued with (`default`, the
     * first argument of EventQueue::enqueue(), kept in QueuedEvent::$queue): every legacy queue
     * shares the one `idno` transport.
     */
    public const QUEUE = 'idno';

    /**
     * The youngest age a message may be called abandoned: a live worker holds the message it is
     * handling, and an event may take minutes (a video transcode), so putting back anything younger
     * would run an event twice.
     */
    public const MINIMUM_AGE_SECONDS = 60;

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

    public function count(string $queue = self::QUEUE, int $olderThanSeconds = 3600): int
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
    public function ids(string $queue = self::QUEUE, int $olderThanSeconds = 3600): array
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
    public function requeue(string $queue = self::QUEUE, int $olderThanSeconds = 3600): int
    {
        return (int) $this->connection->executeStatement(
            'update ' . self::TABLE . ' set delivered_at = null where queue_name = ? and delivered_at is not null and delivered_at < ?',
            [$queue, $this->cutoff($olderThanSeconds)],
            [Types::STRING, Types::DATETIME_IMMUTABLE],
        );
    }

    private function cutoff(int $olderThanSeconds): \DateTimeImmutable
    {
        if ($olderThanSeconds < self::MINIMUM_AGE_SECONDS) {
            throw new \InvalidArgumentException('A message is abandoned only when it was taken at least ' . self::MINIMUM_AGE_SECONDS . ' seconds ago, not ' . $olderThanSeconds . ': a live worker holds its message that long.');
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . $olderThanSeconds . ' seconds');
    }
}
