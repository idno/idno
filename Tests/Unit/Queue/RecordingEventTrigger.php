<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Idno\Queue\EventTrigger;

/**
 * An EventTrigger that records what it is asked to trigger.
 */
final class RecordingEventTrigger implements EventTrigger
{
    /** @var list<array{string, array<mixed>}> */
    public array $triggered = [];

    public function __construct(private readonly mixed $result = true, private readonly ?\ArrayObject $log = null, private readonly ?\Throwable $failure = null)
    {
    }

    public function trigger(string $event, array $data, ?\Closure $keepResponse = null): mixed
    {
        $this->triggered[] = [$event, $data];
        $this->log?->append('trigger ' . $event);
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->result;
    }
}
