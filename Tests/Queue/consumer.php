<?php

/**
 * A consumer for TransportTest::testTwoConsumersNeverHandleOneMessageTwice, run as a subprocess:
 * the Doctrine transport on the connection described in the environment, with auto_setup off, and a
 * worker whose handler appends the message's id to a file under an exclusive lock. It stops when
 * the queue has been empty for a while, and prints how many messages it handled and how many reads
 * found the database busy (ResilientTransport logged a warning and returned nothing, as it does on
 * the site). A worker that dies exits non-zero: nobody restarts it.
 *
 * On SQLite the transport's reads run in deferred transactions, so with several consumers a read that
 * finds another process's commit in between fails at once ("database is locked", never waited for);
 * ResilientTransport rides that out, so no consumer may die.
 *
 * Environment: IDNO_TEST_CONSUMER holds the JSON {"params": <DBAL parameters>, "output": <file>,
 * "work_us": <microseconds of work per message>}.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Idno\Queue\QueuedEvent;
use Idno\Queue\ResilientTransport;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Psr\Log\AbstractLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as TransportConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

error_reporting(E_ALL & ~E_DEPRECATED);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$settings = json_decode((string) getenv('IDNO_TEST_CONSUMER'), true, 512, JSON_THROW_ON_ERROR);
$params = $settings['params'];
$configuration = new Configuration();
if (($params['driver'] ?? '') === 'pdo_sqlite') {
    $configuration->setMiddlewares([new SqliteConnectionSetup()]);
}
$connection = DriverManager::getConnection($params, $configuration);
$busy = new class extends AbstractLogger {
    public int $count = 0;

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        ++$this->count;
    }
};
$transport = new ResilientTransport(
    new DoctrineTransport(
        new TransportConnection(['table_name' => 'idno_messages', 'queue_name' => 'idno', 'auto_setup' => false, 'redeliver_timeout' => 315360000], $connection),
        new PhpSerializer(),
    ),
    $connection,
    $busy,
);

$handled = 0;
$handler = static function (QueuedEvent $message) use ($settings, &$handled): void {
    usleep((int) $settings['work_us']);
    file_put_contents($settings['output'], $message->data['id'] . "\n", FILE_APPEND | LOCK_EX);
    ++$handled;
};
$bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([QueuedEvent::class => [$handler]]))]);

$dispatcher = new EventDispatcher();
$idle = 0;
$dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event) use (&$idle): void {
    $idle = $event->isWorkerIdle() ? $idle + 1 : 0;
    if ($idle >= 20) {
        $event->getWorker()->stop();
    }
});

try {
    (new Worker(['idno' => $transport], $bus, $dispatcher))->run(['sleep' => 20000]);
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    echo json_encode(['handled' => $handled, 'busy' => $busy->count]), "\n";
    exit(1);
}
echo json_encode(['handled' => $handled, 'busy' => $busy->count]), "\n";
