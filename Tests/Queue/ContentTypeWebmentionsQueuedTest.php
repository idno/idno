<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * On a site with the asynchronous queue every bundled content type hands its webmentions to the queue
 * (the event `webmention/sendall`, through Webmention::queueMentions()): saving a post makes no outbound
 * request however many links its text holds, the worker sends them as the member who saved it. Each test
 * has a private site (AsyncSiteTestCase); the target is a `/_test/page/` page that advertises a sink as its
 * webmention endpoint, so what was received is read from the sink.
 */
final class ContentTypeWebmentionsQueuedTest extends AsyncSiteTestCase
{
    use PublishesEachContentType;

    /**
     * @return list<array{id: string, event: string, queue: string, runAsContext: ?string, data: mixed}>
     */
    private function webmentionEvents(): array
    {
        return array_values(array_filter($this->queuedEvents(), static fn (array $event): bool => $event['event'] === 'webmention/sendall'));
    }

    private function work(): void
    {
        $worker = $this->runWorkerOnce();
        $this->assertSame(0, $worker['exit'], $worker['output']);
    }

    /**
     * @dataProvider contentTypes
     */
    public function testAPublicPostQueuesItsWebmentionsAndTheWorkerSendsThem(string $type): void
    {
        $cookie = $this->registerUser('alice');
        $userUuid = $this->runInSite('return \Idno\Entities\User::getByHandle("alice")->getUUID();');
        $name = $this->newSink('wm');
        $target = $this->baseUrl() . '_test/page/' . $name;

        $response = $this->postNew($type, $cookie, $target);
        $this->assertSame(302, $response->status, $type . ': ' . substr($response->body, 0, 800) . $this->serverLog());
        $permalink = (string) $response->header('Location');

        $this->assertSame([], $this->sink($name), $type . ': the save made no request to the linked site');
        $events = $this->webmentionEvents();
        $this->assertCount(1, $events, $type . ': one event is queued');
        $this->assertSame($permalink, $events[0]['data']['source'], $type);
        $this->assertStringContainsString($target, $events[0]['data']['text'], $type);
        $this->assertSame(['source', 'text'], array_keys($events[0]['data']), $type);
        $this->assertSame('default', $events[0]['queue']);
        $this->assertSame($userUuid, $events[0]['runAsContext'], $type . ': run as the member who saved');

        $this->work();

        $requests = $this->sink($name);
        $this->assertCount(1, $requests, $type . ': the worker sent one webmention');
        $this->assertSame(['source' => $permalink, 'target' => $target], $requests[0]['fields'], $type);
        $this->assertSame([], $this->failedEvents(), $type);
        $this->assertSame([], $this->webmentionEvents(), $type . ': the event was handled');
    }

    /**
     * Today's rule for an edit: every type sends again, but a check-in, which sends only when it is new.
     *
     * @dataProvider contentTypes
     */
    public function testAnEditQueuesItsWebmentionsAsTheTypeAlwaysDid(string $type): void
    {
        $cookie = $this->registerUser('alice');
        $first = $this->newSink('wmfirst');
        $firstTarget = $this->baseUrl() . '_test/page/' . $first;
        $this->assertSame(302, $this->postNew($type, $cookie, $firstTarget)->status, $type);
        $this->work();
        $this->assertCount(1, $this->sink($first), $type . ': the new post was announced');

        $second = $this->newSink('wmsecond');
        $secondTarget = $this->baseUrl() . '_test/page/' . $second;
        $id = $this->newestIdOf($type);
        $edit = $this->postEdit($type, $cookie, $id, $secondTarget);
        $this->assertSame(302, $edit->status, $type . ': ' . substr($edit->body, 0, 800) . $this->serverLog());

        $events = $this->webmentionEvents();
        $this->assertCount(self::specs()[$type]['editSends'] ? 1 : 0, $events, $type . ': what an edit queues');
        $this->assertSame([], $this->sink($second), $type . ': the edit made no request');
        $this->work();
        $this->assertCount(self::specs()[$type]['editSends'] ? 1 : 0, $this->sink($second), $type . ': what the worker sent');
        $this->assertSame([], $this->failedEvents(), $type);
    }

    /**
     * A post that is not public, and a draft, send nothing and queue nothing (as before).
     *
     * @dataProvider contentTypes
     */
    public function testAPrivatePostOrADraftQueuesNothing(string $type): void
    {
        $cookie = $this->registerUser('alice');
        $name = $this->newSink('wm');
        $target = $this->baseUrl() . '_test/page/' . $name;

        $private = $this->postNew($type, $cookie, $target, ['access' => 'SITE']);
        $this->assertSame(302, $private->status, $type . ': ' . substr($private->body, 0, 500));
        $this->assertSame([], $this->webmentionEvents(), $type . ': a post for members only');

        if (self::specs()[$type]['draft']) {
            $draft = $this->postNew($type, $cookie, $target, ['publish_status' => 'draft']);
            $this->assertSame(302, $draft->status, $type . ': ' . substr($draft->body, 0, 500));
            $this->assertSame([], $this->webmentionEvents(), $type . ': a draft');
        }
        $this->work();
        $this->assertSame([], $this->sink($name), $type);
    }

