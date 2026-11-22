<?php

declare(strict_types=1);

namespace Idno\Queue;

/**
 * Triggers a named event with its data and returns what the listeners returned. The queue's
 * handler depends on this, not on the application's event dispatcher (Idno\Legacy\LegacyEventTrigger
 * is the adapter over it).
 */
interface EventTrigger
{
    /**
     * @param array<mixed> $data
     */
    public function trigger(string $event, array $data): mixed;
}
