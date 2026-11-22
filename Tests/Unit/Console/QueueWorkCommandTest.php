<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Idno\Console\QueueWorkCommand;
use Idno\Legacy\MessengerEventQueue;
use Idno\Queue\AbandonedMessages;
use Idno\Queue\LegacyQueueDrain;
use Idno\Queue\QueuedEvent;
use Idno\Queue\ResilientTransport;
use Idno\Storage\RecordStore;
use Idno\Storage\AttributeTypeMap;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Tests\Storage\PrivateDatabase;
use Tests\Unit\Queue\CollectingLogger;
use Tests\Unit\Queue\ScriptedTransport;

/**
 * `service-event-queue` in-process, on an in-memory SQLite database and doubles: a stop request
 * (SIGTERM, SIGINT) is never lost, `--once` does not end while the database is busy, and the memory
 * limit the worker stops itself at.
 */
final class QueueWorkCommandTest extends TestCase
{
    private Connection $connection;

    private int $transportFetched = 0;

    private ?QueueWorkCommand $command = null;

    private int $handled = 0;

    private ?CollectingLogger $log = null;

    private bool $deleteFails = false;

    /** @var list<string> */
    private array $drainSends = [];

    protected function setUp(): void
    {
        $this->connection = PrivateDatabase::sqliteMemory();
    }

    private function legacyRecord(int $n, bool $complete = false): void
    {
        $id = sprintf('00000000-0000-4000-8000-%012d', $n);
        $this->assertNotFalse(PrivateDatabase::recordStore($this->connection, null, new AttributeTypeMap())->save('entities', [
            '_id' => $id, 'uuid' => 'http://example.test/view/' . $id, 'entity_subtype' => LegacyQueueDrain::RECORD_CLASS, 'owner' => '',
            'created' => $n, 'queue' => 'default', 'event' => 'post/note/n' . $n, 'eventData' => serialize(['n' => $n]),
            'runAsContext' => false, 'complete' => $complete, 'queuedTs' => $n, 'access' => 'PUBLIC',
        ]));
    }

    private function pending(): int
    {
        return (int) $this->connection->fetchOne('select count(*) from idno_entities');
    }

    /**
     * @param \Closure(): TransportInterface $transport
     */
    private function command(\Closure $transport, bool $signalOnFirstSend = false, float $busyGiveUp = 30.0): QueueWorkCommand
    {
        $drainBus = new class($this, $signalOnFirstSend) implements MessageBusInterface {
            public function __construct(private readonly QueueWorkCommandTest $test, private readonly bool $signal)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->test->sent($message->event, $this->signal);

                return new Envelope($message);
            }
        };
        $store = PrivateDatabase::recordStore($this->connection, null, new AttributeTypeMap());
        if ($this->deleteFails) {
            $store = new class($store) implements RecordStore {
                public function __construct(private readonly RecordStore $inner)
                {
                }

                public function save(string $collection, array $record): string|false
                {
                    return $this->inner->save($collection, $record);
                }

                public function findById(string $collection, string $id): ?array
                {
                    return $this->inner->findById($collection, $id);
                }

                public function findByUuid(string $collection, string $uuid): ?array
                {
                    return $this->inner->findByUuid($collection, $uuid);
                }

                public function findLatest(string $collection): ?array
                {
                    return $this->inner->findLatest($collection);
                }

                public function find(string $collection, \Idno\Storage\Criteria $criteria, int $limit, int $offset): array
                {
                    return $this->inner->find($collection, $criteria, $limit, $offset);
                }

                public function count(string $collection, \Idno\Storage\Criteria $criteria): int
                {
                    return $this->inner->count($collection, $criteria);
                }

                public function delete(string $collection, string $id): bool
                {
                    throw new \RuntimeException('the database refused: secret-detail');
                }

                public function deleteAll(string $collection): bool
                {
                    return $this->inner->deleteAll($collection);
                }
            };
        }
        $drain = new LegacyQueueDrain($this->connection, $store, $drainBus, new NullLogger());
        $this->log = new CollectingLogger();
        $kernel = $this->createMock(KernelInterface::class);

