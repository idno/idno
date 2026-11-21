<?php

namespace Tests\Core {

    use Idno\Entities\UnfurledUrl;

    /**
     * Link previews fetch the first link in a post, so the link, and anything it redirects to,
     * must not be on this machine's own network.
     */
    class UnfurledUrlTest extends \Tests\OutboundHttpTestCase
    {

        public function testPageAtAPublicAddressIsFetched()
        {
            $this->useLocalServerAsProxy();

            (new UnfurledUrl())->unfurl('http://203.0.113.10/page');

            $this->assertSame(['GET http://203.0.113.10/page'], static::$server->requests());
        }

        public function testRedirectToThisMachineIsNotFollowed()
        {
            $this->useLocalServerAsProxy();
            $start = 'http://203.0.113.10/redirect?to=' . urlencode(static::$server->url('/secret'));

            $unfurled = (new UnfurledUrl())->unfurl($start);

            $this->assertFalse($unfurled);
            $this->assertSame(['GET ' . $start], static::$server->requests(), 'Only the first URL may be requested.');
        }

        public function testPageOnThisMachineIsNotFetched()
        {
            $this->useLocalServerAsProxy();

            $unfurled = (new UnfurledUrl())->unfurl(static::$server->url('/secret'));

            $this->assertFalse($unfurled);
            $this->assertSame([], static::$server->requests(), 'No request may be made.');
        }
    }

}
