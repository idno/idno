<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * What a webmention to a post of an asynchronous site sets in motion: nothing for the post's followers. A like, a reply or a webmention is no change of the post, so no
 * `Update` of it is queued or delivered (the activity does not carry them); an edit of the post itself still is; a webmention that changes nothing that is stored (the same
 * one sent again, a withdrawal of one never stored) saves nothing and queues nothing. The source is a real page, fetched from a stand-in address
 * (`Tests\Fixtures\RemoteDocumentController`).
 */
final class WebmentionQueuedTest extends AsyncSiteTestCase
{
    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_REMOTE_STAND_IN' => '93.184.216.34:80'];
    }

    /** @return array{string, string, string} the post's address, the sink the follower's inbox is, and the post's id */
    private function postWithAFollower(): array
    {
        $cookie = $this->registerUser('alice');
        $sink = $this->newSink('inbox');
        $this->addFollower('alice', $this->baseUrl() . '_test/sink/' . $sink);
        $permalink = $this->publishStatus($cookie, 'A post that is mentioned');
        $this->assertSame(0, $this->runWorkerOnce()['exit']);
        $this->sink($sink);
        $this->assertSame([], $this->queuedEvents());
        $id = $this->runInSite('return \IdnoPlugins\Status\Status::getOne(["body" => "A post that is mentioned"])->getID();');

        return [$permalink, $sink, (string) $id];
    }

    private function mention(string $name, string $target, string $words = 'A reply.', int $status = 200): int
    {
        $html = '<!doctype html><html><body><div class="h-entry"><a class="u-url" href="http://93.184.216.34/' . $name . '">permalink</a><div class="p-author h-card"><a class="p-name u-url" href="https://remote.example/">Remote</a></div>'
            . '<a class="u-in-reply-to" href="' . $target . '">a post</a><div class="e-content">' . $words . '</div></div></body></html>';
        $this->assertSame(204, $this->site()->request('PUT', '_test/remote/' . $name . '?status=' . $status, ['Content-Type' => 'text/html'], $html)->status);

        return $this->site()->request('POST', 'webmention/', [], ['source' => 'http://93.184.216.34/' . $name, 'target' => $target])->status;
    }

    /** @return list<string> the names of the queued events */
    private function queuedNames(): array
    {
        return array_map(static fn (array $event): string => $event['event'], $this->queuedEvents());
    }

    public function testAnAcceptedMentionSendsNoUpdateToFollowers(): void
    {
        [$permalink, $sink] = $this->postWithAFollower();

        $this->assertSame(202, $this->mention('queued-reply', $permalink));

        $this->assertNotContains('activitypub/deliver', $this->queuedNames(), 'no Update of the post is queued for an annotation');
        $this->assertSame(0, $this->runWorkerOnce()['exit']);
        $this->assertSame([], $this->sink($sink), 'and nothing arrives at the follower\'s inbox');
        $shown = $this->site()->request('GET', substr($permalink, strlen($this->baseUrl())));
        $this->assertSame(200, $shown->status);
        $this->assertStringContainsString('remote.example', $shown->body, 'the post shows the mention');
    }

    public function testAnEditOfThePostItselfStillSendsAnUpdate(): void
    {
        [$permalink, $sink, $id] = $this->postWithAFollower();
        $this->assertSame(202, $this->mention('queued-reply', $permalink));

        $this->runInSite('$post = \Idno\Common\Entity::getByID(' . $this->literal($id) . '); $post->body = "The post was edited"; $post->save(true); return true;');

        $this->assertContains('activitypub/deliver', $this->queuedNames());
        $this->assertSame(0, $this->runWorkerOnce()['exit']);
        $requests = $this->sink($sink);
        $this->assertCount(1, $requests);
        $this->assertStringContainsString('"type":"Update"', (string) base64_decode($requests[0]['body'], true));
    }

    public function testAMentionThatChangesNothingStoredQueuesNothing(): void
    {
        [$permalink] = $this->postWithAFollower();
        $this->assertSame(202, $this->mention('queued-reply', $permalink));
        $this->runWorkerOnce();

        // the same page again, and the withdrawal of one that was never stored
        $this->assertSame(202, $this->mention('queued-reply', $permalink));
        $this->assertSame(202, $this->mention('never-stored', $permalink, '', 410));

        $this->assertSame([], $this->queuedEvents());
    }
}
