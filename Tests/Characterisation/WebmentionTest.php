<?php

namespace Tests\Characterisation {

    /**
     * The Webmention endpoint as a remote sender sees it. Remote hosts are
     * .invalid so nothing leaves the machine; every case here is refused before
     * any source is fetched (`WebmentionAnswersTest` and `WebmentionAnnotationKeysTest`
     * send mentions whose sources are fetched).
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

        /**
         * A field that is not text (`source[]=a`) is a request that is missing something, not a server error (the legacy endpoint answered it with the 500 page).
         */
        public function testFieldsThatAreNotTextAreAnUnknownError(): void
        {
            $r = $this->site()->request('POST', 'webmention/', ['Content-Type' => 'application/x-www-form-urlencoded'], 'source[]=a&target[]=b');

            $this->assertSame(400, $r->status);
            $this->assertStringContainsString('application/json', (string) $r->header('Content-Type'));
            $this->assertSame('unknown_error', json_decode($r->body, true)['error']);
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
         * A target that names nothing (a post that is not there) is refused as such, before the source is looked at.
         */
        public function testATargetThatNamesNoPostIsTargetNotFound(): void
        {
            $r = $this->site()->request('POST', 'webmention/', [], [
                'source' => 'https://remote.invalid/a',
                'target' => $this->site()->baseUrl() . '2000/does-not-exist',
            ]);

            $this->assertSame(400, $r->status);
            $this->assertSame('target_not_found', json_decode($r->body, true)['error']);
        }

        /**
         * The target is a page of the site and the source is one the site may not fetch: the refusal says so (the legacy endpoint answered it with the
         * self-mention error).
         */
        public function testASourceTheSiteMayNotFetchIsSourceNotAllowed(): void
        {
            $base = $this->site()->baseUrl();
            $r    = $this->site()->request('POST', 'webmention/', [], [
                'source' => 'https://remote.invalid/a',
                'target' => $base . 'profile/' . $this->user()->getHandle(),
            ]);

            $this->assertSame(400, $r->status);
            $this->assertStringContainsString('application/json', (string) $r->header('Content-Type'));
            $this->assertSame(
                '{"error":"source_not_allowed","error_text":"The source URL is not allowed."}',
                $r->body
            );
        }
    }

}
