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
     * @param array<mixed>        $data
     * @param \Closure(mixed): bool|null $keepResponse a rule for the response the event keeps: while the response so far is one it accepts, a
     *                                                 later listener's answer changes nothing (the first acceptable answer wins)
     */
    public function trigger(string $event, array $data, ?\Closure $keepResponse = null): mixed;
}
