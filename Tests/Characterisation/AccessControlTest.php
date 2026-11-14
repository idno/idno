<?php

namespace Tests\Characterisation {

    /**
     * What an anonymous visitor can and cannot see or do. Requests carry no
     * cookies, so every request here is anonymous.
     *
     * Fixture bodies start with filler words. A status permalink slug is built
     * from the first few words of the body, and the slug is echoed back in the
     * URLs of an error page; the filler keeps the marker out of the slug so a
     * marker in a response means the content itself was served.
     */
    class AccessControlTest extends CharacterisationTestCase
    {

        private const OUTBOX_HEADERS = ['Accept' => 'application/activity+json'];

        private function marker(string $prefix): string
        {
            return $prefix . '-' . uniqid();
        }

        private function body(string $marker): string
        {
            return 'one two three four five six seven eight nine ten ' . $marker;
        }

        public function testPrivatePostIsNotServedToAnonymous(): void
        {
            $marker  = $this->marker('secret-marker');
            $private = $this->createStatus($this->user(), $this->body($marker), $this->user()->getUUID());

            $r = $this->site()->request('GET', $this->path($private->getURL()));

            $this->assertSame(410, $r->status);
            $this->assertStringNotContainsString($marker, $r->body);
        }

        public function testDraftIsNotServedToAnonymous(): void
        {
            $marker = $this->marker('draft-marker');
            $draft  = $this->createStatus($this->user(), $this->body($marker), 'PUBLIC', 'draft');

            $r = $this->site()->request('GET', $this->path($draft->getURL()));

            // A draft is hidden from everyone but its owner and admins, whatever its access
            // setting: it answers exactly like a private post (fixed in idno/idno#3403).
            $this->assertSame(410, $r->status);
            $this->assertStringNotContainsString($marker, $r->body);
        }

        public function testPrivateDraftIsNotServedToAnonymous(): void
        {
            $marker = $this->marker('private-draft-marker');
            $draft  = $this->createStatus($this->user(), $this->body($marker), $this->user()->getUUID(), 'draft');

            $r = $this->site()->request('GET', $this->path($draft->getURL()));

            $this->assertSame(410, $r->status);
            $this->assertStringNotContainsString($marker, $r->body);
        }

        public function testPrivateAndDraftAbsentFromHomepage(): void
        {
            [$private, $draft, $control] = $this->createListingFixtures();

            $r = $this->site()->request('GET', '');

            $this->assertSame(200, $r->status);
            $this->assertStringContainsString($control, $r->body);
            $this->assertStringNotContainsString($private, $r->body);
            $this->assertStringNotContainsString($draft, $r->body);
        }

        public function testPrivateAndDraftAbsentFromRss(): void
        {
            [$private, $draft, $control] = $this->createListingFixtures();

            $r = $this->site()->request('GET', 'feed.xml');

            $this->assertSame(200, $r->status);
            $this->assertStringContainsString($control, $r->body);
            $this->assertStringNotContainsString($private, $r->body);
            $this->assertStringNotContainsString($draft, $r->body);
        }

        public function testPrivateAndDraftAbsentFromOutbox(): void
        {
            [$private, $draft, $control] = $this->createListingFixtures();

            $collection = $this->site()->request('GET', 'actor/testuser/outbox', self::OUTBOX_HEADERS);
            $this->assertSame(200, $collection->status);
            $this->assertStringNotContainsString($private, $collection->body);
            $this->assertStringNotContainsString($draft, $collection->body);

            $first = $collection->json()['first'];
            $page  = $this->site()->request('GET', $this->path($first), self::OUTBOX_HEADERS);

            $this->assertSame(200, $page->status);
            $this->assertStringContainsString($control, $page->body);
            $this->assertStringNotContainsString($private, $page->body);
            $this->assertStringNotContainsString($draft, $page->body);
        }

        public function testAdminRequiresLogin(): void
        {
            $r = $this->site()->request('GET', 'admin/');

            $this->assertSame(403, $r->status);
            $this->assertNull($r->header('Location'));
        }

        public function testEditFormRequiresLogin(): void
        {
            $r = $this->site()->request('GET', 'status/edit');

            $this->assertSame(403, $r->status);
        }

        public function testAnonymousPostWithoutCsrfTokenCreatesNothing(): void
        {
            $marker = $this->marker('csrf-marker');

            $r = $this->site()->request('POST', 'status/edit', [], ['body' => $marker]);

            $this->assertSame(302, $r->status);
            $this->assertSame($this->site()->baseUrl(), $r->header('Location'));
            $this->assertFalse($this->statusExists($marker));
        }

        public function testAccountSettingsRequireLogin(): void
        {
            $r = $this->site()->request('GET', 'account/settings');

            $this->assertSame(403, $r->status);
        }

        /**
         * A private post, a PUBLIC-access draft (the case most likely to leak)
         * and a public control post, each with its own marker.
         *
         * @return string[] [private marker, draft marker, control marker]
         */
        private function createListingFixtures(): array
        {
            $private = $this->marker('secret-marker');
            $draft   = $this->marker('draft-marker');
            $control = $this->marker('control-marker');

            $this->createStatus($this->user(), $this->body($private), $this->user()->getUUID());
            $this->createStatus($this->user(), $this->body($draft), 'PUBLIC', 'draft');
            $this->createStatus($this->user(), $this->body($control));

            return [$private, $draft, $control];
        }

    }

}
