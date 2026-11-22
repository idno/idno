<?php

declare(strict_types=1);

use Idno\Queue\AbandonedMessages;
use Idno\Queue\QueuedEvent;
use Idno\Queue\WorkerHygiene;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // The queue of events. Both transports are on the site's own DBAL connection (DoctrineBundle's
    // `default`, the one the record store uses: one database, no second connection) and in the one
    // table `idno_messages`, which the schema migrations create. auto_setup is OFF: the site creates
    // schema only through migrations, so the transport never issues DDL (and the one console command
    // that would, messenger:setup-transports, is removed: Idno\Kernel\MessengerTrimPass).
    //
    // redeliver_timeout is ten years: the Doctrine transport gives a message that was taken and never
    // acknowledged to the next worker after this long (default an hour), so a worker killed in the
    // middle of an event would make it run again. Nothing is ever redelivered by itself; such a
    // message is "abandoned" and an operator may requeue it (Idno\Queue\AbandonedMessages).
    //
    // A message is not retried (max_retries 0): the events a site queues send requests to other
    // servers, and running one twice would deliver it twice. A message whose handler fails goes to
    // the `failed` transport once, with its error, and stays there until someone looks at it.
    //
    // The default serializer is PHP's, kept on purpose: the data of an event may hold entity
    // objects, which the queue has always serialised with serialize().
    $container->extension('framework', [
        'messenger' => [
            'failure_transport' => 'failed',
            'transports' => [
                'idno' => [
                    'dsn' => 'doctrine://default',
                    'options' => ['table_name' => 'idno_messages', 'queue_name' => 'idno', 'auto_setup' => false, 'redeliver_timeout' => AbandonedMessages::REDELIVER_TIMEOUT_SECONDS],
                    'retry_strategy' => ['max_retries' => 0],
                ],
                'failed' => [
                    'dsn' => 'doctrine://default',
                    'options' => ['table_name' => 'idno_messages', 'queue_name' => 'failed', 'auto_setup' => false, 'redeliver_timeout' => AbandonedMessages::REDELIVER_TIMEOUT_SECONDS],
                ],
            ],
            'routing' => [
                QueuedEvent::class => 'idno',
            ],
            'buses' => [
                'messenger.bus.default' => [
                    'middleware' => [WorkerHygiene::class],
                ],
            ],
        ],
    ]);
};
