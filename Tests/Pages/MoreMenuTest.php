<?php

namespace Tests\Pages {

    /**
     * Every post, comment and profile has a "More" menu with "Copy link" in it, for everyone:
     * on your own things, on other people's, and when you are not signed in.
     */
    class MoreMenuTest extends \Tests\BrowserTestCase
    {

        private static $author;
        private static $reader;
        private static $entry;
        private static $commentUrl;

        public function setUp(): void
        {
            if (!static::$author) {
                static::$author = static::member('menuauthor');
                static::$reader = static::member('menureader');
                static::$entry = $this->post(static::$author);
                static::$commentUrl = $this->comment(static::$entry, static::$reader);
            }
        }

        public static function tearDownAfterClass(): void
        {
            parent::tearDownAfterClass();
            static::$author = static::$reader = static::$entry = static::$commentUrl = null;
        }

        public static function viewers()
        {
            return ['a visitor' => [null], 'the author' => ['author'], 'another member' => ['reader']];
        }

        /**
         * @dataProvider viewers
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('viewers')]
        public function testPostHasCopyLink($who)
        {
            $as = $who ? static::$$who : null;
            $url = static::$entry->getDisplayURL();

            foreach ([$url, static::$author->getDisplayURL()] as $page) {
                $result = $this->get($page, $as);
                $this->assertEquals(200, $result['status']);
                $this->assertMenuCopies($result['body'], $url, "The post's menu on $page should copy its permalink.");
            }
        }

        /**
         * @dataProvider viewers
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('viewers')]
        public function testProfileHasCopyLink($who)
        {
            $as = $who ? static::$$who : null;
            $result = $this->get(static::$author->getDisplayURL(), $as);

            $this->assertMenuCopies($result['body'], static::$author->getDisplayURL(), "The profile's menu should copy the profile address.");
        }

        /**
         * @dataProvider viewers
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('viewers')]
        public function testCommentHasCopyLinkToItsAnchor($who)
        {
            $as = $who ? static::$$who : null;
            $anchor = 'annotation-' . basename(static::$commentUrl);
            $result = $this->get(static::$entry->getDisplayURL(), $as);

            $this->assertStringContainsString('id="' . $anchor . '"', $result['body'], 'Each comment should be the target of an anchor.');
            $this->assertMenuCopies($result['body'], static::$entry->getDisplayURL() . '#' . $anchor, "The comment's menu should copy the address of its anchor.");
        }

        public function testFeedsOfAListKeepTheirPosts()
        {
            // The menu's variables must not take the place of a page's own: a list keeps its posts in "items"
            $this->post(static::$author);
            $this->post(static::$author);

            foreach ([static::$author->getDisplayURL() . '?_t=rss', '/?_t=rss'] as $feed) {
                $this->assertGreaterThanOrEqual(3, substr_count($this->get($feed)['body'], '<item>'), "$feed should list every post.");
            }
            $json = json_decode($this->get(static::$author->getDisplayURL() . '?_t=json')['body'], true);
            $this->assertGreaterThanOrEqual(3, count($json['items'] ?? []), 'The JSON of a profile should list every post.');
        }

        private function assertMenuCopies($page, $url, $message)
        {
            $this->assertStringContainsString('data-idno-more-menu', $page, 'The page should have a "More" menu.');
            // The web server may know the site by another host name than this process: compare from the path on
            $path = (string) parse_url($url, PHP_URL_PATH) . (parse_url($url, PHP_URL_FRAGMENT) ? '#' . parse_url($url, PHP_URL_FRAGMENT) : '');
            $this->assertMatchesRegularExpression('#<a[^>]+data-idno-copy-link[^>]+href="https?://[^/"]+' . preg_quote(htmlspecialchars($path), '#') . '"#', $page, $message);
        }
    }

}
