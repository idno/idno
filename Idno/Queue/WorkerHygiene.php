<?php

declare(strict_types=1);

namespace Idno\Queue;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * What a worker that runs for days needs between messages, for messages it RECEIVED (a message
 * dispatched by a web request is not touched):
 *
 *  - before a received message is handled, and again after it (the handler may have run longer
 *    than the server keeps an idle connection, and the transport acknowledges the message on the
 *    same connection afterwards), the site's database connection is checked, and when it is lost it
 *    is closed, so that the next query opens a new one;
 *  - after it, also when it failed, nobody is left logged on, so no event runs as the user of the
 *    one before.
 *
 * It is also a subscriber of the worker's running event (after each message and whenever the
 * worker is idle), which checks the connection too, so that the worker does not read its next
 * message on a connection that was lost while it waited.
 */
final class WorkerHygiene implements MiddlewareInterface, EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UserContext $users,
        private readonly LoggerInterface $logger,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerRunningEvent::class => 'onWorkerRunning'];
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(ReceivedStamp::class) === null) {
            return $stack->next()->handle($envelope, $stack);
        }

        $this->replaceLostConnection();
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            // Each step on its own: a failure of one is logged, never thrown, so it neither masks
            // the handler's own exception nor skips the other step.
            try {
                $this->users->ensureNobodyLoggedOn();
            } catch (\Throwable $e) {
                $this->logger->error('A user could not be logged off after a queued message: ' . self::describe($e));
            }
            $this->replaceLostConnection();
        }
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        $this->replaceLostConnection();
    }

    /**
     * Asks the database for one row and, when that fails, closes the connection: DBAL opens a new
     * one for the next query. ANY failure of this query counts as "lost" (what else could it be: it
     * asks for a constant), and a failure to open the new connection surfaces from the next query,
     * not from here. It never throws.
     */
    private function replaceLostConnection(): void
    {
        try {
            $this->connection->executeQuery('SELECT 1')->free();
        } catch (\Throwable) {
            $this->connection->close();
        }
    }

    private static function describe(\Throwable $e): string
    {
        return substr(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $e::class . ': ' . $e->getMessage()) ?? '', 0, 200);
    }
}
