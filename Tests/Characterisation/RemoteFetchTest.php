<?php

declare(strict_types=1);

namespace Tests\Characterisation;

use IdnoPlugins\ActivityPub\Delivery;

/**
 * The requests the site makes to URLs that a remote party supplied arrive through the HTTP client
 * that refuses private networks on every hop, and the requests to services its admin configured
 * through the site's own client. In the test environment both clients are wrapped so that every
 * request carries the header X-Idno-Test-Client (`site` or `remote`), which the sink records. The
 * fetches that UrlValidator::isSafeUrl() refuses on the rig's own address (a webmention's source, an
 * actor, a Micropub media URL) are proven in Tests/Kernel/RemoteFetchCallSitesTest.
 */
final class RemoteFetchTest extends CharacterisationTestCase
{
    use \Tests\Support\LogsIn;

    /** @var list<string> */
    private array $sinks = [];

    private string $previousErrorLog = '';
    private string $errorLog = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->errorLog = (string) tempnam(sys_get_temp_dir(), 'idno-remote-flows-log');
        $this->previousErrorLog = (string) ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            foreach ($this->sinks as $name) {
                $this->site()->request('GET', '_test/sink/' . $name . '/log');
            }
            $this->sinks = [];
            ini_set('error_log', $this->previousErrorLog);
            @unlink($this->errorLog);
        }
    }

    private function sink(string $prefix): string
    {
        $name = $prefix . '-' . bin2hex(random_bytes(4));
        $this->sinks[] = $name;

        return $name;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recorded(string $name): array
    {
        $response = $this->site()->request('GET', '_test/sink/' . $name . '/log');
        $this->assertSame(200, $response->status);

        return $response->json();
    }

    private function createdByMicropub(array $form): string
    {
        $token = $this->issueMicropubToken($this->user());
        $response = $this->site()->request('POST', 'micropub/endpoint', ['Authorization' => 'Bearer ' . $token], $form);
        $location = $response->header('Location');
        $this->assertNotNull($location, 'No Location header: ' . $response->status . ' ' . $response->body);
        $this->registerEntityAtUrl($location);
        $this->assertSame(201, $response->status);

        return $location;
    }

    public function testAnOutgoingWebmentionGoesThroughTheRemoteClient(): void
    {
        $name = $this->sink('wm');
        $target = $this->site()->baseUrl() . '_test/page/' . $name;

        $this->createdByMicropub(['h' => 'entry', 'content' => 'Reading ' . $target . ' today']);

        $requests = $this->recorded($name);
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('remote', $requests[0]['headers']['x-idno-test-client']);
    }

    public function testADeliveryToAnInboxGoesThroughTheRemoteClient(): void
    {
        $name = $this->sink('inbox');
        $inbox = $this->site()->baseUrl() . '_test/sink/' . $name;
        $activity = ['@context' => 'https://www.w3.org/ns/activitystreams', 'type' => 'Create', 'id' => $inbox . '#1'];

        $this->assertTrue(Delivery::deliver($this->user(), $activity, $inbox));

        $requests = $this->recorded($name);
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('remote', $requests[0]['headers']['x-idno-test-client']);
        $this->assertArrayHasKey('signature', $requests[0]['headers'], 'the request is still signed');
        $this->assertSame(
            json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            base64_decode($requests[0]['body'], true),
            'the body is the activity as it was encoded before'
        );
    }

    public function testRequestsToConfiguredServicesStillUseTheSiteClient(): void
    {
        $name = $this->sink('hub');
        $base = $this->site()->baseUrl();
        $db = \Idno\Core\Idno::site()->db();
        $original = $db->getAnyRecord('config');
        $this->assertIsArray($original);
        try {
            $this->assertNotFalse($db->saveRecord('config', array_replace($original, ['hub' => $base . '_test/sink/' . $name])));
            $this->createdByMicropub(['h' => 'entry', 'content' => 'A note for the hub ' . $name]);

            $requests = $this->recorded($name);
            $this->assertCount(1, $requests);
            $this->assertSame('site', $requests[0]['headers']['x-idno-test-client']);
        } finally {
            $db->saveRecord('config', $original);
        }
    }

    /**
     * Every request a sink recorded was made by the remote client, and there was at least one.
     *
     * @param list<array<string, mixed>> $requests
     */
    private function assertAllRemote(array $requests): void
    {
        $this->assertNotSame([], $requests, 'the sink received a request');
        foreach ($requests as $request) {
            $this->assertSame('remote', $request['headers']['x-idno-test-client']);
        }
    }

    private function sinkUrl(string $name): string
    {
        return $this->site()->baseUrl() . '_test/sink/' . $name;
    }

    public function testASharedUrlIsProbedAndFetchedThroughTheRemoteClient(): void
    {
        $name = $this->sink('share');
        $cookie = $this->logIn($this->user());

        $this->site()->request('GET', 'share/?share_url=' . urlencode($this->sinkUrl($name) . '?html=1'), ['Cookie' => $cookie]);

        $requests = $this->recorded($name);
        $this->assertSame(['HEAD', 'GET'], array_column($requests, 'method'), 'the probe, then the page (only an HTML answer gets that far)');
        $this->assertAllRemote($requests);
    }

    public function testTheFollowingBookmarkletFetchesThroughTheRemoteClient(): void
    {
        $name = $this->sink('bookmarklet');
        $cookie = $this->logIn($this->user());

        $this->site()->request('GET', 'account/settings/following/bookmarklet/?u=' . urlencode($this->sinkUrl($name)), ['Cookie' => $cookie]);

        $this->assertAllRemote($this->recorded($name));
    }

    public function testABookmarksTitleIsFetchedThroughTheRemoteClient(): void
    {
        $name = $this->sink('bookmark');
        $cookie = $this->logIn($this->user());

        $this->site()->request('GET', 'like/edit/?url=' . urlencode($this->sinkUrl($name)), ['Cookie' => $cookie]);

        $this->assertAllRemote($this->recorded($name));
    }

    public function testAReplyTargetsPageIsFetchedThroughTheRemoteClient(): void
    {
        $name = $this->sink('reply');

        \Idno\Core\Webmention::addSyndicatedReplyTargets($this->sinkUrl($name));

        $this->assertAllRemote($this->recorded($name));
    }

    public function testBridgyChecksAPageThroughTheRemoteClient(): void
    {
        $name = $this->sink('bridgy');
        $user = new \Idno\Entities\User();
        $user->bridgy = ['twitter' => ['user' => $this->sinkUrl($name), 'status' => 'disabled']];
        $check = (new \ReflectionClass(\IdnoPlugins\Bridgy\Pages\Check::class))->newInstanceWithoutConstructor();

        $check->checkService($user, 'twitter');

        $this->assertAllRemote($this->recorded($name));
    }

    public function testWebSubFetchesPagesThroughTheRemoteClient(): void
    {
        $name = $this->sink('websub');
        $url = $this->sinkUrl($name);
        $websub = new \Idno\Core\WebSub();

        foreach (['discoverHubs', 'findFeed', 'findSelf'] as $method) {
            (new \ReflectionMethod($websub, $method))->invoke($websub, $url);
        }

        $requests = $this->recorded($name);
        $this->assertGreaterThanOrEqual(3, count($requests), 'discovery, the feed search and the self link each fetch the page');
        $this->assertAllRemote($requests);
    }

    /**
     * The page advertises the sink as its feed, so the feed itself is fetched (and recorded) by
     * hub discovery and by the search for the feed's self link: one request each.
     */
    public function testWebSubFetchesAFeedThroughTheRemoteClient(): void
    {
        $name = $this->sink('websubfeed');
        $page = $this->site()->baseUrl() . '_test/page/' . $name . '?feed=1';
        $websub = new \Idno\Core\WebSub();

        foreach (['discoverHubs', 'findSelf'] as $method) {
            (new \ReflectionMethod($websub, $method))->invoke($websub, $page);

            $requests = $this->recorded($name);
            $this->assertCount(1, $requests, $method . ' fetched the feed once');
            $this->assertAllRemote($requests);
        }
    }
}
