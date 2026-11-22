<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * What the asynchronous event queue delivers, and what it looks like to the code and people that
 * use it, pinned black-box: each test starts a site of its own with the asynchronous queue
 * (AsyncSiteTestCase), publishes over HTTP, runs the worker (`service-event-queue --once`) and reads
 * what arrived at the test site's sinks. The values are literal on purpose: whatever replaces the
 * worker must send the same requests, to the same URLs, signed the same way, as the same user.
 *
 * Each test has a site (and a database) of its own: the worker handles every pending event, so a
 * site shared between tests would hand one test's events to another. A site start takes a fraction of
 * a second on SQLite.
 */
final class QueuedDeliveryTest extends AsyncSiteTestCase
{
    /** The `@context` of every activity the ActivityPub plugin builds. */
    private const ACTIVITY_CONTEXT = [
        'https://www.w3.org/ns/activitystreams',
        'https://w3id.org/security/v1',
        [
            'gts' => 'https://gotosocial.org/ns#',
            'interactionPolicy' => ['@id' => 'gts:interactionPolicy', '@type' => '@id'],
            'canQuote' => ['@id' => 'gts:canQuote', '@type' => '@id'],
            'automaticApproval' => ['@id' => 'gts:automaticApproval', '@type' => '@id'],
            'QuoteAuthorization' => 'https://w3id.org/fep/044f#QuoteAuthorization',
            'QuoteRequest' => 'https://w3id.org/fep/044f#QuoteRequest',
            'interactingObject' => ['@id' => 'gts:interactingObject', '@type' => '@id'],
            'interactionTarget' => ['@id' => 'gts:interactionTarget', '@type' => '@id'],
        ],
    ];

    private const FIRST_LINE = "Processing pending events from queue 'default' (single run)...";

    private const SIGNED_HEADERS = '(request-target) host date digest content-type';

    private const CONTENT_TYPE = 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

