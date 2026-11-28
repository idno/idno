<?php

declare(strict_types=1);

namespace IdnoPlugins\ActivityPub\Tests;

use Tests\Queue\AsyncSiteTestCase;

/**
 * A private site (`walled_garden`) does not federate: a member's followers on other servers are sent nothing, and a stranger or another
 * server finds no actor, no outbox (with the text of the member's posts), no inbox that takes a Follow, no NodeInfo and no WebFinger answer.
 * The same site made public queues the delivery, so the first test cannot pass because the follower or the queue was never set up.
 * A site of the test's own with the asynchronous queue.
 */
final class PrivateSiteTest extends AsyncSiteTestCase
{
    protected function siteEnvironment(): array
    {
        // (read when the site starts, before the test body runs: the control test names itself)
        return str_contains($this->getName(false), 'PublicSite') ? [] : ['IDNO_WALLED_GARDEN' => '1'];
    }

    /** @return list<string> the names of the queued events, sorted */
    private function queuedNames(): array
    {
        $names = array_column($this->queuedEvents(), 'event');
        sort($names);

        return $names;
    }

    /** A member's follower on another server is sent nothing when a private site's member posts, and nothing queued earlier reaches the follower's inbox. */
    public function testAPrivateSiteDeliversToNoFollowerAndNamesNoActor(): void
    {
        $cookie = $this->registerUser('alice');
        $sink = $this->newSink('inbox');
        $this->addFollower('alice', $this->baseUrl() . '_test/sink/' . $sink);

        $this->publishStatus($cookie, 'A post of a private site');

        $this->assertSame([], $this->queuedNames(), 'no delivery, hub ping or webmention was queued');
        $this->assertSame(0, $this->runWorkerOnce()['exit']);
        $this->assertSame([], $this->sink($sink), 'the follower heard nothing');

        // A delivery that was queued while the site was public is refused when it runs
        $delivered = $this->runInSite(
            '$user = \Idno\Entities\User::getByHandle("alice");' . "\n"
            . 'return \IdnoPlugins\ActivityPub\Delivery::deliver($user, ["type" => "Create"], ' . $this->literal($this->baseUrl() . '_test/sink/' . $sink) . ');'
        );
        $this->assertFalse($delivered);
        $this->assertSame([], $this->sink($sink), 'a delivery queued earlier is not sent either');

        $host = (string) parse_url($this->baseUrl(), PHP_URL_HOST);
        foreach (['actor/alice', 'actor/alice/outbox', 'actor/alice/followers', 'actor/alice/following', 'nodeinfo/2.0', '.well-known/nodeinfo', '.well-known/webfinger?resource=acct:alice@' . $host] as $path) {
            $response = $this->site()->request('GET', $path, ['Accept' => 'application/activity+json']);
            $this->assertSame(404, $response->status, $path);
            $this->assertStringNotContainsString('alice', strtolower($response->body), $path . ' names the member');
        }
        foreach (['actor/alice/inbox', 'inbox'] as $path) {
            $follow = json_encode(['type' => 'Follow', 'actor' => 'https://remote.example/users/bob', 'object' => $this->baseUrl() . 'actor/alice']);
            $response = $this->site()->request('POST', $path, ['Content-Type' => 'application/activity+json'], $follow);
            $this->assertSame(404, $response->status, $path . ' takes no Follow');
        }
    }

    /** The member's followers are told about the post on a public site, which is what the private site above must not do. */
    public function testAPublicSiteQueuesTheDeliveryToAFollower(): void
    {
        $cookie = $this->registerUser('alice');
        $sink = $this->newSink('inbox');
        $this->addFollower('alice', $this->baseUrl() . '_test/sink/' . $sink);

        $this->publishStatus($cookie, 'A post of a public site');

        $this->assertContains('activitypub/deliver', $this->queuedNames());
        $this->assertSame(200, $this->site()->request('GET', 'actor/alice', ['Accept' => 'application/activity+json'])->status);
    }
}
