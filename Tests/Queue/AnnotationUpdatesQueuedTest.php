<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * A like, a reply or their removal made on the site is no change of the post, by any path: no `Update` of it is queued for the followers. The paths are the real ones,
 * on an asynchronous site with a follower's sink: a member's like, reply and unlike from the post page, an annotation made through Micropub, and an annotation deleted
 * by its author and by the post's owner. An edit of the post itself still sends exactly one.
 */
final class AnnotationUpdatesQueuedTest extends AsyncSiteTestCase
{
    private string $owner = '';

    private string $member = '';

    private string $postUrl = '';

    private string $postUuid = '';

    private string $sink = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->registerUser('alice');
        $this->member = $this->registerUser('bob');
        $this->sink = $this->newSink('inbox');
        $this->addFollower('alice', $this->baseUrl() . '_test/sink/' . $this->sink);
        $this->postUrl = $this->publishStatus($this->owner, 'A post that people react to');
        $this->postUuid = (string) $this->runInSite('return \IdnoPlugins\Status\Status::getOne(["body" => "A post that people react to"])->getUUID();');
        $this->assertSame(0, $this->runWorkerOnce()['exit']);
        $this->sink($this->sink);
        $this->assertSame([], $this->queuedEvents());
    }

    private function token(string $cookie): string
    {
        $answer = $this->site()->request('GET', 'service/security/csrftoken/', ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest']);
        $this->assertSame(200, $answer->status);

        return (string) json_decode($answer->body, true)['token'];
    }

    private function path(): string
    {
        return substr($this->postUrl, strlen($this->baseUrl()));
    }

    /** @return list<string> */
    private function queuedNames(): array
    {
        return array_map(static fn (array $event): string => $event['event'], $this->queuedEvents());
    }

    private function assertNoUpdate(string $what): void
    {
        $this->assertNotContains('activitypub/deliver', $this->queuedNames(), $what . ': no Update is queued');
        $this->assertSame(0, $this->runWorkerOnce()['exit']);
        $this->assertSame([], $this->sink($this->sink), $what . ': nothing arrives at the follower\'s inbox');
    }

    /** @return array<string, array<string, mixed>> the post's stored annotations of a kind, by key */
    private function stored(string $kind): array
    {
        $annotations = $this->runInSite('return \IdnoPlugins\Status\Status::getByUUID(' . $this->literal($this->postUuid) . ')->annotations;');

        return is_array($annotations) ? ($annotations[$kind] ?? []) : [];
    }

    private function annotate(string $type, string $body = '', ?string $cookie = null): int
    {
        $cookie ??= $this->member;

        return $this->site()->request('POST', 'annotation/post/', ['Cookie' => $cookie], ['__bTk' => $this->token($cookie), 'object' => $this->postUuid, 'type' => $type, 'body' => $body])->status;
    }

    public function testAMembersLikeAndUnlikeSendNoUpdate(): void
    {
        $this->assertSame(302, $this->annotate('like'));
        $this->assertCount(1, $this->stored('like'));
        $this->assertNoUpdate('a like');

        $this->assertSame(302, $this->annotate('like'));
        $this->assertCount(0, $this->stored('like'), 'the second like is an unlike');
        $this->assertNoUpdate('an unlike');
    }

    public function testAMembersReplySendsNoUpdate(): void
    {
        $this->assertSame(302, $this->annotate('reply', 'A member replies.'));

        $this->assertCount(1, $this->stored('reply'));
        $this->assertNoUpdate('a reply');
    }

    public function testTheOwnersOwnLikeReplyAndUnlikeOnHerPostSendNoUpdateEither(): void
    {
        // (the owner may edit the post, so the page's save is not refused: what it saves is what federates)
        $this->assertSame(302, $this->annotate('reply', 'The owner replies.', $this->owner));
        $this->assertNoUpdate('the owner\'s reply');
        $this->assertSame(302, $this->annotate('like', '', $this->owner));
        $this->assertNoUpdate('the owner\'s like');
        $this->assertSame(302, $this->annotate('like', '', $this->owner));
        $this->assertCount(0, $this->stored('like'));
        $this->assertNoUpdate('the owner\'s unlike');
    }

    public function testAnAnnotationMadeThroughMicropubSendsNoUpdate(): void
    {
        $token = bin2hex(random_bytes(16));
        $this->runInSite('$user = \Idno\Entities\User::getByHandle("alice"); $user->indieauth_tokens = [' . $this->literal($token) . ' => ["me" => "http://example.com/", "redirect_uri" => "http://client.example.com/cb", "scope" => "create", "client_id" => "http://client.example.com/", "issued_at" => time(), "nonce" => 1234567]]; $user->save(true); return true;');

        $answer = $this->site()->request('POST', 'micropub/endpoint', ['Authorization' => 'Bearer ' . $token], [
            'h' => 'annotation', 'url' => $this->postUrl, 'type' => 'reply', 'content' => 'Through Micropub.',
            'username' => 'Mia', 'userurl' => 'https://example.org/mia', 'userphoto' => 'https://example.org/mia.png',
        ]);

        $this->assertSame(204, $answer->status, $answer->body);
        $this->assertCount(1, $this->stored('reply'));
        $this->assertNoUpdate('a Micropub annotation');
    }

    public function testAnAnnotationDeletedByItsAuthorOrByThePostsOwnerSendsNoUpdate(): void
    {
        foreach ([['member', 'by its author'], ['owner', 'by the post\'s owner']] as [$who, $what]) {
            $this->assertSame(302, $this->annotate('reply', 'To be deleted ' . $what));
            $this->assertNoUpdate('a reply');
            $key = (string) array_key_last($this->stored('reply'));
            $cookie = $who === 'member' ? $this->member : $this->owner;

            $answer = $this->site()->request('POST', $this->path() . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode($key), ['Cookie' => $cookie], ['__bTk' => $this->token($cookie)]);

            $this->assertSame(302, $answer->status);
            $this->assertNotContains($key, array_keys($this->stored('reply')), 'the annotation is deleted ' . $what);
            $this->assertNoUpdate('a delete ' . $what);
        }
    }

    public function testAnEditOfThePostItselfStillSendsExactlyOneUpdate(): void
    {
        $this->assertSame(302, $this->annotate('reply', 'A member replies.'));
        $this->runWorkerOnce();
        $this->sink($this->sink);

        $this->runInSite('$post = \IdnoPlugins\Status\Status::getByUUID(' . $this->literal($this->postUuid) . '); $post->body = "The post was edited"; $post->save(true); return true;');

        $this->assertSame(1, count(array_keys($this->queuedNames(), 'activitypub/deliver', true)));
        $this->assertSame(0, $this->runWorkerOnce()['exit']);
        $requests = $this->sink($this->sink);
        $this->assertCount(1, $requests);
        $this->assertStringContainsString('"type":"Update"', (string) base64_decode($requests[0]['body'], true));
    }
}
