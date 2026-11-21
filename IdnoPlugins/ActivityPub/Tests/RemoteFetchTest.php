<?php

namespace IdnoPlugins\ActivityPub\Tests {

    use IdnoPlugins\ActivityPub\Delivery;
    use IdnoPlugins\ActivityPub\RemoteActor;

    /**
     * Actor documents and inboxes are named by other servers, so fetching one, or delivering to
     * one, must never reach this machine's own network, directly or through a redirect.
     */
    class RemoteFetchTest extends \Tests\OutboundHttpTestCase
    {

        private $activity = ['@context' => 'https://www.w3.org/ns/activitystreams', 'type' => 'Create', 'id' => 'http://203.0.113.20/activity'];

        public function testActorIsFetchedFromAPublicAddress()
        {
            $this->useLocalServerAsProxy();

            $actor = RemoteActor::fetch('http://203.0.113.10/actor');

            $this->assertIsArray($actor);
            $this->assertEquals('http://203.0.113.10/actor', $actor['id']);
        }

        public function testActorIsFetchedThroughARedirectToAPublicAddress()
        {
            $this->useLocalServerAsProxy();

            $actor = RemoteActor::fetch('http://203.0.113.10/redirect?to=' . urlencode('http://203.0.113.11/actor'));

            $this->assertIsArray($actor);
        }

        public function testActorIsNotFetchedThroughARedirectToThisMachine()
        {
            $this->useLocalServerAsProxy();
            $start = 'http://203.0.113.10/redirect?to=' . urlencode(static::$server->url('/actor'));

            $actor = RemoteActor::fetch($start);

            $this->assertFalse($actor);
            $this->assertSame(['GET ' . $start], static::$server->requests(), 'Only the first URL may be requested.');
        }

        public function testActivityIsDeliveredToAPublicInbox()
        {
            $this->useLocalServerAsProxy();

            $delivered = Delivery::deliver($this->user(), $this->activity, 'http://203.0.113.10/inbox');

            $this->assertTrue($delivered);
            $this->assertSame(['POST http://203.0.113.10/inbox'], static::$server->requests());
        }

        public function testActivityIsNotDeliveredToAnInboxOnThisMachine()
        {
            $this->useLocalServerAsProxy();

            $delivered = Delivery::deliver($this->user(), $this->activity, static::$server->url('/inbox'));

            $this->assertFalse($delivered);
            $this->assertSame([], static::$server->requests(), 'No request may be made.');
        }

        public function testDeliveryDoesNotFollowARedirectToThisMachine()
        {
            $this->useLocalServerAsProxy();
            $start = 'http://203.0.113.10/redirect?code=307&to=' . urlencode('http://169.254.169.254/inbox');

            $delivered = Delivery::deliver($this->user(), $this->activity, $start);

            $this->assertFalse($delivered);
            $this->assertSame(['POST ' . $start], static::$server->requests(), 'Only the first URL may be requested.');
        }
    }

}
