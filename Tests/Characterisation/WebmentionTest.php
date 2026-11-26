<?php

namespace Tests\Characterisation {

    /**
     * The Webmention endpoint as a remote sender sees it. Remote hosts are
     * .invalid so nothing leaves the machine; every case here is refused before
     * any source is fetched.
     */
    class WebmentionTest extends CharacterisationTestCase
    {

        public function testEndpointIsAdvertisedInBodyAndNotInLinkHeader(): void
        {
            // The Link header advertises the endpoint only on a site that has a hub (a fresh site has one): the case here is a site with none, which the test
            // sets up itself and puts back.
            $config = \Idno\Core\Idno::site()->config();
            $hub    = $config->hub;
            $config->hub = '';
            $config->save();
            try {
                $base = $this->site()->baseUrl();
                $r    = $this->site()->request('GET', '');

                $this->assertSame(200, $r->status);
                $this->assertStringContainsString('<link href="' . $base . 'webmention/" rel="webmention"/>', $r->body);
                $this->assertStringNotContainsString('rel="webmention"', (string)$r->header('Link'));
            } finally {
                $config->hub = $hub;
                $config->save();
            }
        }

        public function testGetEndpointIs200AndServesHtmlPage(): void
        {
            $r = $this->site()->request('GET', 'webmention/');

            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('text/html', $r->header('Content-Type'));
            $this->assertStringContainsString('<title>Webmention endpoint</title>', $r->body);
        }

        public function testMissingParametersRejected(): void
        {
            $r = $this->site()->request('POST', 'webmention/', [], []);

            $this->assertSame(400, $r->status);
            $this->assertSame(
                '{"error":"unknown_error","error_text":"Not all the required webmention variables were set."}',
                $r->body
            );
        }

        public function testTargetOnAnotherSiteRejectedWithTargetNotFound(): void
        {
            $r = $this->site()->request('POST', 'webmention/', [], [
                'source' => 'https://remote.invalid/a',
                'target' => 'https://other.invalid/b',
            ]);

            $this->assertSame(400, $r->status);
            $this->assertSame(
                '{"error":"target_not_found","error_text":"The target page https:\/\/other.invalid\/b does not exist."}',
                $r->body
            );
        }

        /**
         * The unreachable source fails the SSRF check, but the response reports
         * it as "target_not_supported" with a self-mention message.
         */
        public function testUnknownLocalTargetRejectedWithMisleadingSelfMentionError(): void
        {
            $base = $this->site()->baseUrl();
            $r    = $this->site()->request('POST', 'webmention/', [], [
                'source' => 'https://remote.invalid/a',
                'target' => $base . '2000/does-not-exist',
            ]);

            $this->assertSame(400, $r->status);
            $this->assertSame(
                '{"error":"target_not_supported","error_text":"A page can\'t webmention itself."}',
                $r->body
            );
        }
    }

}
