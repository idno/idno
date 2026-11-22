<?php

declare(strict_types=1);

namespace Idno\Queue;

/**
 * An event queued to be triggered later, in a worker: the queue it was sent to, the event's name,
 * its data and the uuid of the user it runs as (null for nobody).
 *
 * The message is serialised with PHP's serializer (Messenger's default), because the data may hold
 * entity objects, as the data of an event always could.
 */
final class QueuedEvent
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(
        public readonly string $queue,
        public readonly string $event,
        public readonly array $data,
        public readonly ?string $runAs,
    )
    {
    }
}
