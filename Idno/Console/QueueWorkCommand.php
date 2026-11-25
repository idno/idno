<?php

declare(strict_types=1);

namespace Idno\Console;

use Idno\Config\BootConfig;
use Idno\Core\EventQueue;
use Idno\Queue\AbandonedMessages;
use Idno\Queue\LegacyQueueDrain;
use Idno\Queue\QueuedEvent;
use Idno\Queue\StopWhenQueueIsEmpty;
use Idno\Queue\WorkerSiteUrl;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnTimeLimitListener;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

/**
 * `service-event-queue`: the worker of the queue of events, the command sites run under systemd or
 * cron (the README). It refuses (exit 1) unless the site's queue is asynchronous
 * (`event_queue = 'AsynchronousQueue'`), moves the events the previous version of Idno left in its
 * own records into the queue (LegacyQueueDrain), and then consumes the `idno` transport, as
 * a Messenger worker does, printing one line for each event it takes and one when it is done
 * or fails. With --once it handles everything waiting now and exits 0; otherwise it runs until it is
 * stopped (SIGTERM, SIGINT), sleeping --interval seconds when the queue is empty, and stops by itself
 * (exit 0, for a supervisor to start again) when it has used a lot of memory or after --time-limit
 * seconds (so that changed settings and plugins are read), and on `messenger:stop-workers`. It refuses
 * (exit 1) unless the boot configuration has an explicit absolute `url`: it never guesses the address.
 * It is the only worker: `messenger:consume` is removed (Idno\Kernel\MessengerTrimPass).
 *
 * A failed event is not retried: Messenger keeps it in the `failed` transport with its error
 * (`messenger:failed:show`, `retry`, `remove`). A message a worker took and never finished (the
 * worker was killed) is never given to another by itself; at start the command says how many there
 * are, and --requeue-abandoned puts those taken over an hour ago back in the queue.
 *
 * It needs the legacy application (the events' listeners are legacy code): while the schema gate is
 * closed the console says why, and this command refuses.
 *
 * `--queue` is accepted as it always was (`default`). The previous queue kept the queue name of each
 * event and a worker handled one queue's events. Events now share one queue of messages that cannot
 * be filtered by that name, and nothing bundled enqueues to any name but `default`, so a worker
 * handles the events of every queue name; it says so when given another one.
 */
#[AsCommand('service-event-queue', 'Runs the asynchronous event queue worker')]
final class QueueWorkCommand extends Command implements SignalableCommandInterface
{
    /**
     * The most memory a worker uses before it stops (exit 0) for a supervisor to start it again; a
     * lower `memory_limit` lowers it (memoryLimitBytes()).
     */
    public const MEMORY_LIMIT_BYTES = 268435456;

    /** How long the daemon runs before it stops itself, in seconds (`--time-limit`): changed settings and plugins reach a new process. */
    public const DEFAULT_TIME_LIMIT_SECONDS = 3600;

    /** Seconds `--once` waits between reads while the database is busy. */
    private const BUSY_SLEEP_MICROSECONDS = 500000;

    /** The age at which a message a worker took is called abandoned, in seconds. */
    public const ABANDONED_AFTER_SECONDS = 3600;

    private ?Worker $worker = null;

    /** Set by SIGTERM, SIGINT or SIGQUIT, whenever it arrives: the command ends at the next safe point. */
    private bool $stopRequested = false;

    /**
     * @param \Closure(): EventQueue         $queue
     * @param \Closure(): LegacyQueueDrain   $drain
     * @param \Closure(): AbandonedMessages  $abandoned
     * @param \Closure(): ReceiverInterface  $transport the `idno` transport
     * @param \Closure(): MessageBusInterface $bus
     * @param \Closure(): LoggerInterface    $logger
     * @param (\Closure(): int)|null         $memoryUsage the bytes the process uses, asked between messages (null: PHP's real usage); a seam for tests, whose own process holds a whole suite
     */
    public function __construct(
        #[AutowireServiceClosure(EventQueue::class)]
        private readonly \Closure $queue,
        #[AutowireServiceClosure(LegacyQueueDrain::class)]
        private readonly \Closure $drain,
        #[AutowireServiceClosure(AbandonedMessages::class)]
        private readonly \Closure $abandoned,
        #[AutowireServiceClosure('messenger.transport.idno')]
        private readonly \Closure $transport,
        #[AutowireServiceClosure('messenger.bus.default')]
        private readonly \Closure $bus,
        #[AutowireServiceClosure(LoggerInterface::class)]
        private readonly \Closure $logger,
        private readonly EventDispatcherInterface $events,
        private readonly KernelInterface $kernel,
        private readonly BootConfig $bootConfig,
        private readonly float $busyGiveUpSeconds = 30.0,
        private readonly ?\Closure $memoryUsage = null,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'The named queue to process (every queue name is served: see the command\'s documentation)', 'default');
        $this->addOption('interval', null, InputOption::VALUE_OPTIONAL, 'Polling interval in seconds', 1);
        $this->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Seconds the daemon runs before it stops itself after its current message (exit 0, for a supervisor to start it again with the current settings and plugins); 0 means no limit', (string) self::DEFAULT_TIME_LIMIT_SECONDS);
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Process the pending events and exit (for use with cron)');
        $this->addOption('requeue-abandoned', null, InputOption::VALUE_NONE, 'Put the messages a worker took over an hour ago and never finished back in the queue before starting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->kernel instanceof \Idno\Kernel && !$this->kernel->legacyBooted()) {
            $output->writeln('<error>The event queue worker needs the site\'s database to be set up: run php bin/console idno:upgrade first.</error>');

            return 1;
        }
        $queueName = (string) $input->getOption('queue');
        $interval = max(1, (int) $input->getOption('interval'));
        $once = (bool) $input->getOption('once');

