<?php

declare(strict_types=1);

namespace Idno\Queue;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Stops a worker (`service-event-queue --once`) when the queue is genuinely empty. A worker that read
 * nothing is idle, but ResilientTransport also returns nothing when the database was busy or the
 * connection lost: that is not "nothing waiting", so the worker goes on reading (the command makes it
 * sleep a moment between reads). If every read for $giveUpAfterSeconds in a row was interrupted, it
 * stops and gaveUp() says so: messages may be waiting.
 *
 * Not a container service (it is built by the command, with the transport the worker reads).
 */
final class StopWhenQueueIsEmpty implements EventSubscriberInterface
{
    private ?float $interruptedSince = null;

    private bool $gaveUp = false;

    private readonly \Closure $clock;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly float $giveUpAfterSeconds = 30.0,
        ?\Closure $clock = null,
    )
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerRunningEvent::class => 'onWorkerRunning'];
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (!$event->isWorkerIdle()) {
            $this->interruptedSince = null;

            return;
        }
        if (!($this->transport instanceof ResilientTransport && $this->transport->lastGetWasInterrupted())) {
            $event->getWorker()->stop();

            return;
        }
        $now = ($this->clock)();
        $this->interruptedSince ??= $now;
        if ($now - $this->interruptedSince >= $this->giveUpAfterSeconds) {
            $this->gaveUp = true;
            $event->getWorker()->stop();
        }
    }

    /** True when the worker stopped because the database stayed busy, not because the queue was empty. */
    public function gaveUp(): bool
    {
        return $this->gaveUp;
    }
}
