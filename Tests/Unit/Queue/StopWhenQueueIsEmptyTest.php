<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Idno\Queue\QueuedEvent;
use Idno\Queue\ResilientTransport;
use Idno\Queue\StopWhenQueueIsEmpty;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Worker;
use Tests\Storage\PrivateDatabase;

/**
 * `service-event-queue --once` ends on a genuinely empty read, never on a read that was only ridden out
 * (ResilientTransport returns nothing when the database is busy), and gives up, saying so, after the
 * database has been busy for the whole limit.
 */
final class StopWhenQueueIsEmptyTest extends TestCase
{
    private function busy(): TransportException
    {
        $driver = new class('database is locked') extends AbstractException {
        };

        return new TransportException('locked', 0, new LockWaitTimeoutException($driver, null));
    }

    private function message(): Envelope
    {
        return new Envelope(new QueuedEvent('idno', 'e', [], null));
    }

    /**
     * @param list<\Throwable|list<Envelope>> $script
     *
     * @return array{handled: int, gaveUp: bool, reads: int}
     */
    private function consume(array $script, ?\Closure $clock = null, float $limit = 30.0): array
    {
        $inner = new ScriptedTransport($script);
        $reads = 0;
        $counting = new class($inner, $reads) implements \Symfony\Component\Messenger\Transport\TransportInterface {
            public function __construct(private readonly ScriptedTransport $inner, private int &$reads)
            {
            }

            public function get(): iterable
            {
                ++$this->reads;

                return $this->inner->get();
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
        };
        $transport = new ResilientTransport($counting, PrivateDatabase::sqliteMemory(), new CollectingLogger());
        $handled = 0;
        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([QueuedEvent::class => [static function () use (&$handled): void {
            ++$handled;
        }]]))]);
        $stop = new StopWhenQueueIsEmpty($transport, $limit, $clock);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($stop);

        (new Worker(['idno' => $transport], $bus, $dispatcher))->run(['sleep' => 1000]);

        return ['handled' => $handled, 'gaveUp' => $stop->gaveUp(), 'reads' => $reads];
    }

    public function testAGenuinelyEmptyQueueEndsTheRun(): void
    {
        $this->assertSame(['handled' => 0, 'gaveUp' => false, 'reads' => 1], $this->consume([]));
    }

    public function testBusyReadsAreNotTakenForAnEmptyQueue(): void
    {
        $busy = $this->busy();
        $result = $this->consume([$busy, $busy, $busy, [$this->message(), $this->message()]]);

        $this->assertSame(2, $result['handled'], 'the messages that were waiting behind the busy reads are handled');
        $this->assertFalse($result['gaveUp']);
    }

    public function testItEndsOnTheEmptyReadThatFollowsBusyOnes(): void
    {
        $busy = $this->busy();
        $result = $this->consume([$busy, $busy, []]);

        $this->assertSame(['handled' => 0, 'gaveUp' => false, 'reads' => 3], $result);
    }

    public function testADatabaseBusyForTheWholeLimitMakesItGiveUp(): void
    {
        $busy = $this->busy();
        $now = 1000.0;
        $clock = static function () use (&$now): float {
            return $now += 10.0;
        };

        $result = $this->consume(array_fill(0, 20, $busy), $clock, 30.0);

        $this->assertTrue($result['gaveUp']);
        $this->assertSame(0, $result['handled']);
        $this->assertLessThan(10, $result['reads'], 'it stopped, it did not poll for ever');
    }

    public function testABusyStretchThatEndsBeforeTheLimitStartsTheCountAgain(): void
    {
        $busy = $this->busy();
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now += 10.0;
        };
        // Two busy reads (20 s), a message, two more busy reads (20 s): never 30 s in a row.
        $result = $this->consume([$busy, $busy, [$this->message()], $busy, $busy, []], $clock, 30.0);

        $this->assertFalse($result['gaveUp']);
        $this->assertSame(1, $result['handled']);
    }
}
