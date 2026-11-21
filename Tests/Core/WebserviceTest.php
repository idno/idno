<?php

namespace Tests\Core {

    use Idno\Core\Idno;
    use Idno\Core\Webservice;
    use Tests\LocalHttpServer;

    /**
     * Stands in for the URL check in testSafeSendConnectsToTheAddressesThatWereChecked: every
     * host "resolves" to this machine.
     */
    class WebserviceResolvingToLoopback extends Webservice
    {
        protected static function resolveSafeUrl($url)
        {
            return ['127.0.0.1'];
        }
    }

    /**
     * The outbound HTTP client: certificate verification, and Webservice::safeSend(), which is
     * how URLs supplied by a remote party are fetched.
     *
     * Nothing here leaves the machine. The safeSend() tests set the proxy_string setting to a
     * local server (see \Tests\LocalHttpServer), so that the "public" addresses they use, in the
     * documentation range 203.0.113.0/24, are answered by it.
     */
    class WebserviceTest extends \Tests\OutboundHttpTestCase
    {

        // -----------------------------------------------------------------
        // Certificate verification
        // -----------------------------------------------------------------

        public function testSelfSignedCertificateIsRefused()
        {
            $https = LocalHttpServer::start(true);
            try {
                $result = Webservice::get($https->url('/page'));
            } finally {
                $https->stop();
            }

            $this->assertStringNotContainsString('landed', (string) $result['content'], 'A page served with a certificate that nobody trusted signed must not be fetched.');
            $this->assertNotEquals(200, $result['response']);
            $this->assertNotEmpty($result['error']);
        }

        public function testSelfSignedCertificateIsAcceptedWhenVerificationIsDisabled()
        {
            Idno::site()->config()->disable_ssl_verify = true;

            $https = LocalHttpServer::start(true);
            try {
                $result = Webservice::get($https->url('/page'));
            } finally {
                $https->stop();
            }

            $this->assertEquals(200, $result['response'], 'disable_ssl_verify must still turn certificate verification off. Error: ' . $result['error']);
            $this->assertEquals('landed GET /page', $result['content']);
        }

        // -----------------------------------------------------------------
        // Requests to URLs the site's own people chose: unchanged
        // -----------------------------------------------------------------

        public function testOrdinaryRequestsStillFollowRedirectsAnywhere()
        {
            $result = Webservice::get(self::$server->url('/chain/2'));

            $this->assertEquals(200, $result['response']);
            $this->assertEquals('landed GET /chain/0', $result['content']);
        }

        // -----------------------------------------------------------------
        // safeSend(): URLs supplied by a remote party
        // -----------------------------------------------------------------

        /**
         * Addresses that must never be fetched for a remote party. {server} is the local server.
         */
        public static function unsafeTargetProvider(): array
        {
            return [
                'loopback'             => ['{server}/secret'],
                'localhost'            => ['http://localhost/secret'],
                'loopback IPv6'        => ['http://[::1]/secret'],
                'private 10/8'         => ['http://10.0.0.1/secret'],
                'private 172.16/12'    => ['http://172.16.0.1/secret'],
                'private 192.168/16'   => ['http://192.168.1.1/secret'],
                'link-local metadata'  => ['http://169.254.169.254/latest/meta-data/'],
                'file scheme'          => ['file:///etc/passwd'],
                'gopher scheme'        => ['gopher://203.0.113.11/_secret'],
            ];
        }

        private function expandTarget(string $target): string
        {
            return str_replace('{server}', rtrim(self::$server->url(''), '/'), $target);
        }

        /**
         * @dataProvider unsafeTargetProvider
         */
        public function testSafeSendRefusesAnUnsafeUrl($target)
        {
            $this->useLocalServerAsProxy();

            $result = Webservice::safeSend('get', $this->expandTarget($target));

            $this->assertSame([], self::$server->requests(), 'No request may be made.');
            $this->assertEquals(0, $result['response']);
            $this->assertSame('', $result['content']);
            $this->assertNotEmpty($result['error']);
        }

