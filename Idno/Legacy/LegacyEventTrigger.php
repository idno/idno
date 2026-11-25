<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\EventDispatcher;
use Idno\Queue\EventTrigger;

/**
 * The queue's EventTrigger over the legacy event dispatcher: it triggers the event as any code
 * does (Idno\Core\EventDispatcher::triggerEvent()) and returns what the listeners returned.
 */
final class LegacyEventTrigger implements EventTrigger
{
    public function __construct(private readonly EventDispatcher $events)
    {
    }

    public function trigger(string $event, array $data, ?\Closure $keepResponse = null): mixed
    {
        return $this->events->triggerEvent($event, $data, true, $keepResponse);
    }
}
