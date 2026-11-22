<?php

declare(strict_types=1);

namespace Idno\Queue;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Keeps an event in the failure transport when an operator retries it and it fails again.
 *
 * Neither transport retries by itself (`max_retries` 0: what an event sends to other servers must not
 * be sent twice), but Messenger removes a message that fails without a retry, and the failure
 * transport has no failure transport of its own, so a retried event that failed again would be lost.
 * This subscriber puts it back, with the error of the retry, after a pause: Messenger lists and finds
 * only messages that are available, so the pause also stops `messenger:failed:retry` from finding it
 * again at once and running it again and again; it is back in `messenger:failed:show` a minute later.
 *
 * The new copy is written and the received copy's row is deleted in ONE transaction on the site's
 * connection (the transport shares it: `doctrine://default`), so a crash between the two statements
 * cannot leave two copies or none. The worker rejects the received message afterwards, which deletes
 * nothing: the Doctrine transport's reject() is `delete ... where id = ?` and returns whether a row went,
 * it does not throw when none did. If writing the new copy fails, the exception propagates (the worker
 * stops, the original row stays, abandoned and visible): nothing is lost.
 */
final class KeepFailedMessage implements EventSubscriberInterface
{
    /** The failure transport's name, as a worker's receiver is named. */
    public const RECEIVER = 'failed';

    /** How long a message put back is not listed, so that a retry in progress does not find it again. */
    public const PAUSE_MILLISECONDS = 60000;

    private const TABLE = 'idno_messages';

    public function __construct(private readonly SenderInterface $failed, private readonly Connection $connection)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // After the listener that adds the error to the envelope (200), and after Messenger's own (-100).
        return [WorkerMessageFailedEvent::class => ['onMessageFailed', -200]];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->getReceiverName() !== self::RECEIVER || $event->willRetry()) {
            return;
        }
        $received = $event->getEnvelope();
        $originalId = $received->last(TransportMessageIdStamp::class)?->getId();
        $copy = $received
            ->withoutAll(ReceivedStamp::class)
            ->withoutAll(TransportMessageIdStamp::class)
            ->with(new DelayStamp(self::PAUSE_MILLISECONDS));

        $this->connection->transactional(function () use ($copy, $originalId): void {
            $this->failed->send($copy);
            if ($originalId !== null) {
                $this->connection->executeStatement('delete from ' . self::TABLE . ' where id = ?', [$originalId]);
            }
        });
    }
}