        /**
         * @dataProvider unsafeTargetProvider
         */
        public function testSafeSendDoesNotFollowARedirectToAnUnsafeUrl($target)
        {
            $this->useLocalServerAsProxy();
            $start = 'http://203.0.113.10/redirect?to=' . urlencode($this->expandTarget($target));

            $result = Webservice::safeSend('get', $start);

            $this->assertSame(['GET ' . $start], self::$server->requests(), 'Only the first URL may be requested.');
            $this->assertEquals(0, $result['response']);
            $this->assertSame('', $result['content']);
            $this->assertNotEmpty($result['error']);
        }

        public function testSafeSendFollowsARedirectToAPublicAddress()
        {
            $this->useLocalServerAsProxy();

            $result = Webservice::safeSend('get', 'http://203.0.113.10/redirect?to=' . urlencode('http://203.0.113.11/arrived'));

            $this->assertEquals(200, $result['response']);
            $this->assertEquals('landed GET /arrived', $result['content']);
            $this->assertEquals('http://203.0.113.11/arrived', $result['effective_url']);
            $this->assertEmpty($result['error']);
        }

        public function testSafeSendFollowsFiveRedirects()
        {
            $this->useLocalServerAsProxy();

            $result = Webservice::safeSend('get', 'http://203.0.113.10/chain/5');

            $this->assertEquals(200, $result['response']);
            $this->assertEquals('landed GET /chain/0', $result['content']);
            $this->assertCount(6, self::$server->requests());
        }

        public function testSafeSendGivesUpAfterFiveRedirects()
        {
            $this->useLocalServerAsProxy();

            $result = Webservice::safeSend('get', 'http://203.0.113.10/chain/6');

            $requests = self::$server->requests();
            $this->assertCount(6, $requests, 'The first request and five redirects, then no more.');
            $this->assertNotContains('GET http://203.0.113.10/chain/0', $requests);
            $this->assertEquals(0, $result['response']);
            $this->assertSame('', $result['content']);
            $this->assertNotEmpty($result['error']);
        }

        public function testSafeSendSendsParametersAndHeadersLikeSend()
        {
            $this->useLocalServerAsProxy();

            $result = Webservice::safeSend('get', 'http://203.0.113.10/page', ['a' => 'b'], ['Accept: text/plain']);

            $this->assertEquals('landed GET /page', $result['content']);
            $this->assertSame(['GET http://203.0.113.10/page?a=b'], self::$server->requests());
            $this->assertStringContainsString('Accept: text/plain', Webservice::getLastRequest());
        }

        /**
         * A redirected POST is repeated as a GET, except after a 307 or 308: what curl does when
         * it follows redirects itself.
         */
        public function testSafeSendRedirectsAPostTheWayCurlDoes()
        {
            $this->useLocalServerAsProxy();
            $inbox = urlencode('http://203.0.113.11/inbox');

            $result = Webservice::safeSend('post', 'http://203.0.113.10/redirect?code=302&to=' . $inbox, 'payload');
            $this->assertEquals('landed GET /inbox', $result['content']);

            $result = Webservice::safeSend('post', 'http://203.0.113.10/redirect?code=307&to=' . $inbox, 'payload');
            $this->assertEquals('landed POST /inbox', $result['content']);

            self::$server->reset();
            $result = Webservice::safeSend('head', 'http://203.0.113.10/redirect?code=303&to=' . $inbox);
            $this->assertEquals(200, $result['response']);
            $this->assertContains('HEAD http://203.0.113.11/inbox', self::$server->requests());
        }

        /**
         * The connection is made to the addresses that passed the check, not to whatever the host
         * name resolves to a moment later: pinned.invalid can't be resolved at all.
         */
        public function testSafeSendConnectsToTheAddressesThatWereChecked()
        {
            $port = parse_url(self::$server->url(), PHP_URL_PORT);

            $result = WebserviceResolvingToLoopback::safeSend('get', 'http://pinned.invalid:' . $port . '/pinned');

            $this->assertEquals(200, $result['response'], $result['error']);
            $this->assertEquals('landed GET /pinned', $result['content']);
        }
    }

}
