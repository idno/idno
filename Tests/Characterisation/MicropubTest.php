<?php

namespace Tests\Characterisation {

    /**
     * The Micropub endpoint as a Micropub client sees it. Tokens come from
     * issueMicropubToken(); a request with one succeeds where the same request
     * without one is refused, which also proves the helper.
     */
    class MicropubTest extends CharacterisationTestCase
    {

        private const UNAUTHORIZED = "{\n    \"error\": \"unauthorized\",\n    \"error_description\": \"A valid access token is required\",\n    \"messages\": []\n}";

        private function auth(): array
        {
            return ['Authorization' => 'Bearer ' . $this->issueMicropubToken($this->user())];
        }

        private function marker(string $prefix): string
        {
            return $prefix . '-' . uniqid();
        }

        /**
         * Register what a create made for cleanup, then check it is served.
         */
        private function assertCreatedAndServed(\Tests\Characterisation\SiteResponse $r, string $marker): void
        {
            $base     = $this->site()->baseUrl();
            $location = $r->header('Location');
            $this->assertNotNull($location, 'No Location header: ' . $r->status . ' ' . $r->body);
            $this->registerEntityAtUrl($location);

            $this->assertSame(201, $r->status);
            $this->assertStringStartsWith($base, $location);

            $page = $this->site()->request('GET', $this->path($location));
            $this->assertSame(200, $page->status);
            $this->assertStringContainsString($marker, $page->body);
        }

        public function testConfigRequiresToken(): void
        {
            $r = $this->site()->request('GET', 'micropub/endpoint?q=config');

            // A GET, like a POST, is answered only for an API request: 401, not the gatekeeper's 403.
            $this->assertSame(401, $r->status);
            $this->assertSame(self::UNAUTHORIZED, $r->body);
        }

        public function testConfigWithToken(): void
        {
            $base = $this->site()->baseUrl();
            $r    = $this->site()->request('GET', 'micropub/endpoint?q=config', $this->auth());

            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('application/json', $r->header('Content-Type'));
            $this->assertSame($base . 'micropub/endpoint', $r->json()['media-endpoint']);
            $this->assertArrayHasKey('syndicate-to', $r->json());
        }

        public function testSyndicateToWithToken(): void
        {
            $r = $this->site()->request('GET', 'micropub/endpoint?q=syndicate-to', $this->auth() + ['Accept' => 'application/json']);

            $this->assertSame(200, $r->status);
            $this->assertArrayHasKey('syndicate-to', $r->json());
        }

        public function testSyndicateToWithoutTokenIsForbidden(): void
        {
            $r = $this->site()->request('GET', 'micropub/endpoint?q=syndicate-to', ['Accept' => 'application/json']);

            $this->assertSame(401, $r->status);
            $this->assertSame(self::UNAUTHORIZED, $r->body);
        }

        public function testCreateNoteFormEncoded(): void
        {
            $marker = $this->marker('micropub-marker');
            $r      = $this->site()->request('POST', 'micropub/endpoint', $this->auth(), ['h' => 'entry', 'content' => $marker]);

            $this->assertCreatedAndServed($r, $marker);
        }

        public function testCreateNoteJson(): void
        {
            $marker = $this->marker('micropub-json-marker');
            $r      = $this->site()->request(
                'POST',
                'micropub/endpoint',
                $this->auth() + ['Content-Type' => 'application/json'],
                json_encode(['type' => ['h-entry'], 'properties' => ['content' => [$marker]]])
            );

            $this->assertCreatedAndServed($r, $marker);
        }

        public function testCreateWithoutTokenIsUnauthorizedAndCreatesNothing(): void
        {
            $marker = $this->marker('micropub-anon');
            $r      = $this->site()->request('POST', 'micropub/endpoint', [], ['h' => 'entry', 'content' => $marker]);

            // A POST with no access token (or one that matches nobody) is "unauthorized", 401: the endpoint
            // answers a POST only for an API request, and no longer asks the session. It used to be a
            // "forbidden" 403 from the gatekeeper, which a logged-in browser's cookie could also pass.
            $this->assertSame(401, $r->status);
            $this->assertSame(self::UNAUTHORIZED, $r->body);
            $this->assertNull($r->header('Location'));
            $this->assertFalse($this->statusExists($marker));
        }

        public function testCreateWithInvalidTokenIsUnauthorizedAndCreatesNothing(): void
        {
            $marker = $this->marker('micropub-invalid');
            $r      = $this->site()->request('POST', 'micropub/endpoint', ['Authorization' => 'Bearer nope'], ['h' => 'entry', 'content' => $marker]);

            // A POST with no access token (or one that matches nobody) is "unauthorized", 401: the endpoint
            // answers a POST only for an API request, and no longer asks the session. It used to be a
            // "forbidden" 403 from the gatekeeper, which a logged-in browser's cookie could also pass.
            $this->assertSame(401, $r->status);
            $this->assertSame(self::UNAUTHORIZED, $r->body);
            $this->assertNull($r->header('Location'));
            $this->assertFalse($this->statusExists($marker));
        }

        public function testEndpointIsAdvertised(): void
        {
            $base = $this->site()->baseUrl();
            $r    = $this->site()->request('GET', '');

            $this->assertStringContainsString('<link rel="micropub" href="' . $base . 'micropub/endpoint">', $r->body);
            $this->assertStringContainsString('<' . $base . 'micropub/endpoint>; rel="micropub"', (string)$r->header('Link'));
        }
    }

}