        return $this->command = new QueueWorkCommand(
            static fn (): MessengerEventQueue => new MessengerEventQueue(static fn (): MessageBusInterface => new MessageBus([]), static fn (): string => 'AsynchronousQueue', static fn (): ?string => null, static fn (): mixed => null, new NullLogger()),
            static fn (): LegacyQueueDrain => $drain,
            fn (): AbandonedMessages => new AbandonedMessages($this->connection),
            function () use ($transport): TransportInterface {
                ++$this->transportFetched;

                return $transport();
            },
            fn (): MessageBusInterface => new MessageBus([new HandleMessageMiddleware(new HandlersLocator([QueuedEvent::class => [function (): void {
                ++$this->handled;
            }]]))]),
            fn (): CollectingLogger => $this->log,
            new EventDispatcher(),
            $kernel,
            $busyGiveUp,
        );
    }

    /** @internal for the bus above */
    public function sent(string $event, bool $signal): void
    {
        $this->drainSends[] = $event;
        if ($signal && count($this->drainSends) === 1) {
            $this->command?->handleSignal(\SIGTERM);
        }
    }

    public function testAStopRequestedBeforeTheWorkerExistsIsNotLost(): void
    {
        $this->legacyRecord(1);
        $command = $this->command(static fn (): TransportInterface => new ScriptedTransport([]));
        $command->handleSignal(\SIGTERM);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--once' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $this->transportFetched, 'it did not start consuming');
        $this->assertSame([], $this->drainSends, 'it did not even drain');
        $this->assertSame(1, $this->pending());
        $this->assertStringContainsString('Stopped', $tester->getDisplay());
    }

    public function testAStopArrivingWhileItDrainsEndsTheDrainBetweenRecordsAndDoesNotConsume(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->legacyRecord($n);
        }
        $command = $this->command(static fn (): TransportInterface => new ScriptedTransport([]), signalOnFirstSend: true);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--once' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(['post/note/n1'], $this->drainSends, 'the record in hand was moved whole, the others not started');
        $this->assertSame(2, $this->pending(), 'the rest are still pending');
        $this->assertSame(0, $this->transportFetched, 'and nothing was consumed');
        $this->assertStringContainsString('Stopped', $tester->getDisplay());
    }

    public function testAStopRequestedWhileTheWorkerIsBeingBuiltEndsTheRunBeforeAnyMessageIsRead(): void
    {
        $reads = 0;
        $command = $this->command(function () use (&$reads): TransportInterface {
            // The signal arrives after the last check and before the worker exists.
            $this->command?->handleSignal(\SIGTERM);

            return new class($reads) implements TransportInterface {
                public function __construct(private int &$reads)
                {
                }

                public function get(): iterable
                {
                    ++$this->reads;

                    return [new Envelope(new QueuedEvent('idno', 'e', [], null))];
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
        });

        $exit = (new CommandTester($command))->execute(['--once' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $this->transportFetched, 'the worker was being built');
        $this->assertSame(0, $reads, 'but it read nothing');
        $this->assertSame(0, $this->handled);
    }

    public function testHousekeepingThatFailsNeverKeepsTheWorkerFromConsuming(): void
    {
        $this->deleteFails = true;
        $this->legacyRecord(1, complete: true);
        $command = $this->command(static fn (): TransportInterface => new ScriptedTransport([[new Envelope(new QueuedEvent('idno', 'waiting', [], null))]]));

        $exit = (new CommandTester($command))->execute(['--once' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(1, $this->handled, 'the waiting message was handled');
        $warnings = array_values(array_filter($this->log->lines, static fn (array $line): bool => $line[0] === 'warning'));
        $this->assertCount(1, $warnings, 'one warning line (the worker logs its own lines at other levels)');
        $this->assertStringContainsString(\RuntimeException::class, $warnings[0][1]);
        $this->assertStringNotContainsString('secret-detail', $warnings[0][1], 'the exception\'s class only');
    }

    public function testWithoutAStopItDrainsAndConsumesAndEndsOnAnEmptyQueue(): void
    {
        $this->legacyRecord(1);
        $tester = new CommandTester($this->command(static fn (): TransportInterface => new ScriptedTransport([])));

        $this->assertSame(0, $tester->execute(['--once' => true]));
        $this->assertSame(1, $this->transportFetched);
        $this->assertSame(0, $this->pending());
    }

    public function testOnceGivesUpWhenTheDatabaseStaysBusySayingSoOnStderr(): void
    {
        $driver = new class('database is locked') extends AbstractException {
        };
        $busy = new TransportException('locked', 0, new LockWaitTimeoutException($driver, null));
        $transport = fn (): TransportInterface => new ResilientTransport(new ScriptedTransport(array_fill(0, 50, $busy)), $this->connection, new CollectingLogger());
        $tester = new CommandTester($this->command($transport, busyGiveUp: 0.0));

        $exit = $tester->execute(['--once' => true], ['capture_stderr_separately' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('busy', $tester->getErrorOutput());
        $this->assertStringContainsString('may be waiting', $tester->getErrorOutput());
    }

    /**
     * @return array<string, array{string|false, int}>
     */
    public static function memoryLimits(): array
    {
        $ceiling = QueueWorkCommand::MEMORY_LIMIT_BYTES;

        return [
            'unlimited' => ['-1', $ceiling],
            'zero' => ['0', $ceiling],
            'unset' => [false, $ceiling],
            'empty' => ['', $ceiling],
            '128M is 80%' => ['128M', (int) (128 * 1048576 * 0.8)],
            'in bytes' => ['100000000', 80000000],
            '1G is above the ceiling at 80%' => ['1G', $ceiling],
            '320M: 80% is the ceiling' => ['320M', $ceiling],
            '256k' => ['256k', (int) (256 * 1024 * 0.8)],
            'unreadable' => ['lots', $ceiling],
        ];
    }

    /**
     * @dataProvider memoryLimits
     */
    public function testTheMemoryLimitFollowsPhpsAndNeverExceedsTheCeiling(string|false $ini, int $expected): void
    {
        $this->assertSame($expected, QueueWorkCommand::memoryLimitBytes($ini));
    }
}
