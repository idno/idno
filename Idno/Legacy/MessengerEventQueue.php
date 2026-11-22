<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\EventQueue;
use Idno\Queue\QueuedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * `Idno::site()->queue()`: the legacy EventQueue API over Symfony Messenger.
 *
 * When the site's `event_queue` setting is `AsynchronousQueue` (or `Idno\Core\AsynchronousQueue`: the
 * class of that name is gone, but the value stays the way a site turns the queue on) enqueue() sends
 * a QueuedEvent on the bus, which routes it to the `idno` transport; the worker (`service-event-queue`)
 * triggers the event later, as the user it was queued for. In any other
 * case the event runs at once, in the caller's request, as the synchronous queue always did, and its
 * result is kept for the request.
 *
 * It lives in Idno\Legacy because it IS the legacy API: it is built by LegacyServiceFactory from
 * closures that read the legacy configuration, session, event dispatcher and logger when they are
 * needed (the queue is built before the session and the logger exist).
 */
final class MessengerEventQueue extends EventQueue
{
    /** The setting's value for the asynchronous queue, as componentFactory() resolved it. */
    private const ASYNCHRONOUS = '/^\\\\?(?:Idno\\\\Core\\\\)?AsynchronousQueue$/i';

    /** The values that mean "run at once": no setting, and the name of the class that did. */
    private const SYNCHRONOUS = '/^\\\\?(?:Idno\\\\Core\\\\)?SynchronousQueue$/i';

    /** @var array<string, mixed> results of events run at once, by id */
    private array $results = [];

    private bool $warned = false;

    /**
     * @param \Closure(): MessageBusInterface         $bus        the message bus, fetched when the first event is sent (building it needs the transport)
     * @param \Closure(): ?string                     $setting    the `event_queue` setting, read at each call
     * @param \Closure(): ?string                     $currentUser the uuid of the logged-in user, null when nobody is
     * @param \Closure(string, array<mixed>): mixed   $trigger    triggers an event as any code does
     */
    public function __construct(
        private readonly \Closure $bus,
        private readonly \Closure $setting,
        private readonly \Closure $currentUser,
        private readonly \Closure $trigger,
        private readonly LoggerInterface $logger,
    )
    {
        parent::__construct();
    }

    /**
     * Whether events are queued for a worker (true) or run at once (false). A site whose `event_queue`
     * names any other class gets false and one warning in the log: the classes a plugin could supply
     * for it are no longer used.
     */
    public function isAsynchronous(): bool
    {
        $setting = ($this->setting)();
        if (!is_string($setting) || trim($setting) === '') {
            return false;
        }
        if (preg_match(self::ASYNCHRONOUS, $setting) === 1) {
            return true;
        }
        if (preg_match(self::SYNCHRONOUS, $setting) !== 1 && !$this->warned) {
            $this->warned = true;
            $this->logger->warning('event_queue is set to ' . self::printable($setting) . ', which this version of Idno does not use: events run at once. Set event_queue = \'AsynchronousQueue\' for a queue run by a worker.');
        }

        return false;
    }

    /**
     * @param  string|null  $queueName the legacy queue name (`default` when empty), kept in the message
     * @param  string       $eventName
     * @param  array<mixed> $eventData
     * @param  string|null  $runAsUser the uuid of the user the event runs as; the logged-in user's when empty
     * @return string|false an id (the transport's id of the message, or for an event run at once an opaque string), false when the message could not be sent
     */
    public function enqueue($queueName, $eventName, array $eventData, $runAsUser = null)
    {
        if (!$this->isAsynchronous()) {
            return $this->runAtOnce((string) $eventName, $eventData);
        }

        $queueName = empty($queueName) ? 'default' : (string) $queueName;
        $user = $runAsUser ?: ($this->currentUser)();
        $message = new QueuedEvent($queueName, (string) $eventName, $eventData, $user ? (string) $user : null);
        try {
            $envelope = ($this->bus)()->dispatch($message);
        } catch (\Throwable $e) {
            // Never the event's data, and the exception's class only: its message may quote a statement.
            $this->logger->error('Failed to enqueue asynchronous event ' . self::printable((string) $eventName) . ' on queue ' . self::printable($queueName) . ': ' . $e::class);

            return false;
        }
        $this->logger->debug('Enqueued asynchronous event ' . self::printable((string) $eventName) . ' on queue ' . self::printable($queueName));
        $stamp = $envelope->last(TransportMessageIdStamp::class);

        return $stamp instanceof TransportMessageIdStamp ? (string) $stamp->getId() : 'queued';
    }

    /**
     * True for an event that ran at once (it is complete as it is enqueued); false for any other id,
     * an asynchronous event included: nothing reports on those, they are in the queue or gone.
     */
    public function isComplete($jobId)
    {
        return isset($this->results[$jobId]);
    }

    /**
     * What the listeners of an event that ran at once returned (false when one threw); null for any
     * other id, an asynchronous event included.
     */
    public function getResult($jobId)
    {
        return $this->results[$jobId] ?? null;
    }

    /**
     * @param array<mixed> $eventData
     */
    private function runAtOnce(string $eventName, array $eventData): string
    {
        $id = md5(microtime(true) . mt_rand() . $eventName);
        try {
            $this->results[$id] = ($this->trigger)($eventName, $eventData);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $this->results[$id] = false;
        }

        return $id;
    }

    /** A value of a configuration or an event name, safe for one log line. */
    private static function printable(string $value): string
    {
        return substr(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $value) ?? '', 0, 200);
    }
}
