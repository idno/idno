<?php

declare(strict_types=1);

namespace Tests\Queue;

use Doctrine\DBAL\Connection;
use Idno\Queue\QueuedEvent;
use Idno\Queue\QueuedEventHandler;
use Idno\Storage\Criteria;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\AddErrorDetailsStampListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Worker;
use Tests\Storage\PrivateDatabase;
use Tests\Unit\Queue\RecordingEventTrigger;
use Tests\Unit\Queue\RecordingUserContext;

/**
 * The queue's table and transport on databases made by the schema migrations only (never by the
 * transport: auto_setup is off, as on the site): a SQLite file, and a private database of the rig's
 * MySQL or MariaDB server when the rig runs on one.
 */
final class TransportTest extends TestCase
{
    use PrivateQueueDatabase;

    protected function tearDown(): void
    {
        $this->releaseDatabase();
    }

    /**
     * @dataProvider engines
     */
    public function testTheMigrationCreatesTheTableTheTransportExpects(string $engine): void
    {
        $connection = $this->database($engine, 'transport');
        $transport = MessengerRig::transport($connection);

        $sent = $transport->send(new Envelope(new QueuedEvent('idno', 'syndicate', ['a' => 1], 'https://example.com/user/1')));
        $this->assertNotNull($sent->last(\Symfony\Component\Messenger\Stamp\TransportMessageIdStamp::class));

        $received = iterator_to_array($transport->get(), false);
        $this->assertCount(1, $received);
        $message = $received[0]->getMessage();
        $this->assertInstanceOf(QueuedEvent::class, $message);
        $this->assertEquals(new QueuedEvent('idno', 'syndicate', ['a' => 1], 'https://example.com/user/1'), $message);

        $transport->ack($received[0]);
        $this->assertSame([], iterator_to_array($transport->get(), false), 'an acknowledged message is gone');
        $this->assertSame(0, (int) $connection->fetchOne('select count(*) from ' . MessengerRig::TABLE));
    }

    /**
     * @dataProvider engines
     */
    public function testNothingTheTransportDoesCreatesOrChangesSchema(string $engine): void
    {
        $connection = $this->database($engine, 'transport');
        $before = $connection->createSchemaManager()->listTableNames();
        $transport = MessengerRig::transport($connection);
        $transport->send(new Envelope(new QueuedEvent('idno', 'e', [], null)));
        foreach ($transport->get() as $envelope) {
            $transport->ack($envelope);
        }
        $transport->getMessageCount();

        $this->assertEqualsCanonicalizing($before, $connection->createSchemaManager()->listTableNames());
    }

    /**
     * @dataProvider engines
     */
    public function testWithoutTheTableTheTransportFailsInsteadOfCreatingIt(string $engine): void
    {
        $connection = $this->database($engine, 'transport');
        $connection->executeStatement('drop table ' . MessengerRig::TABLE);
        $transport = MessengerRig::transport($connection);

        try {
            $transport->send(new Envelope(new QueuedEvent('idno', 'e', [], null)));
            $this->fail('Sending without the table did not fail.');
        } catch (\Throwable) {
            // expected
        }
        $this->assertNotContains(MessengerRig::TABLE, $connection->createSchemaManager()->listTableNames(), 'auto_setup is off: nothing creates the table');
    }

    /**
     * @dataProvider engines
     */
    public function testAMessageKeepsItsDataIncludingAnEntity(string $engine): void
    {
        $connection = $this->database($engine, 'transport');
        $transport = MessengerRig::transport($connection);
        $entity = new \IdnoPlugins\Status\Status();
        $entity->setTitle('A title');
        $entity->body = "A body with \"quotes\", a NUL \0 and emoji \u{1F600}";
        $entity->tags = ['one', 'two'];
        $data = ['object' => $entity, 'list' => [1, 2.5, true, null, 'text'], 'nested' => ['k' => ['v']]];

        $transport->send(new Envelope(new QueuedEvent('idno', 'syndicate', $data, null)));
        $received = iterator_to_array($transport->get(), false);

        $this->assertCount(1, $received);
        $this->assertEquals($data, $received[0]->getMessage()->data);
        $this->assertInstanceOf(\IdnoPlugins\Status\Status::class, $received[0]->getMessage()->data['object']);
        $this->assertSame($entity->body, $received[0]->getMessage()->data['object']->body);
    }

