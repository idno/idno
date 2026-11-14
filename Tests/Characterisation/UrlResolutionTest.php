<?php

namespace Tests\Characterisation {

    class UrlResolutionTest extends CharacterisationTestCase
    {

        public function testPermalinkResolves(): void
        {
            $s    = $this->createStatus($this->user(), 'characterisation #pinned');
            $path = $this->path($s->getURL());
            $r    = $this->site()->request('GET', $path);

            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('h-entry', $r->body);
            $this->assertStringContainsString('characterisation', $r->body);
            $this->assertMatchesRegularExpression('#^\d{4}/[a-z0-9-]+$#', $path);
        }

        public function testViewByIdResolves(): void
        {
            $s = $this->createStatus($this->user(), 'characterisation #pinned');
            $this->assertSame(200, $this->site()->request('GET', 'view/' . $s->getID())->status);
        }

        public function testShortlinkRedirectsToPermalink(): void
        {
            $s = $this->createStatus($this->user(), 'characterisation #pinned');
            // The shortlink route takes the entity's short code, not its ID. The code is
            // generated lazily, and its internal save() is refused without a session, so
            // persist it explicitly for the web server to see.
            $short = $s->getShortURL();
            $s->save(true);
            $r = $this->site()->request('GET', $this->path($short));

            $this->assertSame(301, $r->status);
            $this->assertSame($s->getURL(), $r->header('Location'));
        }

        public function testProfileResolves(): void
        {
            $r = $this->site()->request('GET', 'profile/testuser');

            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('h-card', $r->body);
        }

        public function testTagPageResolves(): void
        {
            $this->createStatus($this->user(), 'characterisation #pinned');
            $r = $this->site()->request('GET', 'tag/pinned');

            $this->assertSame(200, $r->status);
            $this->assertStringContainsString('characterisation', $r->body);
        }

        public function testContentTypeFilterResolves(): void
        {
            $this->createStatus($this->user(), 'characterisation #pinned');
            $this->assertSame(200, $this->site()->request('GET', 'content/statusupdates/')->status);
        }

        public function testTemplateTypeQueryParameter(): void
        {
            $this->createStatus($this->user(), 'characterisation #pinned');

            $rss = $this->site()->request('GET', '?_t=rss');
            $this->assertSame(200, $rss->status);
            $this->assertStringContainsString('xml', $rss->header('Content-Type'));

            $json = $this->site()->request('GET', '?_t=json');
            $this->assertSame(200, $json->status);
            $this->assertIsArray($json->json());
        }

        public function testTrailingSlashIsOptional(): void
        {
            $this->assertSame(200, $this->site()->request('GET', 'profile/testuser/')->status);
        }

        public function testUnknownEntityIs404(): void
        {
            // Despite the name, an unknown entity answers 410 Gone (Page::goneContent()), not 404.
            $this->assertSame(410, $this->site()->request('GET', 'view/000000000000000000000000')->status);
        }

    }

}
