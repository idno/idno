<?php

declare(strict_types=1);

namespace Tests\Queue;

use Idno\Queue\KeepFailedMessage;
use Idno\Queue\QueuedEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * An event retried from the failure transport that fails again is put back there: the new copy is
 * written and the received copy's row is deleted in ONE transaction, so a crash between the two can
 * leave neither two copies nor none. On a SQLite file and on a private MySQL or MariaDB database.
 */
final class KeepFailedMessageTest extends TestCase
{
    use PrivateQueueDatabase;

    protected function tearDown(): void
    {
        $this->releaseDatabase();
    }

    /**
     * @return array{Envelope, int} the envelope a worker received from `failed`, and the original row's id
     */
    private function received(): array
    {
        $failed = MessengerRig::transport($this->connection, 'failed');
        $failed->send(new Envelope(new QueuedEvent('idno', 'e', [], null)));
        $received = iterator_to_array($failed->get(), false)[0];

        return [$received->with(new ReceivedStamp('failed')), (int) $this->connection->fetchOne("select id from idno_messages where queue_name = 'failed'")];
    }

    private function failure(Envelope $envelope): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent($envelope->with(new ErrorDetailsStamp(\RuntimeException::class, 0, 'the retry failed again')), 'failed', new \RuntimeException('the retry failed again'));
    }

    /**
     * @dataProvider engines
     */
    public function testARetryThatFailsLeavesExactlyOneRowWithTheNewError(string $engine): void
    {
        $this->database($engine, 'keepfailed');
        [$envelope, $original] = $this->received();
        $keep = new KeepFailedMessage(MessengerRig::transport($this->connection, 'failed'), $this->connection);

        $keep->onMessageFailed($this->failure($envelope));

        // Already one row, before the worker rejects the received message: the new copy and the delete of the
        // original were one transaction, so a crash right here leaves one copy, not two.
        $this->assertSame(1, (int) $this->connection->fetchOne("select count(*) from idno_messages where queue_name = 'failed'"));
        // What the worker does next: it rejects the received message. Its row is already gone: tolerated.
        MessengerRig::transport($this->connection, 'failed')->reject($envelope);
        $this->assertSame(1, (int) $this->connection->fetchOne("select count(*) from idno_messages where queue_name = 'failed'"));
        $row = $this->connection->fetchAssociative("select id, body from idno_messages where queue_name = 'failed'");
        $this->assertNotSame($original, (int) $row['id']);
        $this->assertStringContainsString('the retry failed again', $row['body']);
    }

    /**
     * @dataProvider engines
     */
    public function testWhenTheNewCopyCannotBeWrittenTheOriginalIsStillThere(string $engine): void
    {
        $this->database($engine, 'keepfailed');
        [$envelope, $original] = $this->received();
        $broken = new class implements SenderInterface {
            public function send(Envelope $envelope): Envelope
            {
                throw new \RuntimeException('cannot write');
            }
        };

        try {
            (new KeepFailedMessage($broken, $this->connection))->onMessageFailed($this->failure($envelope));
            $this->fail('The failure was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('cannot write', $e->getMessage());
        }

        $this->assertSame([$original], array_map('intval', $this->connection->fetchFirstColumn("select id from idno_messages where queue_name = 'failed'")), 'nothing was lost');
    }

    /**
     * @dataProvider engines
     */
    public function testAFailureOfAnotherReceiverIsLeftAlone(string $engine): void
    {
        $this->database($engine, 'keepfailed');
        [$envelope] = $this->received();
        $event = new WorkerMessageFailedEvent($envelope, 'idno', new \RuntimeException('x'));

        (new KeepFailedMessage(MessengerRig::transport($this->connection, 'failed'), $this->connection))->onMessageFailed($event);

        $this->assertSame(1, (int) $this->connection->fetchOne("select count(*) from idno_messages where queue_name = 'failed'"));
    }
}
