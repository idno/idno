<?php

declare(strict_types=1);

namespace Tests\Queue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Idno\Queue\QueuedEvent;
use Idno\Queue\WorkerHygiene;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Worker;
use Tests\Storage\PrivateDatabase;
use Tests\Unit\Queue\RecordingEntityCache;
use Tests\Unit\Queue\RecordingUserContext;

/**
 * What a long-running worker needs: it survives a lost database connection, and nobody stays logged
 * on after a message. On a SQLite file and on a private database of the rig's MySQL or MariaDB server.
 */
final class WorkerHygieneTest extends TestCase
{
    use PrivateQueueDatabase;

    protected function tearDown(): void
    {
        $this->releaseDatabase();
    }

    /**
     * Loses the connection the way the engine can: MySQL and MariaDB kill the session from a second
     * connection (the next statement on it fails), SQLite closes it.
     */
    private function loseConnection(Connection $connection): void
    {
        if ($this->directory !== null) {
            $connection->close();

            return;
        }
        $id = (int) $connection->fetchOne('select connection_id()');
        $other = DriverManager::getConnection($connection->getParams());
        try {
            $other->executeStatement('kill ' . $id);
        } finally {
            $other->close();
        }
        // The server ends the session asynchronously.
        usleep(100000);
    }

    /**
     * @dataProvider engines
     */
    public function testTheWorkerSurvivesALostConnection(string $engine): void
    {
        $connection = $this->database($engine, 'hygiene');
        $transport = MessengerRig::transport($connection);
        $handled = [];
        $handler = function (QueuedEvent $message) use ($connection, &$handled): void {
            $handled[] = $message->event;
            if ($message->event === 'first') {
                // A handler that runs past the server's idle timeout: the connection is gone when it returns.
                $this->loseConnection($connection);
            }
        };
        $hygiene = new WorkerHygiene($connection, new RecordingUserContext(), new RecordingEntityCache(), new \Psr\Log\NullLogger());
        $bus = MessengerRig::bus([QueuedEvent::class => [$handler]], [$hygiene]);
        $transport->send(new Envelope(new QueuedEvent('idno', 'first', [], null)));
        $transport->send(new Envelope(new QueuedEvent('idno', 'second', [], null)));

        $this->runUntilIdle(new Worker(['idno' => $transport], $bus, $this->dispatcher($hygiene)));

        $this->assertSame(['first', 'second'], $handled, 'the message after the lost connection is handled');
        $this->assertSame(0, $transport->getMessageCount(), 'and both are acknowledged: neither will be delivered again');
        $this->assertSame(0, (int) $connection->fetchOne('select count(*) from ' . MessengerRig::TABLE));
    }

    /**
     * @dataProvider engines
     */
    public function testAConnectionLostWhileTheWorkerIsIdleIsReplacedBeforeTheNextRead(string $engine): void
    {
        $connection = $this->database($engine, 'hygiene');
        $hygiene = new WorkerHygiene($connection, new RecordingUserContext(), new RecordingEntityCache(), new \Psr\Log\NullLogger());
        $connection->fetchOne('select 1');
        $this->loseConnection($connection);

        $hygiene->onWorkerRunning(new WorkerRunningEvent(new Worker([], MessengerRig::bus([])), true));

        $this->assertSame(1, (int) $connection->fetchOne('select 1'), 'the first statement after the check does not fail');
        $this->assertSame(0, (int) $connection->fetchOne('select count(*) from ' . MessengerRig::TABLE));
    }

    /**
     * @dataProvider engines
     */
    public function testAReadOnAConnectionLostWhileIdleReturnsNothingAndTheNextReadWorks(string $engine): void
    {
        $connection = $this->database($engine, 'hygiene');
        $inner = MessengerRig::transport($connection);
        $inner->send(new Envelope(new QueuedEvent('idno', 'waiting', [], null)));
        $connection->fetchOne('select 1');
        $this->loseConnection($connection);
        $transport = new \Idno\Queue\ResilientTransport($inner, $connection, new \Psr\Log\NullLogger());

        $received = [];
        for ($i = 0; $i < 3 && $received === []; ++$i) {
            $received = iterator_to_array($transport->get(), false);
        }

        $this->assertCount(1, $received, 'the worker is not ended by the lost connection: a read or two later it has its message');
    }

    /**
     * @dataProvider engines
     */
    public function testNoUserIsLoggedOnAfterAMessageWhateverHappened(string $engine): void
    {
        $connection = $this->database($engine, 'hygiene');
        $users = new RecordingUserContext();
        $hygiene = new WorkerHygiene($connection, $users, new RecordingEntityCache(), new \Psr\Log\NullLogger());
        $received = static fn (): Envelope => (new Envelope(new QueuedEvent('idno', 'e', [], null)))->with(new ReceivedStamp('idno'));

        foreach ([null, new \RuntimeException('failed'), new \TypeError('an error')] as $failure) {
            $bus = MessengerRig::bus([QueuedEvent::class => [static function () use ($users, $failure): void {
                $users->loggedOn = 'https://example.com/user/1'; // a handler that leaves its user logged on
                if ($failure !== null) {
                    throw $failure;
                }
            }]], [$hygiene]);

            try {
                $bus->dispatch($received());
            } catch (\Throwable) {
                // the failure is the worker's to deal with
            }

            $this->assertNull($users->loggedOn, 'nobody is logged on after ' . ($failure === null ? 'a message that succeeded' : get_class($failure)));
        }
    }

