<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * The same checks on a site whose `event_queue` is not the asynchronous queue: federation is off, and
 * so is the queue's asynchronous form.
 */
final class QueueSettingSynchronousSiteTest extends QueueSettingSiteTest
{
    protected function siteEnvironment(): array
    {
        return ['IDNO_EVENT_QUEUE' => 'SynchronousQueue'];
    }

    protected function expectsFederation(): bool
    {
        return false;
    }
}
