<?php

declare(strict_types=1);

namespace Idno\Queue;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\RetryableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * The queue's transport as a worker needs it: reading does not end the worker when the database
 * is busy or gone for a moment.
 *
 * get() asks the transport it decorates for the next message. When that fails because the database
 * is locked or busy (a lock wait timeout, a deadlock: what SQLite reports when another process
 * committed between the transport's read and its update) or the connection was lost (the database
 * restarted, the server closed an idle connection), it closes the connection when it was lost (the
 * next query opens a new one), logs ONE line at warning level and returns no message, so the worker
 * sleeps and asks again. Any other failure is thrown as it is.
 *
 * Nothing else is touched: a failure to acknowledge or reject a message is thrown (a message that
 * could not be acknowledged must be visible, not swallowed), and sending is passed through.
 */
final class ResilientTransport implements TransportInterface, KeepaliveReceiverInterface, MessageCountAwareInterface, ListableReceiverInterface
{
    private const MAX_LOGGED_BYTES = 200;

    public function __construct(
        private readonly TransportInterface $inner,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    )
    {
    }

    public function get(): iterable
    {
        try {
            return $this->inner->get(...func_get_args());
        } catch (\Throwable $e) {
            $cause = self::cause($e);
            if ($cause === null) {
                throw $e;
            }
            if ($cause instanceof ConnectionLost) {
                $this->connection->close();
            }
            $this->logger->warning('The queue could not be read, trying again: ' . self::describe($cause));

            return [];
        }
    }

    public function ack(Envelope $envelope): void
    {
        $this->inner->ack($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->inner->reject($envelope);
    }

    public function send(Envelope $envelope): Envelope
    {
        return $this->inner->send($envelope);
    }

    public function keepalive(Envelope $envelope, ?int $seconds = null): void
    {
        if ($this->inner instanceof KeepaliveReceiverInterface) {
            $this->inner->keepalive($envelope, $seconds);
        }
    }

    public function getMessageCount(): int
    {
        return $this->inner instanceof MessageCountAwareInterface ? $this->inner->getMessageCount() : 0;
    }

    public function all(?int $limit = null): iterable
    {
        return $this->inner instanceof ListableReceiverInterface ? $this->inner->all($limit) : [];
    }

    public function find(mixed $id): ?Envelope
    {
        return $this->inner instanceof ListableReceiverInterface ? $this->inner->find($id) : null;
    }

    /**
     * The database failure that makes a read worth trying again (busy, locked, lost), found in the
     * exception or what it wraps; null for anything else.
     */
    private static function cause(\Throwable $e): ?\Throwable
    {
        for ($each = $e; $each !== null; $each = $each->getPrevious()) {
            if ($each instanceof RetryableException || $each instanceof ConnectionLost) {
                return $each;
            }
        }

        return null;
    }

    private static function describe(\Throwable $e): string
    {
        $text = $e::class . ': ' . $e->getMessage();
        $text = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text) ?? '';

        return substr($text, 0, self::MAX_LOGGED_BYTES);
    }
}