    public function testPublishingEnqueuesAndTheWorkerDeliversToAFollowersInbox(): void
    {
        $cookie = $this->registerUser('alice');
        $sink = $this->newSink('inbox');
        $inbox = $this->baseUrl() . '_test/sink/' . $sink;
        $userUuid = $this->addFollower('alice', $inbox);

        $permalink = $this->publishStatus($cookie, 'Hello queue');

        // Publishing sends nothing: the events are stored, and nothing arrived.
        $this->assertSame([], $this->sink($sink), 'nothing arrives before the worker runs');
        $events = $this->queuedEvents();
        $byName = [];
        foreach ($events as $event) {
            $byName[$event['event']][] = $event;
            $this->assertSame('default', $event['queue']);
            // (The previous queue's record also held `complete`, `error`, `queuedTs` and `completedTs`:
            // a message has none of them. A message is in the queue until it is handled.)
            $this->assertSame($userUuid, $event['runAsContext'], 'queued as the user who published');
        }
        $names = array_keys($byName);
        sort($names);
        $this->assertSame(['activitypub/deliver', 'pubsubhubbub/ping', 'webmention/sendall'], $names, 'one event of each kind');
        foreach ($byName as $name => $list) {
            $this->assertCount(1, $list, $name);
        }
        $this->assertSame(['user_uuid', 'activity', 'inbox'], array_keys($byName['activitypub/deliver'][0]['data']));
        $this->assertSame($inbox, $byName['activitypub/deliver'][0]['data']['inbox']);
        $this->assertSame($userUuid, $byName['activitypub/deliver'][0]['data']['user_uuid']);
        $this->assertSame(['source' => $permalink, 'text' => 'Hello queue'], $byName['webmention/sendall'][0]['data']);

        $worker = $this->runWorkerOnce();
        $this->assertSame(0, $worker['exit'], $worker['output']);

        $requests = $this->sink($sink);
        $this->assertCount(1, $requests, 'one delivery to the follower\'s inbox');
        $request = $requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame([], $request['query']);
        $this->assertSame(self::CONTENT_TYPE, $request['content_type']);
        $this->assertSame([], $request['fields'], 'the body is JSON, not a form');
        $body = (string) base64_decode($request['body'], true);
        $this->assertStringNotContainsString('\\/', $body, 'encoded with unescaped slashes');

        $headers = $request['headers'];
        $origin = (string) parse_url($this->baseUrl(), PHP_URL_HOST) . ':' . (string) parse_url($this->baseUrl(), PHP_URL_PORT);
        $this->assertSame($origin, $headers['host']);
        $this->assertSame(self::CONTENT_TYPE, $headers['content-type']);
        $this->assertSame(self::CONTENT_TYPE, $headers['accept']);
        $this->assertSame('Idno https://idno.co', $headers['user-agent']);
        $this->assertSame((string) strlen($body), $headers['content-length']);
        $this->assertSame('SHA-256=' . base64_encode(hash('sha256', $body, true)), $headers['digest'], 'the digest matches the body');
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/', $headers['date']);
        $this->assertEqualsWithDelta(time(), (int) strtotime($headers['date']), 120, 'dated when it was sent');

        // The Signature header: the actor's key, the five signed headers, RSA-SHA256, and a signature
        // the actor's published public key verifies over exactly those headers.
        $this->assertSame(1, preg_match('/^keyId="([^"]+)",algorithm="rsa-sha256",headers="([^"]+)",signature="([A-Za-z0-9+\/=]+)"$/', $headers['signature'], $m), $headers['signature']);
        $this->assertSame($this->baseUrl() . 'actor/alice#main-key', $m[1]);
        $this->assertSame(self::SIGNED_HEADERS, $m[2]);
        $signingString = "(request-target): post /_test/sink/" . $sink . "\n"
            . 'host: ' . $headers['host'] . "\n"
            . 'date: ' . $headers['date'] . "\n"
            . 'digest: ' . $headers['digest'] . "\n"
            . 'content-type: ' . $headers['content-type'];
        $this->assertSame(1, openssl_verify($signingString, (string) base64_decode($m[3], true), $this->publicKeyOf('alice'), OPENSSL_ALGO_SHA256), 'the actor\'s public key verifies the signature');

        // The body: a Create of a Note.
        $activity = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $objectId = $activity['object']['id'];
        $this->assertMatchesRegularExpression('#^' . preg_quote($this->baseUrl(), '#') . 'view/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#', $objectId);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $activity['published']);
        $public = 'https://www.w3.org/ns/activitystreams#Public';
        $followers = $this->baseUrl() . 'actor/alice/followers';
        $this->assertSame([
            '@context' => self::ACTIVITY_CONTEXT,
            'id' => $objectId . '#create',
            'type' => 'Create',
            'actor' => $this->baseUrl() . 'actor/alice',
            'published' => $activity['published'],
            'object' => [
                'id' => $objectId,
                'type' => 'Note',
                'url' => $permalink,
                'attributedTo' => $this->baseUrl() . 'actor/alice',
                'published' => $activity['published'],
                'to' => [$public],
                'cc' => [$followers],
                'content' => '<p>Hello queue</p>',
                'interactionPolicy' => ['canQuote' => ['automaticApproval' => [$public]]],
            ],
            'to' => [$public],
            'cc' => [$followers],
        ], $activity);
        $this->assertSame($byName['activitypub/deliver'][0]['data']['activity'], $activity, 'the activity sent is the one stored when the post was published');
    }

    public function testAWebmentionIsSentByTheWorkerNotByThePublishRequest(): void
    {
        $cookie = $this->registerUser('alice');
        $name = $this->newSink('wm');
        $target = $this->baseUrl() . '_test/page/' . $name;

        $post = $this->publishStatus($cookie, 'Reading ' . $target . ' today');

        $this->assertSame([], $this->sink($name), 'the publish request sends nothing');

        $worker = $this->runWorkerOnce();
        $this->assertSame(0, $worker['exit'], $worker['output']);

        $requests = $this->sink($name);
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame(['source' => $post, 'target' => $target], $requests[0]['fields']);
        $this->assertSame('application/x-www-form-urlencoded', $requests[0]['content_type']);
        $this->assertSame('Idno https://idno.co', $requests[0]['headers']['user-agent']);
        $this->assertSame([], $this->sink($name), 'sent once');
    }

    /**
     * Run-as is pinned by an effect. The `linkpreview/fetch` listener reads the post with
     * `Entity::getByID()`, which applies the session user's read access, and saves it, which needs the
     * session user to be allowed to edit it. So on a PRIVATE post the preview is stored only when the
     * event runs as the post's owner: the event queued by the owner's publish stores it; an event for
     * another private post, queued with no user, finds nothing and stores nothing. (The unfurled page is
     * seeded, because the real fetch refuses loopback addresses.) The stored context of each event, and
     * that the dispatch logs who it ran as, are pinned too.
     */
    public function testTheEventRunsAsTheUserWhoQueuedIt(): void
    {
        $cookie = $this->registerUser('alice');
        $userUuid = $this->runInSite('return \Idno\Entities\User::getByHandle("alice")->getUUID();');
        $page = $this->baseUrl() . '_test/page/' . $this->newSink('lp');
        $this->runInSite(
            '$u = new \Idno\Entities\UnfurledUrl(); $u->source_url = ' . $this->literal($page) . '; $u->data = ["title" => "Seeded title"]; $u->setAccess("PUBLIC");'
            . ' if (!$u->save(true)) { throw new \RuntimeException("not seeded"); } return true;'
        );

        // The owner publishes a private post with a link; another private post of theirs is stored
        // with a preview event of its own that names no user.
        $this->publishStatus($cookie, 'A private note about ' . $page, [], $userUuid);
        $otherId = $this->runInSite(
            '$s = new \IdnoPlugins\Status\Status(); $s->body = "Another private note"; $s->setOwner(\Idno\Entities\User::getByHandle("alice"));'
            . ' $s->setAccess(' . $this->literal($userUuid) . '); $s->setPublishStatus("published"); if (!$s->save(true)) { throw new \RuntimeException("not saved"); } return $s->getID();'
        );
        // Queued with no user: no session in this process (the adapter's enqueue(), as the previous
        // queue's was, takes the session's user and none is logged on).
        $this->runInSite('return \Idno\Core\Idno::site()->queue()->enqueue("default", "linkpreview/fetch", ["entity_id" => ' . $this->literal($otherId) . ', "url" => ' . $this->literal($page) . '], null);');

        $contexts = [];
        $ownId = null;
        foreach ($this->queuedEvents() as $event) {
            if ($event['event'] === 'linkpreview/fetch' && $event['data']['entity_id'] !== $otherId) {
                $ownId = $event['data']['entity_id'];
            }
            $contexts[$event['event']][] = $event['runAsContext'];
        }
        $this->assertNotNull($ownId, 'the owner\'s publish queued a link preview fetch');
        $this->assertSame([$userUuid, null], $this->sortedContexts($contexts['linkpreview/fetch']), 'one queued as the owner, one with no user');

        $worker = $this->runWorkerOnce();
        $this->assertSame(0, $worker['exit'], $worker['output']);

        $previews = $this->runInSite(
            '\Idno\Core\Idno::site()->db()->setIgnoreAccess(true);'
            . ' return [\Idno\Common\Entity::getByID(' . $this->literal($ownId) . ')->link_preview, \Idno\Common\Entity::getByID(' . $this->literal($otherId) . ')->link_preview];'
        );
        $this->assertSame(
            [['url' => $page, 'title' => 'Seeded title', 'description' => '', 'image' => '', 'domain' => '127.0.0.1'], null],
            $previews,
            'the preview is stored for the private post when run as its owner, and not when run as no one'
        );

        // The previous queue's dispatch also logged "Dispatching event <id>: <event> as Alice queued
        // at <date>" for each event. The worker prints "Dispatching event <id>: <event>" and does not
        // name the user (who an event ran as is pinned by its effect above), so that line is not
        // asserted any more.

        // Every event was handled: none is left waiting or failed, and a handled event leaves no
        // record (the previous queue kept it, marked complete, until garbage collection five minutes later).
        $this->assertSame([], $this->queuedEvents());
        $this->assertSame([], $this->failedEvents());
    }

    /**
     * An event queued with the user named explicitly (as the ActivityPub plugin queues its deliveries)
     * or with none is stored as such: the context is the named user, else the session's, else none (`null`:
     * the previous queue's record held `false`).
     */
    public function testTheStoredContextIsTheNamedUserElseTheSessionsElseNone(): void
    {
        $cookie = $this->registerUser('alice');
        $userUuid = $this->addFollower('alice', $this->baseUrl() . '_test/sink/' . $this->newSink('inbox'));
        $this->publishStatus($cookie, 'Hello queue');
        $this->runInSite('return \Idno\Core\Idno::site()->queue()->enqueue("default", "idno/queue/probe", [], null);');
        $this->runInSite('return \Idno\Core\Idno::site()->queue()->enqueue("default", "idno/queue/probe-named", [], ' . $this->literal($userUuid) . ');');

        $stored = [];
        foreach ($this->queuedEvents() as $event) {
            $stored[$event['event']] = $event['runAsContext'];
        }
        ksort($stored);
        $this->assertSame([
            'activitypub/deliver' => $userUuid,
            'idno/queue/probe' => null,
            'idno/queue/probe-named' => $userUuid,
            'pubsubhubbub/ping' => $userUuid,
            'webmention/sendall' => $userUuid,
        ], $stored);
    }

    /**
     * A failing event does not stop the ones after it, and a failed event is kept, not retried.
     *
     * The previous queue had a KNOWN DEFECT here, which this test pinned until the queue moved: an event
     * whose listener threw an `\Error` (a TypeError, say) was never marked, so it stayed pending and every
     * later run of the worker dispatched it again, for ever; one that threw an `\Exception` was marked
     * complete with its error. Now both kinds are handled alike: the worker prints the error, the event is
     * kept ONCE in the failure transport with its error (`messenger:failed:show`), and no later run takes
     * it again. What must keep holding, and does, is that the other events are delivered.
     */
    public function testAFailingEventDoesNotStopTheOnesAfterIt(): void
    {
        $cookie = $this->registerUser('alice');
        $sink = $this->newSink('inbox');
        $this->addFollower('alice', $this->baseUrl() . '_test/sink/' . $sink);

        // Events that fail, queued before and after a good one. Two ways to fail, with the real
        // webmention listener and no test-only code: an event to run as a user that does not exist
        // (the handler refuses it with an exception), and event data the listener cannot read (no
        // `source`: the site's `pedantic_mode` makes the undefined key an exception; the previous
        // queue's test used data that was not an array at all, a TypeError, which the adapter, whose
        // data is an array, cannot queue: it is left where it is by the drain, see LegacyQueueDrainTest).
        // Both are queued as the adapter queues an event.
        $unknownUser = $this->runInSite('return \\Idno\\Core\\Idno::site()->queue()->enqueue("default", "idno/queue/probe", [], "http://nobody.example/view/none");');
        sleep(1);
        $this->publishStatus($cookie, 'Hello queue');
        sleep(1);
        $unreadable = $this->runInSite('return \\Idno\\Core\\Idno::site()->queue()->enqueue("default", "webmention/sendall", ["text" => "no source"], null);');

        $first = $this->runWorkerOnce();
        $this->assertSame(0, $first['exit'], 'the worker exits 0 whatever the events did: ' . $first['output']);
        $lines = $this->lines($first['output']);

        $this->assertCount(1, $this->sink($sink), 'the good event was delivered');

        // An event the handler refuses (an exception) and one whose listener throws an Error: each is
        // reported, not completed, and the worker goes on.
        foreach ([[$unknownUser, 'idno/queue/probe'], [$unreadable, 'webmention/sendall']] as [$id, $name]) {
            $this->assertContains('Dispatching event ' . $id . ': ' . $name, $lines);
            $this->assertNotContains('Event ' . $id . ' completed.', $lines, implode("\n", $lines));
            $this->assertCount(1, array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'Error dispatching event ' . $id . ': ')), 'the worker reports the failure of ' . $name);
        }
        // The good ones around them were all dispatched.
        foreach (['activitypub/deliver', 'webmention/sendall', 'pubsubhubbub/ping'] as $event) {
            $this->assertNotEmpty(array_filter($lines, static fn (string $line): bool => preg_match('/^Dispatching event \d+: ' . preg_quote($event, '/') . '$/', $line) === 1), $event);
        }

        // Nothing is left waiting; the two failed events are kept once, with their errors.
        $this->assertSame([], $this->queuedEvents());
        $failed = [];
        foreach ($this->failedEvents() as $event) {
            $failed[$event['event']] = $event['error'];
        }
        ksort($failed);
        $this->assertSame(['idno/queue/probe', 'webmention/sendall'], array_keys($failed));
        $this->assertStringContainsString('Invalid user (http://nobody.example/view/none) given for runAsContext, aborting', $failed['idno/queue/probe']);
        $this->assertNotSame('', $failed['webmention/sendall']);

        // Neither is dispatched again by a later run (the previous queue dispatched the Error one
        // again, for ever), and the good event was not delivered twice.
        $second = $this->runWorkerOnce();
        $this->assertSame(0, $second['exit']);
        $this->assertSame([self::FIRST_LINE], $this->lines($second['output']));
        $this->assertSame([], $this->sink($sink), 'the good event was not delivered twice');
        $this->assertCount(2, $this->failedEvents(), 'and the failed ones are still kept, once');
    }

    public function testTheWorkersExitCodeAndThatASecondRunHasNothingToDo(): void
    {
        $cookie = $this->registerUser('alice');
        $this->addFollower('alice', $this->baseUrl() . '_test/sink/' . $this->newSink('inbox'));

        // Nothing queued: exit 0, the one line.
        $empty = $this->runWorkerOnce();
        $this->assertSame(0, $empty['exit']);
        $this->assertSame([self::FIRST_LINE], $this->lines($empty['output']));

        $this->publishStatus($cookie, 'Hello queue');
        $ids = [];
        foreach ($this->queuedEvents() as $event) {
            $ids[$event['id']] = $event['event'];
        }
        $this->assertCount(3, $ids);

        $first = $this->runWorkerOnce();
        $this->assertSame(0, $first['exit'], $first['output']);
        $lines = $this->lines($first['output']);
        $this->assertSame(self::FIRST_LINE, $lines[0]);
        // One "Dispatching" and one "completed" line for each event, "[<date>] " in front of each.
        $this->assertSame(1 + 2 * count($ids), count($lines), implode("\n", $lines));
        foreach ($ids as $id => $name) {
            $this->assertContains('Dispatching event ' . $id . ': ' . $name, $lines);
            $this->assertContains('Event ' . $id . ' completed.', $lines);
        }
        $this->assertMatchesRegularExpression('/^\[[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}\] Dispatching event /m', $first['output']);

        $second = $this->runWorkerOnce();
        $this->assertSame(0, $second['exit']);
        $this->assertSame([self::FIRST_LINE], $this->lines($second['output']), 'nothing left to dispatch');
    }

    public function testWithTheSynchronousQueueTheWorkerRefuses(): void
    {
        // The same site, the worker started without the asynchronous queue set (as the rig's own
        // site is configured).
        $worker = $this->runWorkerOnce(['IDNO_EVENT_QUEUE' => null]);
        $this->assertSame(1, $worker['exit'], $worker['output']);
        $this->assertStringContainsString("The event queue is not set to AsynchronousQueue. Set event_queue = 'AsynchronousQueue' in config.ini.", $worker['output']);
        $this->assertStringNotContainsString('Processing pending events', $worker['output']);
    }

    public function testEnqueueReturnsTheIdOfTheStoredEvent(): void
    {
        $id = $this->runInSite('return \Idno\Core\Idno::site()->queue()->enqueue("default", "idno/queue/probe", ["a" => 1], null);');
        $this->assertIsString($id);
        // The previous queue returned a UUID, the stored entity's id; the id is now the message's, a
        // whole number in a string.
        $this->assertMatchesRegularExpression('/^[0-9]+$/', $id, 'the stored message\'s id');
        $events = $this->queuedEvents();
        $this->assertSame([$id], array_column($events, 'id'));
        $this->assertSame(['a' => 1], $events[0]['data']);

        // With the synchronous queue (the rig's own site, this process) the event runs at once and the
        // return value is an opaque 32-character hexadecimal string.
        $queue = \Idno\Core\Idno::site()->queue();
        if ($queue->isAsynchronous()) {
            $this->markTestIncomplete('This process\'s site does not use the synchronous queue.');
        }
        $returned = $queue->enqueue('default', 'idno/queue/probe', []);
        $this->assertIsString($returned);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $returned);
        $this->assertTrue($queue->isComplete($returned));
    }

    /**
     * The public key of a local user, as the site publishes it on the actor document.
     */
    private function publicKeyOf(string $handle): string
    {
        $actor = $this->site()->request('GET', 'actor/' . $handle, ['Accept' => 'application/activity+json']);
        $this->assertSame(200, $actor->status, $actor->body);
        $document = $actor->json();
        $this->assertSame($this->baseUrl() . 'actor/' . $handle . '#main-key', $document['publicKey']['id']);

        return $document['publicKey']['publicKeyPem'];
    }

    /**
     * The worker's output without the logger's lines (`Idno (): debug - ...`), and with the
     * `[<date>] ` in front of the event lines removed.
     *
     * @return list<string>
     */
    private function lines(string $output): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, 'Idno (')) {
                continue;
            }
            $lines[] = preg_replace('/^\[[^\]]+\] /', '', $line) ?? $line;
        }

        return $lines;
    }

    /**
     * @param list<mixed> $contexts
     *
     * @return list<mixed> the string contexts first, in order, then null
     */
    private function sortedContexts(array $contexts): array
    {
        usort($contexts, static fn ($a, $b): int => [$a === null, (string) $a] <=> [$b === null, (string) $b]);

        return $contexts;
    }
}
