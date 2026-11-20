<?php

namespace Tests\Core {

    use Idno\Core\Idno;
    use Idno\Core\Webservice;
    use Tests\LocalHttpServer;

    /**
     * The outbound HTTP client: certificate verification.
     *
     * Nothing here leaves the machine: the tests talk to a local server (see
     * \Tests\LocalHttpServer).
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
    }

}