    /**
     * @dataProvider engines
     */
    public function testAFailingCleanupDoesNotMaskTheHandlersException(string $engine): void
    {
        $connection = $this->database($engine, 'hygiene');
        $users = new class extends RecordingUserContext {
            public function ensureNobodyLoggedOn(): void
            {
                throw new \LogicException('cannot log off');
            }
        };
        $log = new \Tests\Unit\Queue\CollectingLogger();
        $hygiene = new WorkerHygiene($connection, $users, new RecordingEntityCache(), $log);
        $handlerFailure = new \RuntimeException('the handler failed');
        $bus = MessengerRig::bus([QueuedEvent::class => [static function () use ($handlerFailure): void {
            throw $handlerFailure;
        }]], [$hygiene]);

        try {
            $bus->dispatch((new Envelope(new QueuedEvent('idno', 'e', [], null)))->with(new ReceivedStamp('idno')));
            $this->fail('The handler\'s failure was lost.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Symfony\Component\Messenger\Exception\HandlerFailedException::class, $e, 'the handler\'s own failure is the one that propagates, not the cleanup\'s');
            $this->assertSame([$handlerFailure], array_values($e->getWrappedExceptions()));
        }
        $this->assertCount(1, $log->lines);
        $this->assertStringContainsString('cannot log off', $log->lines[0][1]);
        $this->assertSame(1, (int) $connection->fetchOne('select 1'), 'the connection step still ran and the connection works');
    }

    /**
     * The worker lives for hours: what one event loaded (users, entities) must not be what the next
     * one sees, so the entity cache is emptied after every received message, whatever it did.
     *
     * @dataProvider engines
     */
    public function testTheEntityCacheIsEmptiedAfterEveryReceivedMessageWhateverItDid(string $engine): void
    {
        $connection = $this->database($engine, 'hygiene');
        $cache = new RecordingEntityCache();
        $hygiene = new WorkerHygiene($connection, new RecordingUserContext(), $cache, new \Psr\Log\NullLogger());
        $received = static fn (): Envelope => (new Envelope(new QueuedEvent('idno', 'e', [], null)))->with(new ReceivedStamp('idno'));

        foreach ([null, new \RuntimeException('failed'), new \TypeError('an error')] as $n => $failure) {
            $bus = MessengerRig::bus([QueuedEvent::class => [static function () use ($failure): void {
                if ($failure !== null) {
                    throw $failure;
                }
            }]], [$hygiene]);
            try {
                $bus->dispatch($received());
            } catch (\Throwable) {
                // the worker's to deal with
            }
            $this->assertSame($n + 1, $cache->cleared, 'emptied after message ' . ($n + 1));
        }
    }

    /**
     * @dataProvider engines
     */
    public function testAFailureToEmptyTheCacheIsLoggedAndMasksNothing(string $engine): void
    {
        $connection = $this->database($engine, 'hygiene');
        $users = new RecordingUserContext();
        $log = new \Tests\Unit\Queue\CollectingLogger();
        $hygiene = new WorkerHygiene($connection, $users, new RecordingEntityCache(new \LogicException('cache broke')), $log);
        $handlerFailure = new \RuntimeException('the handler failed');
        $bus = MessengerRig::bus([QueuedEvent::class => [static function () use ($handlerFailure, $users): void {
            $users->loggedOn = 'https://example.com/user/1';
            throw $handlerFailure;
        }]], [$hygiene]);

        try {
            $bus->dispatch((new Envelope(new QueuedEvent('idno', 'e', [], null)))->with(new ReceivedStamp('idno')));
            $this->fail('The handler\'s failure was lost.');
        } catch (\Throwable $e) {
            $this->assertSame([$handlerFailure], array_values($e->getWrappedExceptions()));
        }
        $this->assertNull($users->loggedOn, 'the other cleanup steps still ran');
        $this->assertCount(1, $log->lines);
        $this->assertStringContainsString('cache broke', $log->lines[0][1]);
    }

    /**
     * @dataProvider engines
     */
    public function testAMessageDispatchedByARequestIsLeftAlone(string $engine): void
    {
        $connection = $this->database($engine, 'hygiene');
        $users = new RecordingUserContext();
        $users->loggedOn = 'https://example.com/user/1'; // the request's own user
        $cache = new RecordingEntityCache();
        $hygiene = new WorkerHygiene($connection, $users, $cache, new \Psr\Log\NullLogger());
        $bus = MessengerRig::bus([QueuedEvent::class => [static function (): void {
        }]], [$hygiene]);
        $disconnected = !$connection->isConnected();

        $bus->dispatch(new Envelope(new QueuedEvent('idno', 'e', [], null)));

        $this->assertSame('https://example.com/user/1', $users->loggedOn, 'the request\'s user stays logged on');
        $this->assertSame(0, $cache->cleared, 'and the request\'s cache too');
        $this->assertSame($disconnected, !$connection->isConnected(), 'the connection was not even touched');
    }

    private function dispatcher(WorkerHygiene $hygiene): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($hygiene);
        $idle = 0;
        $dispatcher->addListener(WorkerRunningEvent::class, function (WorkerRunningEvent $event) use (&$idle): void {
            $idle = $event->isWorkerIdle() ? $idle + 1 : 0;
            if ($idle >= 2) {
                $event->getWorker()->stop();
            }
        }, -10);

        return $dispatcher;
    }

    private function runUntilIdle(Worker $worker): void
    {
        $worker->run(['sleep' => 1000]);
    }
}
