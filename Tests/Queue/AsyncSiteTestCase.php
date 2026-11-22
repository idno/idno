<?php

declare(strict_types=1);

namespace Tests\Queue;

use Tests\Support\PrivateSiteTestCase;

/**
 * A site of the test's own with the asynchronous event queue, for tests of what queued events do.
 * The rig's site runs the synchronous queue, so nothing queued is ever left for a worker there.
 *
 * Everything about the site process (the free port, the private database, the upload directory, the
 * environment, the users) is Tests\Support\PrivateSiteTestCase's; this class sets
 * IDNO_EVENT_QUEUE=AsynchronousQueue for the web server and every subprocess, and adds the worker
 * (`bin/console service-event-queue --once`), the queued and failed events, the sinks and the
 * ActivityPub follower of the delivery tests.
 */
abstract class AsyncSiteTestCase extends PrivateSiteTestCase
{
    /** @var list<string> sinks read by sink() or named by newSink(), emptied again in tearDown() */
    private array $sinks = [];

    /**
     * A fresh sink name, emptied again in tearDown().
     */
    protected function newSink(string $prefix): string
    {
        $name = $prefix . '-' . bin2hex(random_bytes(4));
        $this->sinks[] = $name;

        return $name;
    }

    /**
     * The requests /_test/sink/<name> recorded since the last call, in order; the sink is emptied.
     *
     * @return list<array<string, mixed>>
     */
    protected function sink(string $name): array
    {
        $response = $this->site()->request('GET', '_test/sink/' . $name . '/log');
        $this->assertSame(200, $response->status, $response->body);
        if (!in_array($name, $this->sinks, true)) {
            $this->sinks[] = $name;
        }

        return $response->json();
    }

    /**
     * Runs `bin/console service-event-queue --once` in a subprocess with the site's environment.
     *
     * @param array<string, string|null> $environment variables to set over the site's, null to remove one
     *
     * @return array{exit: int, output: string} the exit code, and stdout then stderr
     */
    protected function runWorkerOnce(array $environment = []): array
    {
        [$exit, $stdout, $stderr] = $this->consoleWith($environment, 'service-event-queue', '--once');

        return ['exit' => $exit, 'output' => $stdout . $stderr];
    }

    /**
     * Stores an ActivityPub follower record of a local user (what a signed Follow to the inbox
     * would, which the test does not send: the inbox fetches the remote actor), with the inbox URL
     * the deliveries go to; returns the user's UUID.
     */
    protected function addFollower(string $handle, string $inbox, string $actorUri = 'https://remote.example/users/bob'): string
    {
        return $this->runInSite(
            '$user = \Idno\Entities\User::getByHandle(' . $this->literal($handle) . ');' . "\n"
            . '$follower = new \IdnoPlugins\ActivityPub\Entities\ActivityPubFollower();' . "\n"
            . '$follower->user_uuid = $user->getUUID();' . "\n"
            . '$follower->actor_uri = ' . $this->literal($actorUri) . ';' . "\n"
            . '$follower->actor_inbox = ' . $this->literal($inbox) . ';' . "\n"
            . 'if (!$follower->save(true)) { throw new \RuntimeException("follower not saved"); }' . "\n"
            . 'return $user->getUUID();'
        );
    }

    /**
     * The events waiting in the queue (the messages of the `idno` queue of `idno_messages`, whose rows
     * a worker deletes when it has handled them), oldest first.
     *
     * @return list<array{id: string, event: string, queue: string, runAsContext: ?string, data: mixed}>
     */
    protected function queuedEvents(): array
    {
        return $this->messagesOf('idno');
    }

    /**
     * Marks the queued message as taken by a worker $secondsAgo seconds ago and never finished (what a
     * worker killed in the middle of an event leaves), writing the datetime as the transport does.
     */
    protected function markTakenAgo(string $messageId, int $secondsAgo): void
    {
        $this->assertNotNull($this->connection);
        $this->connection->executeStatement(
            'update idno_messages set delivered_at = ? where id = ?',
            [(new \DateTimeImmutable('UTC'))->modify('-' . $secondsAgo . ' seconds'), (int) $messageId],
            [\Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE, \Doctrine\DBAL\Types\Types::INTEGER],
        );
    }