        $queue = ($this->queue)();
        if (!$queue->isAsynchronous()) {
            $output->writeln('<error>The event queue is not set to AsynchronousQueue. Set event_queue = \'AsynchronousQueue\' in config.ini.</error>');

            return 1;
        }

        // The worker never guesses the site's address: what it signs and sends names it.
        $problem = WorkerSiteUrl::problem($this->bootConfig->get('url'));
        if ($problem !== null) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('<error>' . $problem . '</error>');

            return 1;
        }

        if ($once) {
            $output->writeln("Processing pending events from queue '$queueName' (single run)...");
        } else {
            $output->writeln("Starting event queue worker for queue '$queueName' (polling every {$interval}s)...");
            $output->writeln('Press Ctrl+C to stop.');
        }
        if ($queueName !== 'default') {
            $output->writeln("Events of every queue name are handled by this worker, not only '$queueName'.");
        }

        if ($this->stopped($output)) {
            return 0;
        }
        $this->reportAbandoned($input, $output);

        $drain = ($this->drain)();
        $drained = $drain->drain(fn (): bool => $this->stopRequested);
        if ($drained['moved'] > 0 || $drained['left'] > 0) {
            $output->writeln('Moved ' . $drained['moved'] . ' event(s) queued by the previous version of Idno into the queue.' . ($drained['left'] > 0 ? ' ' . $drained['left'] . ' could not be moved and were left where they are (see the log).' : ''));
        }
        // Records of the previous queue that are complete are of no further use: remove a bounded
        // number at each start.
        if (!$this->stopRequested) {
            try {
                if (($removed = $drain->removeCompleted()) > 0) {
                    $output->writeln('Removed ' . $removed . ' completed record(s) of the previous queue.');
                }
            } catch (\Throwable $e) {
                // Housekeeping never keeps the worker from consuming; the exception's class only.
                ($this->logger)()->warning('The completed records of the previous queue could not be removed: ' . $e::class);
            }
        }
        if ($this->stopped($output)) {
            return 0;
        }

        if ($this->consume($output, $interval, $once, self::timeLimitSeconds((string) $input->getOption('time-limit')))) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('<error>The database stayed busy for ' . (int) $this->busyGiveUpSeconds . ' seconds: events may be waiting in the queue. Run the worker again.</error>');

            return 1;
        }

        return 0;
    }

    /** Says so and returns true when a stop was requested (the command then ends, exit 0). */
    private function stopped(OutputInterface $output): bool
    {
        if ($this->stopRequested) {
            $output->writeln('Stopped.');
        }

        return $this->stopRequested;
    }

    /**
     * The memory the worker may use before it stops itself: 80% of PHP's `memory_limit` when that is set
     * and the result is below MEMORY_LIMIT_BYTES, else MEMORY_LIMIT_BYTES.
     */
    public static function memoryLimitBytes(string|false $iniLimit): int
    {
        $text = is_string($iniLimit) ? strtolower(trim($iniLimit)) : '';
        if (preg_match('/^(\d+)([kmg]?)$/', $text, $m) !== 1) {
            return self::MEMORY_LIMIT_BYTES;
        }
        $bytes = (int) $m[1] * match ($m[2]) {
            'k' => 1024,
            'm' => 1048576,
            'g' => 1073741824,
            default => 1,
        };
        if ($bytes <= 0) {
            return self::MEMORY_LIMIT_BYTES;
        }

        return min(self::MEMORY_LIMIT_BYTES, (int) ($bytes * 0.8));
    }

    private function reportAbandoned(InputInterface $input, OutputInterface $output): void
    {
        $abandoned = ($this->abandoned)();
        if ($input->getOption('requeue-abandoned')) {
            $output->writeln('Requeued ' . $abandoned->requeue(AbandonedMessages::QUEUE, self::ABANDONED_AFTER_SECONDS) . ' abandoned message(s).');

            return;
        }
        $count = $abandoned->count(AbandonedMessages::QUEUE, self::ABANDONED_AFTER_SECONDS);
        if ($count > 0) {
            $output->writeln("<comment>$count message(s) were taken by a worker more than an hour ago and never finished; nothing runs them again by itself. If their events are safe to run again, start the worker once with --requeue-abandoned.</comment>");
        }
    }

    /**
     * @return bool true when `--once` gave up because the database stayed busy
     */
    /**
     * The daemon's time limit from the option: whole seconds, 0 (or less) for none; a value that is not a
     * number is the default.
     */
    public static function timeLimitSeconds(string $option): int
    {
        if (!is_numeric($option)) {
            return self::DEFAULT_TIME_LIMIT_SECONDS;
        }

        return max(0, (int) $option);
    }

    private function consume(OutputInterface $output, int $interval, bool $once, int $timeLimit): bool
    {
        $transport = ($this->transport)();
        $memoryLimit = self::memoryLimitBytes(ini_get('memory_limit'));
        $stopWhenEmpty = $once ? new StopWhenQueueIsEmpty($transport, $this->busyGiveUpSeconds) : null;
        $listeners = [
            WorkerMessageReceivedEvent::class => static function (WorkerMessageReceivedEvent $event) use ($output): void {
                $message = $event->getEnvelope()->getMessage();
                if ($message instanceof QueuedEvent) {
                    $output->writeln('[' . date('r') . '] Dispatching event ' . self::idOf($event->getEnvelope()) . ': ' . $message->event);
                }
            },
            WorkerMessageHandledEvent::class => static function (WorkerMessageHandledEvent $event) use ($output): void {
                if ($event->getEnvelope()->getMessage() instanceof QueuedEvent) {
                    $output->writeln('[' . date('r') . '] Event ' . self::idOf($event->getEnvelope()) . ' completed.');
                }
            },
            WorkerMessageFailedEvent::class => static function (WorkerMessageFailedEvent $event) use ($output): void {
                if ($event->getEnvelope()->getMessage() instanceof QueuedEvent) {
                    $output->writeln('<error>[' . date('r') . '] Error dispatching event ' . self::idOf($event->getEnvelope()) . ': ' . self::describe($event->getThrowable()) . ' (kept in the failure transport: messenger:failed:show)</error>');
                }
            },
            WorkerRunningEvent::class => function (WorkerRunningEvent $event) use ($output, $memoryLimit): void {
                if (($this->memoryUsage !== null ? ($this->memoryUsage)() : memory_get_usage(true)) > $memoryLimit) {
                    $output->writeln('[' . date('r') . '] Stopping: the worker has used a lot of memory; a supervisor starts it again.');
                    $event->getWorker()->stop();
                }
            },
        ];
        foreach ($listeners as $name => $listener) {
            $this->events->addListener($name, $listener);
        }
        $subscribers = [];
        if ($stopWhenEmpty !== null) {
            $subscribers[] = $stopWhenEmpty;
        } else {
            // The daemon stops after the time limit (between messages). `messenger:stop-workers` needs nothing
            // here: FrameworkBundle registers its listener (StopWorkerOnRestartSignalListener) on the event
            // dispatcher this worker runs on, for `--once` runs too.
            if ($timeLimit > 0) {
                $subscribers[] = new StopWorkerOnTimeLimitListener($timeLimit, ($this->logger)());
            }
        }
        foreach ($subscribers as $subscriber) {
            $this->events->addSubscriber($subscriber);
        }
        $this->worker = new Worker(['idno' => $transport], ($this->bus)(), $this->events, ($this->logger)());
        if ($this->stopRequested) {
            // A signal that arrived while the worker was being built: run() never looks at the flag.
            $this->worker->stop();
        }
        try {
            $this->worker->run(['sleep' => $once ? self::BUSY_SLEEP_MICROSECONDS : $interval * 1_000_000]);
        } finally {
            $this->worker = null;
            foreach ($subscribers as $subscriber) {
                $this->events->removeSubscriber($subscriber);
            }
            foreach ($listeners as $name => $listener) {
                $this->events->removeListener($name, $listener);
            }
        }

        return $stopWhenEmpty?->gaveUp() ?? false;
    }

    public function getSubscribedSignals(): array
    {
        return SignalRegistry::isSupported() ? [\SIGTERM, \SIGINT, \SIGQUIT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        // Remembered even before the worker exists (during the abandoned report or the drain), so the
        // command ends at its next safe point instead of starting to consume.
        $this->stopRequested = true;
        $this->worker?->stop();

        return false;
    }

    private static function idOf(Envelope $envelope): string
    {
        $stamp = $envelope->last(TransportMessageIdStamp::class);

        return $stamp instanceof TransportMessageIdStamp ? (string) $stamp->getId() : '?';
    }

    /** What went wrong, without Messenger's wrapper: the first wrapped exception's message. */
    private static function describe(\Throwable $e): string
    {
        if ($e instanceof WrappedExceptionsInterface && $e->getWrappedExceptions() !== []) {
            $e = array_values($e->getWrappedExceptions())[0];
        }
        $text = preg_replace('/\s+/', ' ', $e->getMessage()) ?? '';

        return substr($text, 0, 300);
    }
}
