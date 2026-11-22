<?php

declare(strict_types=1);

namespace Tests\Queue;

use Doctrine\DBAL\Types\Types;
use Idno\Queue\AbandonedMessages;
use Idno\Queue\QueuedEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;

/**
 * Messages a worker took and never finished: the transport never gives them to another worker by
 * itself, and AbandonedMessages lets an operator count, list and requeue them. On a SQLite file and on
 * a private MySQL or MariaDB database.
 */
final class AbandonedMessagesTest extends TestCase
{
    use PrivateQueueDatabase;

    protected function tearDown(): void
    {
        $this->releaseDatabase();
    }

    /** Marks a message as taken $seconds ago, writing the datetime as the transport does. */
    private function takenAgo(int $id, int $seconds): void
    {
        $this->connection->executeStatement(
            'update idno_messages set delivered_at = ? where id = ?',
            [(new \DateTimeImmutable('UTC'))->modify('-' . $seconds . ' seconds'), $id],
            [Types::DATETIME_IMMUTABLE, Types::INTEGER],
        );
    }

    private function send(string $queue = 'idno'): int
    {
        $stamp = MessengerRig::transport($this->connection, $queue)->send(new Envelope(new QueuedEvent($queue, 'e', [], null)))
            ->last(\Symfony\Component\Messenger\Stamp\TransportMessageIdStamp::class);

        return (int) $stamp->getId();
    }

    /**
     * @dataProvider engines
     */
    public function testAMessageTakenTwoHoursAgoIsNotReceivedAgain(string $engine): void
    {
        $this->database($engine, 'abandoned');
        $transport = MessengerRig::transport($this->connection);
        $id = $this->send();
        $this->assertCount(1, iterator_to_array($transport->get(), false), 'a worker takes it');
        $this->takenAgo($id, 7200);

        $this->assertSame([], iterator_to_array($transport->get(), false), 'two hours later nothing gives it to another worker');
        $this->assertSame(1, (int) $this->connection->fetchOne('select count(*) from idno_messages'), 'and it is kept');
    }

    /**
     * @dataProvider engines
     */
    public function testItCountsAndListsOnlyWhatIsOldEnoughInThatQueue(string $engine): void
    {
        $this->database($engine, 'abandoned');
        $old = $this->send();
        $recent = $this->send();
        $waiting = $this->send();
        $other = $this->send('failed');
        $this->takenAgo($old, 7200);
        $this->takenAgo($recent, 300);
        $this->takenAgo($other, 7200);
        $abandoned = new AbandonedMessages($this->connection);

        $this->assertSame(1, $abandoned->count('idno'));
        $this->assertSame([(string) $old], $abandoned->ids('idno'));
        $this->assertSame(1, $abandoned->count(), 'the default queue is the one the site\'s events go to, `idno`');
        $this->assertSame([(string) $old], $abandoned->ids());
        $this->assertSame(2, $abandoned->count('idno', 60), 'older than a minute');
        $this->assertSame([(string) $old, (string) $recent], $abandoned->ids('idno', 60));
        $this->assertSame(0, $abandoned->count('nosuchqueue'));
        $this->assertSame(1, $abandoned->count('failed'));
        $this->assertNotContains((string) $waiting, $abandoned->ids('idno', 60), 'a message nobody took is not abandoned');
    }

    /**
     * @dataProvider engines
     */
    public function testItListsAtMostAHundred(string $engine): void
    {
        $this->database($engine, 'abandoned');
        $this->connection->executeStatement("insert into idno_messages (body, headers, queue_name, created_at, available_at, delivered_at) values ('b', '[]', 'idno', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        for ($i = 0; $i < 7; ++$i) {
            $this->connection->executeStatement('insert into idno_messages (body, headers, queue_name, created_at, available_at, delivered_at) select body, headers, queue_name, created_at, available_at, delivered_at from idno_messages');
        }
        $abandoned = new AbandonedMessages($this->connection);

        $this->assertSame(128, $abandoned->count('idno'));
        $this->assertCount(100, $abandoned->ids('idno'));
    }

    /**
     * @dataProvider engines
     */
    public function testRequeueMakesExactlyThoseMessagesAvailableAgain(string $engine): void
    {
        $this->database($engine, 'abandoned');
        $transport = MessengerRig::transport($this->connection);
        $old = $this->send();
        $recent = $this->send();
        $other = $this->send('failed');
        $this->takenAgo($old, 7200);
        $this->takenAgo($recent, 300);
        $this->takenAgo($other, 7200);
        $abandoned = new AbandonedMessages($this->connection);

        $this->assertSame(1, $abandoned->requeue());

        $this->assertNull($this->connection->fetchOne('select delivered_at from idno_messages where id = ?', [$old]));
        $this->assertNotNull($this->connection->fetchOne('select delivered_at from idno_messages where id = ?', [$recent]), 'a recent one is left');
        $this->assertNotNull($this->connection->fetchOne('select delivered_at from idno_messages where id = ?', [$other]), 'another queue is left');
        $received = iterator_to_array($transport->get(), false);
        $this->assertCount(1, $received);
        $this->assertSame((string) $old, (string) $received[0]->last(\Symfony\Component\Messenger\Stamp\TransportMessageIdStamp::class)->getId());
        $this->assertSame(0, $abandoned->requeue('idno'), 'nothing is left to requeue');
    }

    /**
     * @dataProvider engines
     */
    public function testAnAgeBelowAMinuteIsRefusedBecauseALiveWorkerHoldsItsMessageThatLong(string $engine): void
    {
        $this->database($engine, 'abandoned');
        $id = $this->send();
        MessengerRig::transport($this->connection)->get();
        $abandoned = new AbandonedMessages($this->connection);

        foreach ([0, 1, 59, -5] as $seconds) {
            foreach (['count', 'ids', 'requeue'] as $method) {
                try {
                    $abandoned->$method('idno', $seconds);
                    $this->fail($method . ' accepted ' . $seconds . ' seconds');
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString('60', $e->getMessage());
                }
            }
        }
        $this->assertNotNull($this->connection->fetchOne('select delivered_at from idno_messages where id = ?', [$id]), 'nothing was changed');
        $this->assertSame(0, $abandoned->count('idno', 60), 'sixty seconds is allowed');
    }
}
