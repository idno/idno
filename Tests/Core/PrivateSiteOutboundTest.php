<?php

namespace Tests\Core {

    use Idno\Core\Idno;
    use Idno\Core\PubSubHubbub;
    use Idno\Core\Webmention;

    /**
     * A private site (the "walled_garden" setting) tells no other server that something was
     * posted on it: no webmention is sent, for a link or for a reply, and no hub is pinged.
     */
    class PrivateSiteOutboundTest extends \Tests\OutboundHttpTestCase
    {

        private $was = [];

        protected function setUp(): void
        {
            parent::setUp();
            foreach (['walled_garden', 'hub'] as $name) {
                $this->was[$name] = Idno::site()->config()->$name ?? null;
            }
            $this->forgetMentionClient();
            $this->useLocalServerAsProxy();
        }

        protected function tearDown(): void
        {
            foreach ($this->was as $name => $value) {
                Idno::site()->config()->$name = $value;
            }
            $this->forgetMentionClient();
            parent::tearDown();
        }

        /**
         * The webmention client is made once, with the proxy setting of that moment
         */
        private function forgetMentionClient()
        {
            $client = new \ReflectionProperty(Webmention::class, 'mentionClient');
            $client->setAccessible(true);
            $client->setValue(null, false);
        }

        public function testWebmentionsAreSentByAPublicSiteOnly()
        {
            $source = Idno::site()->config()->getDisplayURL() . '2026/a-post';
            $target = 'http://203.0.113.10/advertise?endpoint=' . rawurlencode('http://203.0.113.10/endpoint');

            Idno::site()->config()->walled_garden = true;

            $this->assertFalse(Webmention::sendWebmentionPayload($source, $target));
            $this->assertFalse(Webmention::pingMentions($source, '<a href="' . htmlspecialchars($target) . '">a link</a>'));
            $this->assertSame([], static::$server->requests(), 'A private site asks nothing of the pages its posts link to or reply to.');

            Idno::site()->config()->walled_garden = false;

            Webmention::sendWebmentionPayload($source, $target);
            $this->assertContains('POST http://203.0.113.10/endpoint', static::$server->requests(), 'Control: a public site sends the webmention.');
        }

        public function testHubIsPingedByAPublicSiteOnly()
        {
            Idno::site()->config()->hub = 'http://203.0.113.10/hub';
            $old = $this->swapUser($this->user());
            $post = new \IdnoPlugins\Status\Status();
            $post->body = 'A post with a #privatetag';
            $post->setAccess('PUBLIC');

            Idno::site()->config()->walled_garden = true;

            $this->assertFalse(PubSubHubbub::publish($post));
            $this->assertSame([], static::$server->requests(), 'A private site does not tell a hub that something was posted.');

            Idno::site()->config()->walled_garden = false;

            PubSubHubbub::publish($post);
            $this->swapUser($old);
            $this->assertSame(['POST http://203.0.113.10/hub'], static::$server->requests(), 'Control: a public site pings its hub.');
        }
    }

}
