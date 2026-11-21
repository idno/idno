<?php

namespace Tests\Core {

    use Idno\Core\Webmention;

    /**
     * Sending webmentions: the page that is mentioned, and the endpoint that page names, are
     * both chosen by someone else, so neither may be fetched from this machine's own network.
     */
    class MentionClientTest extends \Tests\OutboundHttpTestCase
    {

        public function testWebmentionIsSentToAPublicEndpoint()
        {
            $this->useLocalServerAsProxy();
            $target = 'http://203.0.113.10/advertise?endpoint=' . urlencode('http://203.0.113.11/webmention');

            Webmention::sendWebmentionPayload('http://203.0.113.20/source', $target);

            $this->assertContains('POST http://203.0.113.11/webmention', static::$server->requests());
        }

        public function testWebmentionIsNotSentToAnEndpointOnThisMachine()
        {
            $this->useLocalServerAsProxy();
            $endpoint = static::$server->url('/webmention');
            $target = 'http://203.0.113.10/advertise?hidden&endpoint=' . urlencode($endpoint);

            Webmention::sendWebmentionPayload('http://203.0.113.20/source', $target);

            $this->assertNotContains('POST ' . $endpoint, static::$server->requests());
        }

        public function testWebmentionEndpointIsNotLookedForOnThisMachine()
        {
            $this->useLocalServerAsProxy();
            $target = static::$server->url('/advertise?endpoint=' . urlencode('http://203.0.113.11/webmention'));

            Webmention::sendWebmentionPayload('http://203.0.113.20/source', $target);

            $this->assertSame([], static::$server->requests());
        }

        public function testWebmentionEndpointIsNotLookedForBehindARedirectToThisMachine()
        {
            $this->useLocalServerAsProxy();
            $target = 'http://203.0.113.10/redirect?to=' . urlencode(static::$server->url('/advertise?endpoint=x'));

            Webmention::supportsMentions($target);

            foreach (static::$server->requests() as $request) {
                $this->assertStringContainsString(' http://203.0.113.10/redirect', $request, 'Only the first URL may be requested.');
            }
        }
    }

}
