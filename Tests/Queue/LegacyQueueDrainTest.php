<?php

declare(strict_types=1);

namespace Tests\Queue;

use Doctrine\DBAL\Connection;
use Idno\Queue\LegacyQueueDrain;
use Idno\Queue\QueuedEvent;
use Idno\Storage\AttributeTypeMap;
use Idno\Storage\RecordStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Tests\Storage\PrivateDatabase;
use Tests\Unit\Queue\CollectingLogger;

/**
 * The move of the previous queue's records (entities of the class AsynchronousQueuedEvent) into the
 * queue of messages, on a SQLite file and on a private MySQL or MariaDB database.
 */
final class LegacyQueueDrainTest extends TestCase
{
    use PrivateQueueDatabase;

    private const CLASS_NAME = 'Idno\\Entities\\AsynchronousQueuedEvent';

    private int $serial = 0;

    protected function tearDown(): void
    {
        $this->releaseDatabase();
    }

    private function store(): RecordStore
    {
        return PrivateDatabase::recordStore($this->connection, null, new AttributeTypeMap());
    }

    /** A bus that sends to the idno transport, as the site's does. */
    private function bus(): MessageBusInterface
    {
        $transport = MessengerRig::transport($this->connection);

        return new class ($transport) implements MessageBusInterface {
            public function __construct(private readonly \Symfony\Component\Messenger\Transport\TransportInterface $transport)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return $this->transport->send(new Envelope($message, $stamps));
            }
        };
    }

    /**
     * Stores a record as the previous queue's enqueue() did.
     *
     * @param array<string, mixed> $override
     */
    private function legacyEvent(string $event, int $queuedTs, array $override = []): string
    {
        $id = sprintf('00000000-0000-4000-8000-%012d', ++$this->serial);
        $record = $override + [
            '_id' => $id,
            'uuid' => 'http://example.test/view/' . $id,
            'entity_subtype' => self::CLASS_NAME,
            'owner' => '',
            'created' => $queuedTs,
            'queue' => 'default',
            'event' => $event,
            'eventData' => serialize(['n' => $queuedTs]),
            'runAsContext' => false,
            'complete' => false,
            'queuedTs' => $queuedTs,
            'access' => 'PUBLIC',
        ];
        $this->assertNotFalse($this->store()->save('entities', $record));

        return $record['_id'];
    }

    /**
     * @return list<QueuedEvent> the messages in the `idno` queue, oldest first
     */
    private function messages(): array
    {
        $messages = [];
        foreach ($this->connection->fetchAllAssociative("select body, headers from idno_messages where queue_name = 'idno' order by id") as $row) {
            $messages[] = (new PhpSerializer())->decode(['body' => $row['body'], 'headers' => json_decode((string) $row['headers'], true)])->getMessage();
        }

        return $messages;
    }

    private function remaining(): array
    {
        $ids = array_column($this->connection->fetchAllAssociative('select id from idno_entities order by id'), 'id');
        sort($ids);

        return $ids;
    }

    /**
     * @dataProvider engines
     */
    public function testPendingLegacyEventsAreMovedAndABrokenOneIsLeftAndReported(string $engine): void
    {
        $this->database($engine, 'drain');
        // Stored out of order: they must be sent in the order they were queued.
        $third = $this->legacyEvent('post/note/c', 300, ['queue' => 'mail', 'runAsContext' => 'http://example.test/view/alice', 'eventData' => serialize(['o' => new \ArrayObject([1, 2]), 'secret' => 'the-data'])]);
        $first = $this->legacyEvent('post/note/a', 100);
        $second = $this->legacyEvent('post/note/b', 200, ['runAsContext' => 'http://example.test/view/bob']);
        $unreadable = $this->legacyEvent('post/note/broken', 150, ['eventData' => 'this is not serialised secret-bytes']);
        $notAnArray = $this->legacyEvent('post/note/scalar', 160, ['eventData' => serialize('a string')]);
        $other = $this->legacyEvent('post/note/not-a-queue-record', 50, ['entity_subtype' => 'IdnoPlugins\\Status\\Status']);
        $logger = new CollectingLogger();

        $result = (new LegacyQueueDrain($this->connection, $this->store(), $this->bus(), $logger))->drain();

        $this->assertSame(['moved' => 3, 'left' => 2], $result);
        $messages = $this->messages();
        $this->assertSame(['post/note/a', 'post/note/b', 'post/note/c'], array_map(static fn (QueuedEvent $m): string => $m->event, $messages), 'in the order they were queued');
        $this->assertSame(['default', 'default', 'mail'], array_map(static fn (QueuedEvent $m): string => $m->queue, $messages));
        $this->assertSame([null, 'http://example.test/view/bob', 'http://example.test/view/alice'], array_map(static fn (QueuedEvent $m): ?string => $m->runAs, $messages));
        $this->assertSame(['n' => 100], $messages[0]->data);
        $this->assertEquals(new \ArrayObject([1, 2]), $messages[2]->data['o'], 'an object in the data is kept');
        $expectedLeft = [$unreadable, $notAnArray, $other];
        sort($expectedLeft);
        $this->assertSame($expectedLeft, $this->remaining(), 'the moved records are gone, the broken ones and any other entity are left');
        $this->assertNotContains($first, $this->remaining());
        $this->assertNotContains($second, $this->remaining());
        $this->assertNotContains($third, $this->remaining());

        $this->assertCount(2, $logger->lines, 'each broken record is logged once');
        $text = implode("\n", array_column($logger->lines, 1));
        $this->assertStringContainsString($unreadable, $text);
        $this->assertStringContainsString('post/note/broken', $text);
        $this->assertStringContainsString($notAnArray, $text);
        $this->assertStringNotContainsString('secret-bytes', $text, 'never the data');
        $this->assertStringNotContainsString('the-data', $text);

        // A second drain has nothing more to move; the broken ones are reported again.
        $again = new CollectingLogger();
        $this->assertSame(['moved' => 0, 'left' => 2], (new LegacyQueueDrain($this->connection, $this->store(), $this->bus(), $again))->drain());
        $this->assertCount(3, $this->messages());
    }

    /**
     * @dataProvider engines
     */
    public function testCompletedLegacyRecordsAreNotMoved(string $engine): void
    {
        $this->database($engine, 'drain');
        $done = $this->legacyEvent('post/note/done', 100, ['complete' => true, 'completedTs' => 110]);
        $pending = $this->legacyEvent('post/note/pending', 200);

        $this->assertSame(['moved' => 1, 'left' => 0], (new LegacyQueueDrain($this->connection, $this->store(), $this->bus(), new CollectingLogger()))->drain());

        $this->assertSame(['post/note/pending'], array_map(static fn (QueuedEvent $m): string => $m->event, $this->messages()));
        $this->assertSame([$done], $this->remaining());
        $this->assertSame(['moved' => 0, 'left' => 0], (new LegacyQueueDrain($this->connection, $this->store(), $this->bus(), new CollectingLogger()))->drain(), 'nothing pending: nothing to do');
        $this->assertNotSame($pending, $done);
    }

    /**
     * @dataProvider engines
     */
    public function testAFailedSendLeavesTheRecordAndNoMessage(string $engine): void
    {
        $this->database($engine, 'drain');
        $id = $this->legacyEvent('post/note/a', 100);
        $connection = $this->connection;
        // A bus that writes the message and then fails: both the message and the delete are rolled back.
        $failing = new class ($connection) implements MessageBusInterface {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                MessengerRig::transport($this->connection)->send(new Envelope($message));
                throw new \RuntimeException('the send failed after the insert: secret-detail');
            }
        };
        $logger = new CollectingLogger();

        $result = (new LegacyQueueDrain($this->connection, $this->store(), $failing, $logger))->drain();

        $this->assertSame(['moved' => 0, 'left' => 1], $result);
        $this->assertSame([$id], $this->remaining());
        $this->assertSame([], $this->messages(), 'the insert was rolled back with the delete');
        $this->assertFalse($this->connection->isTransactionActive());
        $this->assertCount(1, $logger->lines);
        $this->assertStringContainsString($id, $logger->lines[0][1]);
        $this->assertStringContainsString('post/note/a', $logger->lines[0][1]);
        $this->assertStringNotContainsString('secret-detail', $logger->lines[0][1], 'the exception\'s class, never its message');

        // Next time it works.
        $this->assertSame(['moved' => 1, 'left' => 0], (new LegacyQueueDrain($this->connection, $this->store(), $this->bus(), new CollectingLogger()))->drain());
    }

    /**
     * A record another worker moved after this one listed it is not sent again: the other worker's
     * delete is committed, so this one's claim removes nothing.
     *
     * @dataProvider engines
     */
    public function testARecordMovedByAnotherWorkerMeanwhileIsNotSentAgain(string $engine): void
    {
        $this->database($engine, 'drain');
        $id = $this->legacyEvent('post/note/a', 100);
        $connection = $this->connection;
        $store = $this->store();
        // After this worker READS the record, another one (here: the delete, committed) moves it.
        $racing = new class ($store, $connection) implements RecordStore {
            public function __construct(private readonly RecordStore $inner, private readonly Connection $connection)
            {
            }

            public function save(string $collection, array $record): string|false
            {
                return $this->inner->save($collection, $record);
            }

            public function findById(string $collection, string $id): ?array
            {
                $record = $this->inner->findById($collection, $id);
                $this->connection->executeStatement('delete from idno_entities where id = ?', [$id]);

                return $record;
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
                return $this->inner->delete($collection, $id);
            }

            public function deleteAll(string $collection): bool
            {
                return $this->inner->deleteAll($collection);
            }
        };

        $result = (new LegacyQueueDrain($this->connection, $racing, $this->bus(), new CollectingLogger()))->drain();

        $this->assertSame(['moved' => 0, 'left' => 0], $result, 'it was not this worker that moved it, and it is not left');
        $this->assertSame([], $this->messages(), 'nothing was sent');
        $this->assertNotSame('', $id);
    }

    /**
     * @dataProvider engines
     */
    public function testAMoveIsNotDoneTwiceWhenTwoWorkersStartTogether(string $engine): void
    {
        $database = $this->database($engine, 'drain');
        $total = 60;
        for ($i = 1; $i <= $total; ++$i) {
            $this->legacyEvent('post/note/n' . $i, 1000 + $i);
        }
        $params = $database->getParams();
        $start = sys_get_temp_dir() . '/idno-drain-start-' . bin2hex(random_bytes(6));
        $environment = ['IDNO_TEST_DRAIN' => json_encode(['params' => $params, 'start' => $start], JSON_THROW_ON_ERROR), 'PATH' => (string) getenv('PATH')];
        $processes = [];
        for ($worker = 0; $worker < 2; ++$worker) {
            $process = proc_open([PHP_BINARY, '-d', 'error_reporting=22527', __DIR__ . '/drain.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $environment);
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        touch($start);
        $totals = ['moved' => 0, 'left' => 0];
        foreach ($processes as [$process, $pipes]) {
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $out . $err);
            $result = json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);
            $totals['moved'] += $result['moved'];
            $totals['left'] += $result['left'];
        }
        unlink($start);

        $this->assertSame(['moved' => $total, 'left' => 0], $totals, 'every record moved by exactly one of them');
        $events = array_map(static fn (QueuedEvent $m): string => $m->event, $this->messages());
        $this->assertCount($total, $events);
        $this->assertCount($total, array_unique($events), 'no event was sent twice');
        $this->assertSame([], $this->remaining());
    }

    /**
     * @dataProvider engines
     */
    public function testARecordWhoseDataHoldsAClassThatNoLongerExistsIsLeftAndReported(string $engine): void
    {
        $this->database($engine, 'drain');
        // An entity of a class that is gone (a removed plugin) unserialises to __PHP_Incomplete_Class,
        // here nested in the data as an event's data holds an entity.
        $gone = $this->legacyEvent('post/note/gone', 100, ['eventData' => 'a:1:{s:6:"entity";a:1:{i:0;O:21:"Removed\\Plugin\\Entity":1:{s:1:"a";i:1;}}}']);
        $good = $this->legacyEvent('post/note/good', 200);
        $logger = new CollectingLogger();

        $result = (new LegacyQueueDrain($this->connection, $this->store(), $this->bus(), $logger))->drain();

        $this->assertSame(['moved' => 1, 'left' => 1], $result);
        $this->assertSame([$gone], $this->remaining());
        $this->assertSame(['post/note/good'], array_map(static fn (QueuedEvent $m): string => $m->event, $this->messages()));
        $this->assertCount(1, $logger->lines);
        $this->assertStringContainsString($gone, $logger->lines[0][1]);
        $this->assertStringContainsString('post/note/gone', $logger->lines[0][1]);
        $this->assertStringNotContainsString('Removed', $logger->lines[0][1], 'never the data');
        $this->assertNotSame($good, $gone);
    }

    /**
     * @dataProvider engines
     */
    public function testItStopsBetweenRecordsWhenAskedAndTheRestStayPending(string $engine): void
    {
        $this->database($engine, 'drain');
        for ($i = 1; $i <= 5; ++$i) {
            $this->legacyEvent('post/note/n' . $i, 100 * $i);
        }
        $asked = 0;
        $stopAfterTwo = static function () use (&$asked): bool {
            return $asked++ >= 2;
        };

        $result = (new LegacyQueueDrain($this->connection, $this->store(), $this->bus(), new CollectingLogger()))->drain($stopAfterTwo);

        $this->assertSame(['moved' => 2, 'left' => 0], $result);
        $this->assertSame(['post/note/n1', 'post/note/n2'], array_map(static fn (QueuedEvent $m): string => $m->event, $this->messages()), 'whole records, oldest first');
        $this->assertCount(3, $this->remaining());
        $this->assertFalse($this->connection->isTransactionActive());

        // A later run moves the rest, and nothing twice.
        $this->assertSame(['moved' => 3, 'left' => 0], (new LegacyQueueDrain($this->connection, $this->store(), $this->bus(), new CollectingLogger()))->drain());
        $this->assertCount(5, array_unique(array_map(static fn (QueuedEvent $m): string => $m->event, $this->messages())));
    }

    /**
     * @dataProvider engines
     */
    public function testCompletedRecordsAreRemovedInBoundedBatchesAndPendingOnesAreNot(string $engine): void
    {
        $this->database($engine, 'drain');
        for ($i = 1; $i <= 5; ++$i) {
            $this->legacyEvent('post/note/done' . $i, 100 * $i, ['complete' => true, 'completedTs' => 100 * $i + 1]);
        }
        $pending = $this->legacyEvent('post/note/pending', 900);
        $other = $this->legacyEvent('post/note/other', 50, ['entity_subtype' => 'IdnoPlugins\\Status\\Status', 'complete' => true]);
        $drain = new LegacyQueueDrain($this->connection, $this->store(), $this->bus(), new CollectingLogger());

        $this->assertSame(3, $drain->removeCompleted(3), 'at most the limit');
        $this->assertCount(4, $this->remaining());
        $this->assertSame(2, $drain->removeCompleted(3));
        $this->assertSame(0, $drain->removeCompleted(3));
        $expected = [$pending, $other];
        sort($expected);
        $this->assertSame($expected, $this->remaining(), 'a pending record and another entity are left');
        $this->assertSame([], $this->messages(), 'removing completed records sends nothing');
    }

    /**
     * Another worker that holds the claim's lock past the busy timeout makes this worker's delete fail
     * with a lock failure: the record is the other worker's, not "left": no warning, not counted, and the
     * drain goes on to the next record.
     */
    public function testAClaimThatFindsTheDatabaseBusyIsNotOursAndIsNotReportedAsLeft(): void
    {
        $this->database('sqlite', 'drain');
        $first = $this->legacyEvent('post/note/a', 100);
        $second = $this->legacyEvent('post/note/b', 200);
        $driver = new class('database is locked') extends \Doctrine\DBAL\Driver\AbstractException {
        };
        $claims = 0;
        $busy = $this->createMock(Connection::class);
        $busy->method('beginTransaction');
        $busy->method('isTransactionActive')->willReturn(true);
        $busy->expects($this->once())->method('rollBack'); // the busy claim's transaction
        $busy->method('executeStatement')->willReturnCallback(function (string $sql, array $params = []) use (&$claims, $driver): int {
            if (++$claims === 1) {
                throw new \Doctrine\DBAL\Exception\LockWaitTimeoutException($driver, null);
            }

            return $this->connection->executeStatement($sql, $params);
        });
        $busy->method('commit');
        $logger = new CollectingLogger();

        $result = (new LegacyQueueDrain($busy, $this->store(), $this->bus(), $logger))->drain();

        $this->assertSame(['moved' => 1, 'left' => 0], $result, 'the busy one is neither moved nor left');
        $this->assertSame([], array_filter($logger->lines, static fn (array $line): bool => in_array($line[0], ['warning', 'error'], true)), 'nothing is reported as a problem');
        $this->assertSame(['post/note/b'], array_map(static fn (QueuedEvent $m): string => $m->event, $this->messages()), 'the next record was moved');
        $this->assertContains($first, $this->remaining(), 'the busy record is still there for whoever holds it');
        $this->assertNotContains($second, $this->remaining());
    }
}
