<?php

namespace Tests\Characterisation {

    /**
     * The Micropub endpoint as a Micropub client sees it. Tokens come from
     * issueMicropubToken(); a request with one succeeds where the same request
     * without one is refused, which also proves the helper.
     */
    class MicropubTest extends CharacterisationTestCase
    {

        private const FORBIDDEN = "{\n    \"error\": \"forbidden\",\n    \"error_description\": \"\",\n    \"messages\": []\n}";

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

            $this->assertSame(403, $r->status);
            $this->assertSame(self::FORBIDDEN, $r->body);
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

            $this->assertSame(403, $r->status);
            $this->assertSame(self::FORBIDDEN, $r->body);
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

        public function testCreateWithoutTokenIsForbiddenAndCreatesNothing(): void
        {
            $marker = $this->marker('micropub-anon');
            $r      = $this->site()->request('POST', 'micropub/endpoint', [], ['h' => 'entry', 'content' => $marker]);

            $this->assertSame(403, $r->status);
            $this->assertSame(self::FORBIDDEN, $r->body);
            $this->assertNull($r->header('Location'));
            $this->assertFalse($this->statusExists($marker));
        }

        public function testCreateWithInvalidTokenIsForbiddenAndCreatesNothing(): void
        {
            $marker = $this->marker('micropub-invalid');
            $r      = $this->site()->request('POST', 'micropub/endpoint', ['Authorization' => 'Bearer nope'], ['h' => 'entry', 'content' => $marker]);

            $this->assertSame(403, $r->status);
            $this->assertSame(self::FORBIDDEN, $r->body);
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