    /**
     * The events a worker could not handle (the messages of the `failed` queue), with the error each
     * was kept with.
     *
     * @return list<array{id: string, event: string, queue: string, runAsContext: ?string, data: mixed, error: string}>
     */
    protected function failedEvents(): array
    {
        return $this->messagesOf('failed');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messagesOf(string $queueName): array
    {
        $this->assertNotNull($this->connection);
        $events = [];
        $serializer = new \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer();
        foreach ($this->connection->fetchAllAssociative('select id, body, headers from idno_messages where queue_name = ? order by id', [$queueName]) as $row) {
            $envelope = $serializer->decode(['body' => $row['body'], 'headers' => json_decode((string) $row['headers'], true) ?? []]);
            $message = $envelope->getMessage();
            $this->assertInstanceOf(\Idno\Queue\QueuedEvent::class, $message);
            $event = ['id' => (string) $row['id'], 'event' => $message->event, 'queue' => $message->queue, 'runAsContext' => $message->runAs, 'data' => $message->data];
            if ($queueName === 'failed') {
                $details = $envelope->last(\Symfony\Component\Messenger\Stamp\ErrorDetailsStamp::class);
                $event['error'] = $details instanceof \Symfony\Component\Messenger\Stamp\ErrorDetailsStamp ? $details->getExceptionMessage() : '';
            }
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Writes a queued event record exactly as the previous queue's enqueue() did (an
     * AsynchronousQueuedEvent entity), with the event data given as the text stored (so that data
     * which does not unserialise can be stored), and returns the stored record's id. The worker moves
     * such a record into the queue before it consumes (LegacyQueueDrain).
     */
    protected function storeQueuedEvent(string $event, string $serializedData, ?string $runAsContext): string
    {
        return $this->runInSite(
            '$q = new \Idno\Entities\AsynchronousQueuedEvent();' . "\n"
            . '$q->queue = "default";' . "\n"
            . '$q->event = ' . $this->literal($event) . ';' . "\n"
            . '$q->eventData = ' . $this->literal($serializedData) . ';' . "\n"
            . '$q->runAsContext = ' . $this->literal($runAsContext) . ';' . "\n"
            . '$q->complete = false;' . "\n"
            . '$q->queuedTs = time();' . "\n"
            . 'if (!$q->save(true)) { throw new \RuntimeException("event not stored"); }' . "\n"
            . 'return $q->getID();'
        );
    }

    /**
     * Registers a user, gives them a follower whose inbox is a fresh sink, publishes a status (with
     * $publishHeaders on the post, as a reverse proxy would add them), runs the worker once and returns
     * the URLs the delivered request carried: the post's permalink (the Location of the publish),
     * the activity's `actor`, `object.id` (its UUID replaced by `<uuid>`), `object.url` and the signature's `keyId`.
     *
     * @param array<string, string> $publishHeaders
     *
     * @return array{permalink: string, actor: string, objectId: string, objectUrl: string, keyId: string}
     */
    protected function deliveredUrls(array $publishHeaders = []): array
    {
        $cookie = $this->registerUser('alice');
        $sink = $this->newSink('inbox');
        $this->addFollower('alice', $this->baseUrl() . '_test/sink/' . $sink);
        $permalink = $this->publishStatus($cookie, 'Hello queue', $publishHeaders);
        $worker = $this->runWorkerOnce();
        $this->assertSame(0, $worker['exit'], $worker['output']);
        $requests = $this->sink($sink);
        $this->assertCount(1, $requests, 'one delivery' . $this->serverLog());
        $this->assertSame(1, preg_match('/^keyId="([^"]+)",/', $requests[0]['headers']['signature'], $m));
        $activity = json_decode((string) base64_decode($requests[0]['body'], true), true, 512, JSON_THROW_ON_ERROR);

        $objectId = $activity['object']['id'];
        $this->assertSame(1, preg_match('#/view/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#', $objectId), $objectId);

        return [
            'permalink' => $permalink,
            'actor' => $activity['actor'],
            'objectId' => substr($objectId, 0, -36) . '<uuid>',
            'objectUrl' => $activity['object']['url'],
            'keyId' => $m[1],
        ];
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->sinks as $name) {
                try {
                    $this->site()->request('GET', '_test/sink/' . $name . '/log');
                } catch (\Throwable) {
                    // The server may already be gone.
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function additionalEnvironment(): array
    {
        return ['IDNO_EVENT_QUEUE' => 'AsynchronousQueue'];
    }
}