    /**
     * @dataProvider engines
     */
    public function testTwoConsumersNeverHandleOneMessageTwice(string $engine): void
    {
        $connection = $this->database($engine, 'transport');
        $transport = MessengerRig::transport($connection);
        for ($id = 1; $id <= 100; ++$id) {
            $transport->send(new Envelope(new QueuedEvent('idno', 'e', ['id' => $id], null)));
        }
        $output = ($this->directory ?? sys_get_temp_dir()) . '/handled-' . bin2hex(random_bytes(4)) . '.txt';
        $settings = json_encode(['params' => $connection->getParams(), 'output' => $output, 'work_us' => 5000], JSON_THROW_ON_ERROR);

        $processes = [];
        $pipes = [];
        $reports = [];
        try {
            for ($i = 0; $i < 2; ++$i) {
                $processes[$i] = proc_open(
                    [PHP_BINARY, __DIR__ . '/consumer.php'],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes[$i],
                    null,
                    ['IDNO_TEST_CONSUMER' => $settings] + array_filter(getenv(), 'is_string'),
                );
                $this->assertIsResource($processes[$i]);
                stream_set_blocking($pipes[$i][1], false);
                stream_set_blocking($pipes[$i][2], false);
            }
            $reports = $this->collect($processes, $pipes);
        } finally {
            foreach ($processes as $i => $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
                foreach ($pipes[$i] ?? [] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }
        }

        $ids = array_map('intval', file($output, FILE_IGNORE_NEW_LINES) ?: []);
        @unlink($output);
        sort($ids);
        $this->assertSame(range(1, 100), $ids, 'each of the 100 messages was handled exactly once');
        foreach ($reports as $i => $report) {
            $this->assertSame(0, $report['exit'], 'consumer ' . $i . ' did not die: ' . $report['stderr']);
            $this->assertGreaterThan(0, $report['handled'], 'consumer ' . $i . ' handled some of the messages');
        }
        $this->assertSame(100, $reports[0]['handled'] + $reports[1]['handled']);
        $this->assertSame(0, (int) $connection->fetchOne('select count(*) from ' . MessengerRig::TABLE), 'none is left');
    }

    /**
     * Reads the consumers' output without blocking, until both have ended or the deadline passes (then
     * they are terminated and the test fails).
     *
     * @param array<int, resource>             $processes
     * @param array<int, array<int, resource>> $pipes
     *
     * @return array<int, array{exit: int, handled: int, stderr: string}>
     */
    private function collect(array $processes, array $pipes): array
    {
        $deadline = microtime(true) + 90;
        $out = array_fill(0, count($processes), ['', '']);
        $open = [];
        foreach ($pipes as $i => $pair) {
            $open[$i] = [1 => $pair[1], 2 => $pair[2]];
        }
        while ($open !== []) {
            if (microtime(true) > $deadline) {
                foreach ($processes as $process) {
                    proc_terminate($process);
                }
                $this->fail('A consumer did not finish within 90 seconds.');
            }
            $read = [];
            foreach ($open as $pair) {
                array_push($read, ...array_values($pair));
            }
            $write = $except = null;
            if (@stream_select($read, $write, $except, 1) === false) {
                continue;
            }
            foreach ($open as $i => $pair) {
                foreach ($pair as $n => $stream) {
                    $chunk = stream_get_contents($stream);
                    $out[$i][$n - 1] .= (string) $chunk;
                    if (feof($stream)) {
                        unset($open[$i][$n]);
                    }
                }
                if ($open[$i] === []) {
                    unset($open[$i]);
                }
            }
        }
        $reports = [];
        foreach ($processes as $i => $process) {
            $exit = proc_close($process);
            $report = json_decode(trim($out[$i][0]), true);
            $reports[$i] = ['exit' => $exit, 'handled' => (int) ($report['handled'] ?? 0), 'stderr' => $out[$i][1]];
        }

        return $reports;
    }

    /**
     * @dataProvider engines
     */
    public function testAFailedMessageGoesToTheFailureTransportOnceAndIsNotRetried(string $engine): void
    {
        $connection = $this->database($engine, 'transport');
        $main = MessengerRig::transport($connection, 'idno');
        $failed = MessengerRig::transport($connection, 'failed');
        $calls = 0;
        $bus = MessengerRig::bus([QueuedEvent::class => [function () use (&$calls): void {
            ++$calls;
            throw new \RuntimeException('the listener failed');
        }]]);
        $main->send(new Envelope(new QueuedEvent('idno', 'syndicate', [], null)));

        $this->runUntilIdle(new Worker(['idno' => $main], $bus, $this->dispatcher($main, $failed)));

        $this->assertSame(1, $calls, 'a failed message is handled once, not retried');
        $this->assertSame(0, $main->getMessageCount());
        $this->assertSame(1, $failed->getMessageCount(), 'it is in the failure transport once');
        $stored = iterator_to_array($failed->get(), false)[0];
        $this->assertInstanceOf(QueuedEvent::class, $stored->getMessage());
        $this->assertStringContainsString('the listener failed', (string) $stored->last(\Symfony\Component\Messenger\Stamp\ErrorDetailsStamp::class)?->getExceptionMessage());
    }

    /**
     * A listener that throws an `\Error` (a TypeError: the old queue's poison event) is a failure like any
     * other: the message goes to the failure transport once, nothing retries it, and the worker goes on to
     * the next message.
     *
     * @dataProvider engines
     */
    public function testAListenerThatThrowsAnErrorFailsOnceAndTheWorkerGoesOn(string $engine): void
    {
        $connection = $this->database($engine, 'transport');
        $main = MessengerRig::transport($connection, 'idno');
        $failed = MessengerRig::transport($connection, 'failed');
        $handled = [];
        $bus = MessengerRig::bus([QueuedEvent::class => [function (QueuedEvent $message) use (&$handled): void {
            $handled[] = $message->event;
            if ($message->event === 'poison') {
                throw new \TypeError('Cannot access offset of type string on string');
            }
        }]]);
        $main->send(new Envelope(new QueuedEvent('idno', 'poison', [], null)));
        $main->send(new Envelope(new QueuedEvent('idno', 'good', [], null)));

        $this->runUntilIdle(new Worker(['idno' => $main], $bus, $this->dispatcher($main, $failed)));

        $this->assertSame(['poison', 'good'], $handled, 'each handled once, the good one after the poison one');
        $this->assertSame(0, $main->getMessageCount());
        $this->assertSame(1, $failed->getMessageCount(), 'the poison event is kept once');
        $stored = iterator_to_array($failed->get(), false)[0];
        $this->assertSame('poison', $stored->getMessage()->event);
        $this->assertStringContainsString('Cannot access offset', (string) $stored->last(\Symfony\Component\Messenger\Stamp\ErrorDetailsStamp::class)?->getExceptionMessage());
    }

    /**
     * @dataProvider engines
     */
    public function testAnEventForAUserThatNoLongerExistsFailsOnceAndTheNextIsHandled(string $engine): void
    {
        $connection = $this->database($engine, 'transport');
        $main = MessengerRig::transport($connection, 'idno');
        $failed = MessengerRig::transport($connection, 'failed');
        $trigger = new RecordingEventTrigger();
        $handler = new QueuedEventHandler($trigger, new RecordingUserContext(null, ['https://example.com/user/1']));
        $bus = MessengerRig::bus([QueuedEvent::class => [$handler]]);
        $main->send(new Envelope(new QueuedEvent('idno', 'first', [], 'https://example.com/user/gone')));
        $main->send(new Envelope(new QueuedEvent('idno', 'second', [], 'https://example.com/user/1')));

        $this->runUntilIdle(new Worker(['idno' => $main], $bus, $this->dispatcher($main, $failed)));

        $this->assertSame([['second', []]], $trigger->triggered, 'only the second event was triggered');
        $this->assertSame(1, $failed->getMessageCount());
        $this->assertSame(0, $main->getMessageCount());
    }

    /**
     * @dataProvider engines
     */
    public function testTheTableIsNotACollection(string $engine): void
    {
        $connection = $this->database($engine, 'transport');
        $store = PrivateDatabase::recordStore($connection);
        MessengerRig::transport($connection)->send(new Envelope(new QueuedEvent('idno', 'e', [], null)));

        $this->assertSame([], $store->find('messages', Criteria::fromLegacyArray([]), 10, 0));
        $this->assertSame(0, $store->count('messages', Criteria::fromLegacyArray([])));
        $this->assertFalse($store->save('messages', ['_id' => 'x', 'entity_subtype' => 'Foo']));
        $this->assertSame(1, (int) $connection->fetchOne('select count(*) from ' . MessengerRig::TABLE), 'the store wrote nothing to it');
    }

    private function dispatcher(\Symfony\Component\Messenger\Transport\TransportInterface $main, \Symfony\Component\Messenger\Transport\TransportInterface $failed): EventDispatcher
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new AddErrorDetailsStampListener());
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(new ServiceLocator(['idno' => static fn () => $failed])));
        $idle = 0;
        $dispatcher->addListener(WorkerRunningEvent::class, function (WorkerRunningEvent $event) use (&$idle): void {
            $idle = $event->isWorkerIdle() ? $idle + 1 : 0;
            if ($idle >= 2) {
                $event->getWorker()->stop();
            }
        });

        return $dispatcher;
    }

    private function runUntilIdle(Worker $worker): void
    {
        $worker->run(['sleep' => 1000]);
    }
}