    public function testAPostSavedWithNomentionQueuesNothing(): void
    {
        $cookie = $this->registerUser('alice');
        $name = $this->newSink('wm');
        $response = $this->postNew('article', $cookie, $this->baseUrl() . '_test/page/' . $name, ['nomention' => '1']);
        $this->assertSame(302, $response->status);

        $this->assertSame([], $this->webmentionEvents(), 'the request asked for no webmentions, and the worker has no request to ask');
    }

    /**
     * Deleting a public post still tells the sites it linked to, now through the queue.
     */
    public function testDeletingAPublicPostQueuesItsWebmentionsToo(): void
    {
        $cookie = $this->registerUser('alice');
        $name = $this->newSink('wm');
        $target = $this->baseUrl() . '_test/page/' . $name;
        $this->assertSame(302, $this->postNew('status', $cookie, $target)->status);
        $this->work();
        $this->assertCount(1, $this->sink($name));

        $id = $this->newestIdOf('status');
        $this->runInSite('$u = \Idno\Entities\User::getByHandle("alice"); \Idno\Core\Idno::site()->session()->logUserOn($u); return \Idno\Common\Entity::getByID(' . $this->literal($id) . ')->delete();');
        $this->assertCount(1, $this->webmentionEvents(), 'the delete queued the event');
        $this->assertSame([], $this->sink($name), 'and sent nothing itself');
        $this->work();
        $this->assertCount(1, $this->sink($name), 'the worker told the linked site');
        $this->assertSame([], $this->failedEvents());
    }

    /**
     * The types whose delete tells the sites a post linked to (Photo and Media send nothing on delete).
     *
     * @return array<string, array{string}>
     */
    public static function typesThatAnnounceADelete(): array
    {
        return array_intersect_key(self::contentTypes(), array_flip(['status', 'reply', 'article', 'bookmark', 'event', 'rsvp', 'checkin']));
    }

    private function deleteAsAlice(string $id): void
    {
        $this->assertTrue($this->runInSite('$u = \Idno\Entities\User::getByHandle("alice"); \Idno\Core\Idno::site()->session()->logUserOn($u); return \Idno\Common\Entity::getByID(' . $this->literal($id) . ')->delete();'));
    }

    /**
     * Deleting a public DRAFT announces nothing: it was never published, so the sites it links to never heard of its
     * address, and a webmention would tell them an address the site never made public. Deleting a published public post
     * still queues its retraction. (A post published and later turned back into a draft is not published when it is
     * deleted, and sends nothing either: its address already answers as a missing page to everyone but its owner.)
     *
     * @dataProvider typesThatAnnounceADelete
     */
    public function testDeletingADraftAnnouncesNothingAndDeletingAPublishedPostQueuesItsRetraction(string $type): void
    {
        $cookie = $this->registerUser('alice');
        $name = $this->newSink('wm');
        $target = $this->baseUrl() . '_test/page/' . $name;

        if (self::specs()[$type]['draft']) {
            $this->assertSame(302, $this->postNew($type, $cookie, $target, ['publish_status' => 'draft'])->status, $type);
            $this->assertSame([], $this->webmentionEvents(), $type . ': the draft queued nothing');
            $this->deleteAsAlice($this->newestIdOf($type));
            $this->assertSame([], $this->webmentionEvents(), $type . ': deleting the draft queued nothing');
        }

        $this->assertSame(302, $this->postNew($type, $cookie, $target)->status, $type);
        $this->work();
        $this->assertCount(1, $this->sink($name), $type . ': the published post was announced');
        $this->deleteAsAlice($this->newestIdOf($type));
        $events = $this->webmentionEvents();
        $this->assertCount(1, $events, $type . ': deleting the published post queued its retraction');
        $this->work();
        $this->assertCount(1, $this->sink($name), $type . ': the worker told the linked site');
        $this->assertSame([], $this->failedEvents(), $type);
    }

    /**
     * The target that answers slowly is not waited for by the save; the others are still sent by the worker.
     */
    public function testALinkToASlowSiteDoesNotHoldUpTheSave(): void
    {
        $cookie = $this->registerUser('alice');
        $name = $this->newSink('wm');
        $target = $this->baseUrl() . '_test/page/' . $name;
        $slow = $this->baseUrl() . '_test/slow?seconds=2';

        $response = $this->postNew('article', $cookie, $target, ['body' => 'First ' . $slow . ' then ' . $target]);
        $this->assertSame(302, $response->status);

        $this->assertSame([], $this->sink($name), 'no request was made while the post was saved');
        $this->assertCount(1, $this->webmentionEvents());
        $this->work();
        $this->assertCount(1, $this->sink($name), 'the worker waited for the slow link and then sent to the other');
        $this->assertSame([], $this->failedEvents());
    }

    /**
     * A link that cannot be reached does not stop the ones after it and does not park the event.
     */
    public function testALinkThatCannotBeReachedDoesNotFailTheEvent(): void
    {
        $cookie = $this->registerUser('alice');
        $name = $this->newSink('wm');
        $target = $this->baseUrl() . '_test/page/' . $name;
        $dead = 'http://127.0.0.1:9/nothing-listens-here';

        $this->assertSame(302, $this->postNew('article', $cookie, $target, ['body' => 'Before ' . $dead . ' and after ' . $target])->status);
        $this->work();

        $this->assertCount(1, $this->sink($name), 'the reachable link was sent');
        $this->assertSame([], $this->failedEvents(), 'the event is not in the failure transport');
    }
}
