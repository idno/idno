<?php

declare(strict_types=1);

namespace Tests\Queue;

/**
 * A queued delivery's URLs depend on the web request that makes them. The site's public URL is
 * built with `Config::getDisplayURL()`, which gives the scheme the CURRENT request used (https
 * when the web server presents the request as TLS, as a server behind a TLS terminator does, even
 * when the configured URL says http), and a command-line process never is such a request.
 *
 * Here the web server presents every request as TLS and the configured URL is http. What the
 * request that publishes builds (the activity's ids and URLs) and what the request that dispatches
 * the event builds (the signature's keyId, from the actor id) are https; the worker, which is
 * not TLS, calls the site over http, and the delivery still arrives, signed with the https keyId.
 * Whatever runs the event in another process must build the same https URLs.
 */
final class QueuedDeliveryBehindTlsTest extends AsyncSiteTestCase
{
    protected function siteEnvironment(): array
    {
        return ['IDNO_TEST_SERVER_HTTPS' => '1'];
    }

    public function testTheDeliveredUrlsAreThoseOfAWebRequestPresentedAsTls(): void
    {
        $cookie = $this->registerUser('alice');
        $sink = $this->newSink('inbox');
        $http = $this->baseUrl();
        $https = 'https://' . substr($http, strlen('http://'));
        $this->addFollower('alice', $http . '_test/sink/' . $sink);

        $permalink = $this->publishStatus($cookie, 'Hello queue');
        $this->assertSame($https . date('Y') . '/hello-queue', $permalink, 'the publishing request builds https URLs');

        $worker = $this->runWorkerOnce();
        $this->assertSame(0, $worker['exit'], $worker['output']);

        $requests = $this->sink($sink);
        $this->assertCount(1, $requests);
        $this->assertSame(1, preg_match('/^keyId="([^"]+)",/', $requests[0]['headers']['signature'], $m));
        $this->assertSame($https . 'actor/alice#main-key', $m[1], 'the dispatching request builds the keyId');
        $activity = json_decode((string) base64_decode($requests[0]['body'], true), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($https . 'actor/alice', $activity['actor']);
        $this->assertSame($permalink, $activity['object']['url']);
        $this->assertSame($https . 'actor/alice/followers', $activity['cc'][0]);
        $this->assertSame($http . '_test/sink/' . $sink, $this->storedInbox(), 'the inbox is as stored: the follower\'s own URL');
        $this->assertStringContainsString('Dispatching event', $worker['output']);
    }

    private function storedInbox(): string
    {
        $events = $this->queuedEvents();
        foreach ($events as $event) {
            if ($event['event'] === 'activitypub/deliver') {
                return $event['data']['inbox'];
            }
        }
        $this->fail('no delivery event');
    }
}
