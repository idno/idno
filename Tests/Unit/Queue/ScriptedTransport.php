<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * A transport whose reads are scripted: each get() takes the next step, a Throwable to throw or the
 * envelopes to return; past the end it is empty.
 */
final class ScriptedTransport implements TransportInterface
{
    /**
     * @param list<\Throwable|list<Envelope>> $script
     */
    public function __construct(private array $script)
    {
    }

    public function get(): iterable
    {
        $step = array_shift($this->script) ?? [];
        if ($step instanceof \Throwable) {
            throw $step;
        }

        return $step;
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        return $envelope;
    }
}
