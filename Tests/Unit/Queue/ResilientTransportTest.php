<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Idno\Queue\QueuedEvent;
use Idno\Queue\ResilientTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Tests\Storage\PrivateDatabase;

/**
 * Reading the queue rides out a busy or lost database, and nothing else.
 */
final class ResilientTransportTest extends TestCase
{
    private static function driverFailure(string $message): AbstractException
    {
        return new class($message) extends AbstractException {
        };
    }

    /**
     * @return array<string, array{\Throwable, bool}> the failure the inner transport throws, and whether the connection was lost
     */
    public static function ridableFailures(): array
    {
        $driver = self::driverFailure('database is locked');

        return [
            'a lock wait timeout (SQLite: database is locked)' => [new TransportException('locked', 0, new LockWaitTimeoutException($driver, null)), false],
            'a deadlock' => [new TransportException('deadlock', 0, new DeadlockException($driver, null)), false],
            'a lost connection' => [new TransportException('gone', 0, new ConnectionLost(self::driverFailure('server has gone away'), null)), true],
            'one not wrapped' => [new LockWaitTimeoutException($driver, null), false],
        ];
    }

    /**
     * @dataProvider ridableFailures
     */
    public function testAReadThatFindsTheDatabaseBusyOrGoneReturnsNothing(\Throwable $failure, bool $lost): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        $connection->fetchOne('select 1');
        $log = new CollectingLogger();
        $transport = new ResilientTransport(new ThrowingTransport($failure), $connection, $log);

        $this->assertSame([], iterator_to_array($transport->get(), false));

        $this->assertSame($lost, !$connection->isConnected(), 'a lost connection is closed so the next query opens a new one; a busy one is kept');
        $this->assertCount(1, $log->lines, 'one line');
        $this->assertSame('warning', $log->lines[0][0]);
    }

    public function testAnyOtherFailurePropagates(): void
    {
        $connection = PrivateDatabase::sqliteMemory();
        foreach ([
            new TransportException('syntax', 0, new SyntaxErrorException(self::driverFailure('bad sql'), null)),
            new \RuntimeException('something else'),
            new \Error('an error'),
        ] as $failure) {
            $log = new CollectingLogger();
            $transport = new ResilientTransport(new ThrowingTransport($failure), $connection, $log);
            try {
                $transport->get();
                $this->fail('The failure was swallowed.');
            } catch (\Throwable $e) {
                $this->assertSame($failure, $e);
            }
            $this->assertSame([], $log->lines);
        }
    }

    public function testItSaysWhetherItsLastReadWasRiddenOut(): void
    {
        $failure = new TransportException('locked', 0, new LockWaitTimeoutException(self::driverFailure('database is locked'), null));
        $inner = new ScriptedTransport([$failure, [], [new Envelope(new QueuedEvent('idno', 'e', [], null))]]);
        $transport = new ResilientTransport($inner, PrivateDatabase::sqliteMemory(), new CollectingLogger());

        $this->assertFalse($transport->lastGetWasInterrupted(), 'before any read');
        $transport->get();
        $this->assertTrue($transport->lastGetWasInterrupted(), 'a busy read that returned nothing');
        $transport->get();
        $this->assertFalse($transport->lastGetWasInterrupted(), 'a genuinely empty read');
        $transport->get();
        $this->assertFalse($transport->lastGetWasInterrupted(), 'a read that found a message');
    }

    public function testAMessageThatIsThereIsReturned(): void
    {
        $envelope = new Envelope(new QueuedEvent('idno', 'e', [], null));
        $transport = new ResilientTransport(new ThrowingTransport(null, [$envelope]), PrivateDatabase::sqliteMemory(), new CollectingLogger());

        $this->assertSame([$envelope], iterator_to_array($transport->get(), false));
    }

    public function testAcknowledgingRejectingAndSendingAreNotSwallowed(): void
    {
        $failure = new TransportException('locked', 0, new LockWaitTimeoutException(self::driverFailure('database is locked'), null));
        $inner = new ThrowingTransport($failure);
        $transport = new ResilientTransport($inner, PrivateDatabase::sqliteMemory(), new CollectingLogger());
        $envelope = new Envelope(new QueuedEvent('idno', 'e', [], null));

        foreach (['ack', 'reject', 'send'] as $method) {
            try {
                $transport->$method($envelope);
                $this->fail($method . ' swallowed the failure.');
            } catch (TransportException $e) {
                $this->assertSame($failure, $e, $method);
            }
        }
    }

    public function testTheLoggedLineHasNoControlCharactersAndIsBounded(): void
    {
        $failure = new TransportException('x', 0, new LockWaitTimeoutException(self::driverFailure("locked\r\n" . str_repeat('a', 1000)), null));
        $log = new CollectingLogger();

        (new ResilientTransport(new ThrowingTransport($failure), PrivateDatabase::sqliteMemory(), $log))->get();

        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1f]/', $log->lines[0][1]);
        $this->assertLessThan(400, strlen($log->lines[0][1]));
    }
}

final class ThrowingTransport implements TransportInterface
{
    /**
     * @param list<Envelope> $envelopes
     */
    public function __construct(private readonly ?\Throwable $failure, private readonly array $envelopes = [])
    {
    }

    public function get(): iterable
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->envelopes;
    }

    public function ack(Envelope $envelope): void
    {
        throw $this->failure;
    }

    public function reject(Envelope $envelope): void
    {
        throw $this->failure;
    }

    public function send(Envelope $envelope): Envelope
    {
        throw $this->failure;
    }
}
