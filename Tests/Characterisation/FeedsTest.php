<?php

namespace Tests\Characterisation {

    class FeedsTest extends CharacterisationTestCase
    {

        public function testRssFeedRoutes(): void
        {
            $this->createStatus($this->user(), 'characterisation #pinned');

            foreach (['feed.xml', 'rss.xml', 'feed'] as $path) {
                $r = $this->site()->request('GET', $path);

                $this->assertSame(200, $r->status, $path);
                $this->assertSame('application/rss+xml', $r->header('Content-Type'), $path);
                $this->assertNotFalse(simplexml_load_string($r->body), $path);
                $this->assertStringContainsString('characterisation', $r->body, $path);
            }
        }

        public function testRssItemLinksToPermalink(): void
        {
            $s   = $this->createStatus($this->user(), 'characterisation #pinned');
            $xml = simplexml_load_string($this->site()->request('GET', 'feed.xml')->body);

            $this->assertNotFalse($xml);
            $this->assertSame($s->getURL(), (string)$xml->channel->item[0]->link);
        }

        public function testJsonFeed(): void
        {
            $s    = $this->createStatus($this->user(), 'characterisation #pinned');
            $r    = $this->site()->request('GET', '?_t=jsonfeed');
            $json = $r->json();

            $this->assertSame(200, $r->status);
            $this->assertSame('https://jsonfeed.org/version/1', $json['version']);
            $this->assertSame($s->getURL(), $json['items'][0]['url']);
        }

        public function testHomepageAdvertisesFeed(): void
        {
            $r = $this->site()->request('GET', '');

            $this->assertMatchesRegularExpression('#<link[^>]+rel="alternate"[^>]+type="application/rss\+xml"#', $r->body);
        }

    }

}
