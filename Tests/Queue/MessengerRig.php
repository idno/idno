<?php

declare(strict_types=1);

namespace Tests\Queue;

use Doctrine\DBAL\Connection;
use Idno\Queue\AbandonedMessages;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as TransportConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Builds Messenger's pieces by hand on a connection, as config/packages/messenger.php configures
 * them for the site: the Doctrine transport on `idno_messages` with auto_setup off and PHP's
 * serializer, a bus whose handler is a closure.
 */
final class MessengerRig
{
    public const TABLE = 'idno_messages';

    public static function transport(Connection $connection, string $queue = 'idno'): DoctrineTransport
    {
        return new DoctrineTransport(
            new TransportConnection(['table_name' => self::TABLE, 'queue_name' => $queue, 'auto_setup' => false, 'redeliver_timeout' => AbandonedMessages::REDELIVER_TIMEOUT_SECONDS], $connection),
            new PhpSerializer(),
        );
    }

    /**
     * @param array<class-string, list<callable>> $handlers
     * @param list<MiddlewareInterface>           $middleware run before the handler
     */
    public static function bus(array $handlers, array $middleware = []): MessageBus
    {
        return new MessageBus([...$middleware, new HandleMessageMiddleware(new HandlersLocator($handlers))]);
    }
}
