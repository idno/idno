<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Kernel;
use Idno\Queue\QueuedEvent;
use Idno\Queue\QueuedEventHandler;
use Idno\Queue\WorkerHygiene;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Idno\Queue\AbandonedMessages;
use Idno\Queue\ResilientTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\SigningSerializer;
use Symfony\Component\Messenger\Worker;

/**
 * How the site's container wires the queue of events (config/packages/messenger.php): the two
 * transports on the site's own connection and table with auto_setup off, no retry, the failure
 * transport, the route and handler of QueuedEvent, the worker middleware, and the commands: the
 * ones that run and inspect the queue are there, the one that creates tables (and so would make
 * schema outside the migrations) is not. Checked in the test container (PHPUnit's kernel) and in a
 * freshly compiled prod container.
 */
final class MessengerConfigurationTest extends TestCase
{
    private ?Kernel $kernel = null;

    private ?string $cacheDir = null;

    private mixed $previousCacheDir = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        if ($this->cacheDir !== null) {
            self::removeDir($this->cacheDir);
            $this->cacheDir = null;
            if ($this->previousCacheDir === null) {
                unset($_SERVER['IDNO_CACHE_DIR']);
            } else {
                $_SERVER['IDNO_CACHE_DIR'] = $this->previousCacheDir;
            }
        }
    }

    private static function removeDir(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeDir($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function kernels(): array
    {
        return ['test (PHPUnit\'s kernel)' => ['test'], 'prod (freshly compiled)' => ['prod']];
    }

    private function kernel(string $environment): Kernel
    {
        if ($environment === 'test') {
            $kernel = Idno::container()->get('kernel');
            $this->assertInstanceOf(Kernel::class, $kernel);

            return $kernel;
        }
        $this->previousCacheDir = $_SERVER['IDNO_CACHE_DIR'] ?? null;
        $this->cacheDir = sys_get_temp_dir() . '/idno-test-cache-' . bin2hex(random_bytes(6));
        $_SERVER['IDNO_CACHE_DIR'] = $this->cacheDir;
        $this->kernel = new Kernel($environment, false, bootLegacy: false);
        $this->kernel->boot();

        return $this->kernel;
    }

    /**
     * @dataProvider kernels
     */
    public function testTheCommandsThatCreateTablesOrRunAnotherWorkerAreNotRegisteredAndTheOthersAre(string $environment): void
    {
        $names = array_keys((new Application($this->kernel($environment)))->all());

        $this->assertNotContains('messenger:setup-transports', $names, 'tables are created by migrations only');
        $this->assertNotContains('messenger:consume', $names, 'service-event-queue is the one worker: it checks the queue, the site address and the schema gate, moves the old queue\'s records and stops itself');
        $this->assertContains('service-event-queue', $names);
        foreach (['messenger:stop-workers', 'messenger:failed:show', 'messenger:failed:retry', 'messenger:failed:remove', 'messenger:stats'] as $command) {
            $this->assertContains($command, $names);
        }
    }

    /**
     * @dataProvider kernels
     */
    public function testQueuedEventsAreRoutedToTheirHandler(string $environment): void
    {
        $application = new Application($this->kernel($environment));
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'debug:messenger']);

        $output = $tester->getDisplay();
        $this->assertStringContainsString(QueuedEvent::class, $output);
        $this->assertStringContainsString(QueuedEventHandler::class, $output);
    }

    public function testTheTransportsAreOnTheSitesTableWithNoAutoSetup(): void
    {
        $container = $this->kernel('test')->getContainer();
        foreach (['idno', 'failed'] as $queue) {
            $transport = $container->get('idno.test.messenger_transport_' . $queue);
            if ($queue === 'idno') {
                // Reading the idno transport rides out a busy or lost database.
                $this->assertInstanceOf(ResilientTransport::class, $transport);
                $transport = (new \ReflectionProperty($transport, 'inner'))->getValue($transport);
            }
            $this->assertInstanceOf(DoctrineTransport::class, $transport);
            $connection = (new \ReflectionProperty($transport, 'connection'))->getValue($transport);
            $configuration = $connection->getConfiguration();

            $this->assertSame('idno_messages', $configuration['table_name']);
            $this->assertSame($queue, $configuration['queue_name']);
            $this->assertFalse($configuration['auto_setup'], 'the transport never creates the table');
            $this->assertSame('default', $configuration['connection'], 'the site\'s own DBAL connection');
            $this->assertSame(AbandonedMessages::REDELIVER_TIMEOUT_SECONDS, $configuration['redeliver_timeout'], 'nothing is redelivered by itself');
            $this->assertGreaterThanOrEqual(315360000, $configuration['redeliver_timeout']);
            $serializer = (new \ReflectionProperty($transport, 'serializer'))->getValue($transport);
            if ($serializer instanceof SigningSerializer) {
                // Messenger signs only the message types it names (its own); a QueuedEvent is not one.
                $serializer = (new \ReflectionProperty($serializer, 'inner'))->getValue($serializer);
            }
            $this->assertInstanceOf(PhpSerializer::class, $serializer, 'PHP\'s serializer: the data of an event may hold entity objects');
        }
    }

    /**
     * The container's own pieces, on the site's database: a message whose handler fails is handled
     * once (no retry, though the retry listener is there) and is in the failure transport once.
     */
    public function testAFailedMessageGoesToTheFailureTransportOnceAndIsNotRetried(): void
    {
        $container = $this->kernel('test')->getContainer();
        $idno = $container->get('idno.test.messenger_transport_idno');
        $failed = $container->get('idno.test.messenger_transport_failed');
        $dispatcher = $container->get('event_dispatcher');
        $this->assertSame(0, $idno->getMessageCount());
        $this->assertSame(0, $failed->getMessageCount());

        // Messenger logs the failure it handles: keep that line out of the test output.
        $logFile = tempnam(sys_get_temp_dir(), 'idno-messenger-log') ?: '/dev/null';
        $previousLog = ini_set('error_log', $logFile);
        $handled = [];
        $count = static function (WorkerMessageFailedEvent $event) use (&$handled): void {
            $handled[] = $event->willRetry();
        };
        $idle = 0;
        $stop = static function (WorkerRunningEvent $event) use (&$idle): void {
            $idle = $event->isWorkerIdle() ? $idle + 1 : 0;
            if ($idle >= 2) {
                $event->getWorker()->stop();
            }
        };
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $count, -1000);
        $dispatcher->addListener(WorkerRunningEvent::class, $stop);
        try {
            $container->get('idno.test.messenger_bus')->dispatch(new QueuedEvent('idno', 'queue/test/' . bin2hex(random_bytes(4)), [], 'https://example.com/user/nobody-' . bin2hex(random_bytes(4))));
            $this->assertSame(1, $idno->getMessageCount(), 'the message is routed to the idno transport');

            (new Worker(['idno' => $idno], $container->get('idno.test.messenger_bus'), $dispatcher))->run(['sleep' => 1000]);

            $this->assertSame([false], $handled, 'handled once and not retried');
            $this->assertSame(0, $idno->getMessageCount());
            $this->assertSame(1, $failed->getMessageCount(), 'in the failure transport, once');
        } finally {
            ini_set('error_log', (string) $previousLog);
            if ($logFile !== '/dev/null') {
                @unlink($logFile);
            }
            $dispatcher->removeListener(WorkerMessageFailedEvent::class, $count);
            $dispatcher->removeListener(WorkerRunningEvent::class, $stop);
            foreach ($failed->all() as $envelope) {
                $failed->reject($envelope);
            }
        }
        $this->assertSame(0, $failed->getMessageCount(), 'the test left nothing behind');
    }

    public function testAFailedMessageIsNeverRetried(): void
    {
        $strategy = $this->kernel('test')->getContainer()->get('idno.test.messenger_retry_idno');
        $envelope = new \Symfony\Component\Messenger\Envelope(new QueuedEvent('idno', 'e', [], null));

        $this->assertFalse($strategy->isRetryable($envelope), 'max_retries is 0');
    }

    public function testAFailedMessageIsNeverRetriedFromTheFailureTransportEither(): void
    {
        $strategy = $this->kernel('test')->getContainer()->get('idno.test.messenger_retry_failed');
        $envelope = new \Symfony\Component\Messenger\Envelope(new QueuedEvent('idno', 'e', [], null));

        $this->assertFalse($strategy->isRetryable($envelope), 'the failure transport has max_retries 0 too: a retry of a failed event that fails again is not re-sent');
    }

    /**
     * What an operator's `messenger:failed:retry` does, on the container's own pieces: a message in the
     * failure transport whose handler fails again is handled once by that retry and is in the failure
     * transport once afterwards (not run again by the framework's retry strategy, up to four times).
     */
    public function testARetryOfAFailedEventThatFailsAgainRunsOnceAndStaysInTheFailureTransportOnce(): void
    {
        $container = $this->kernel('test')->getContainer();
        $idno = $container->get('idno.test.messenger_transport_idno');
        $failed = $container->get('idno.test.messenger_transport_failed');
        $dispatcher = $container->get('event_dispatcher');
        $bus = $container->get('idno.test.messenger_bus');

        $logFile = tempnam(sys_get_temp_dir(), 'idno-messenger-log') ?: '/dev/null';
        $previousLog = ini_set('error_log', $logFile);
        $failures = [];
        $count = static function (WorkerMessageFailedEvent $event) use (&$failures): void {
            $failures[] = $event->getReceiverName();
        };
        $idle = 0;
        $stop = static function (WorkerRunningEvent $event) use (&$idle): void {
            $idle = $event->isWorkerIdle() ? $idle + 1 : 0;
            if ($idle >= 2) {
                $event->getWorker()->stop();
            }
        };
        $dispatcher->addListener(WorkerMessageFailedEvent::class, $count, -1000);
        $dispatcher->addListener(WorkerRunningEvent::class, $stop);
        try {
            // First failure: the idno worker handles it, it lands in `failed`.
            $bus->dispatch(new QueuedEvent('idno', 'queue/test/' . bin2hex(random_bytes(4)), [], 'https://example.com/user/nobody-' . bin2hex(random_bytes(4))));
            (new Worker(['idno' => $idno], $bus, $dispatcher))->run(['sleep' => 1000]);
            $this->assertSame(['idno'], $failures);
            $this->assertSame(1, $failed->getMessageCount());

            // The operator's retry: a worker on the failure transport (what messenger:failed:retry runs).
            $idle = 0;
            $failures = [];
            (new Worker(['failed' => $failed], $bus, $dispatcher))->run(['sleep' => 1000]);

            $this->assertSame(['failed'], $failures, 'handled once by the retry, not retried again by the framework');
            $rows = static fn (): int => (int) $container->get('database_connection')->fetchOne("select count(*) from idno_messages where queue_name = 'failed'");
            $this->assertSame(1, $rows(), 'and it is in the failure transport exactly once afterwards (kept, not lost)');
            $this->assertSame(0, $idno->getMessageCount());
            // It is back for the operator, with the error of the retry, once the pause that stops
            // `messenger:failed:retry` from finding it again at once is over.
            $container->get('database_connection')->executeStatement("update idno_messages set available_at = '2000-01-01 00:00:00' where queue_name = 'failed'");
            $listed = iterator_to_array($failed->all(), false);
            $this->assertCount(1, $listed);
            $this->assertNotNull($listed[0]->last(\Symfony\Component\Messenger\Stamp\ErrorDetailsStamp::class));
        } finally {
            ini_set('error_log', (string) $previousLog);
            if ($logFile !== '/dev/null') {
                @unlink($logFile);
            }
            $dispatcher->removeListener(WorkerMessageFailedEvent::class, $count);
            $dispatcher->removeListener(WorkerRunningEvent::class, $stop);
            $container->get('database_connection')->executeStatement("delete from idno_messages where queue_name in ('failed', 'idno')");
        }
    }

    public function testTheWorkerMiddlewareIsOnTheBus(): void
    {
        $bus = $this->kernel('test')->getContainer()->get('idno.test.messenger_bus');
        $middleware = (new \ReflectionProperty($bus, 'middlewareAggregate'))->getValue($bus);
        $classes = [];
        foreach ($middleware->getIterator() as $each) {
            $classes[] = $each::class;
        }
        $this->assertContains(WorkerHygiene::class, $classes);
    }
}
