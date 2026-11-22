<?php

/**
 * A drain of the previous queue's records for LegacyQueueDrainTest::testAMoveIsNotDoneTwiceWhenTwoWorkersStartTogether,
 * run as a subprocess: LegacyQueueDrain on the connection described in the environment, the transport
 * of the site's configuration (the `idno` queue of `idno_messages`). Waits until the file named by
 * "start" exists so that several of them begin together, then prints {"moved": n, "left": n} as JSON.
 *
 * Environment: IDNO_TEST_DRAIN holds the JSON {"params": <DBAL parameters>, "start": <file>}.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Idno\Queue\LegacyQueueDrain;
use Idno\Storage\AttributeTypeMap;
use Idno\Storage\Platform\SqliteConnectionSetup;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as TransportConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Tests\Storage\PrivateDatabase;

error_reporting(E_ALL & ~E_DEPRECATED);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$settings = json_decode((string) getenv('IDNO_TEST_DRAIN'), true, 512, JSON_THROW_ON_ERROR);
$configuration = new Configuration();
if (($settings['params']['driver'] ?? '') === 'pdo_sqlite') {
    $configuration->setMiddlewares([new SqliteConnectionSetup()]);
}
$connection = DriverManager::getConnection($settings['params'], $configuration);
$transport = new DoctrineTransport(
    new TransportConnection(['table_name' => 'idno_messages', 'queue_name' => 'idno', 'auto_setup' => false], $connection),
    new PhpSerializer(),
);
$bus = new MessageBus([new SendMessageMiddleware(new SendersLocator([Idno\Queue\QueuedEvent::class => ['idno']], new class ($transport) implements Psr\Container\ContainerInterface {
    public function __construct(private readonly object $transport)
    {
    }

    public function get(string $id): object
    {
        return $this->transport;
    }

    public function has(string $id): bool
    {
        return true;
    }
}))]);
$drain = new LegacyQueueDrain($connection, PrivateDatabase::recordStore($connection, null, new AttributeTypeMap()), $bus, new NullLogger());

$deadline = microtime(true) + 30;
while (!is_file($settings['start']) && microtime(true) < $deadline) {
    usleep(1000);
}
echo json_encode($drain->drain()), "\n";
