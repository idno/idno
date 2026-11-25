<?php

namespace Tests\Pages {

    use Idno\Core\Idno;

    /**
     * Every post has a "More" menu (content/menu) with "Copy link" in it, for everyone, in
     * both looks. Plugins add their items through content/menu/items. In Twenty26 the menu
     * also holds Edit and Delete for whoever may edit the post.
     */
    class PostMenuTest extends \Tests\BrowserTestCase
    {

        private static $author;
        private static $reader;
        private static $entry;
        private static $savedTheme;

        public function setUp(): void
        {
            if (!static::$author) {
                static::$savedTheme = Idno::site()->config()->theme;
                static::$author = static::member('pmenuauthor');
                static::$reader = static::member('pmenureader');
                static::$entry = $this->post(static::$author);
            }
        }

        public static function tearDownAfterClass(): void
        {
            Idno::site()->config()->theme = static::$savedTheme;
            Idno::site()->config()->save();
            parent::tearDownAfterClass();
            static::$author = static::$reader = static::$entry = null;
        }

        public static function cases()
        {
            $cases = [];
            foreach (['default' => '', 'Twenty26' => 'Twenty26'] as $look => $theme) {
                foreach (['a visitor' => null, 'the author' => 'author', 'another member' => 'reader'] as $name => $who) {
                    $cases["$look, $name"] = [$theme, $who];
                }
            }

            return $cases;
        }

        /**
         * A pattern for an attribute that holds an address of this site, whatever host the
         * site gives itself (the server under test may call itself something other than the
         * host the tests reach it by).
         */
        private function attribute($name, $url, $after = '')
        {
            $local = (string) parse_url($url, PHP_URL_PATH);
            if ($query = parse_url($url, PHP_URL_QUERY)) {
                $local .= '?' . $query;
            }

            return '#' . $name . '="(?:https?://[^/"]+)?' . preg_quote(htmlspecialchars($local), '#') . '"' . $after . '#';
        }

        private function look($theme)
        {
            if (Idno::site()->config()->theme !== $theme) {
                Idno::site()->config()->theme = $theme;
                Idno::site()->config()->save();
            }
        }

        /**
         * @dataProvider cases
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
        public function testPostHasAMenuThatCopiesItsLink($theme, $who)
        {
            $this->look($theme);
            $as = $who ? static::$$who : null;
            $url = static::$entry->getDisplayURL();

            foreach ([$url, static::$author->getDisplayURL()] as $page) {
                $result = $this->get($page, $as);
                $this->assertEquals(200, $result['status']);
                $this->assertSame(1, substr_count($result['body'], 'data-idno-more-menu'), "The post should have one \"More\" menu on $page.");
                $this->assertMatchesRegularExpression(
                    '#<a[^>]+data-idno-copy-link[^>]+' . trim($this->attribute('href', $url), '#') . '#',
                    $result['body'],
                    "The post's menu on $page should copy its permalink."
                );
            }
        }

        /**
         * The menu is drawn inside each post of a list, and the RSS and JSON forms of a list
         * read the list's posts from the template after its body has been drawn: drawing a
         * menu must leave them alone.
         */
        public function testFeedsOfAListKeepTheirPosts()
        {
            $this->look('');
            $second = $this->post(static::$author);
            $profile = static::$author->getDisplayURL();

            $rss = $this->get($profile . '?_t=rss')['body'];
            $this->assertSame(2, substr_count($rss, '<item>'), 'The RSS feed of a profile has every post.');
            $json = json_decode($this->get($profile . '?_t=json')['body'], true);
            $this->assertIsArray($json['items'] ?? null, 'The JSON of a profile has its posts.');
            $this->assertCount(2, $json['items']);
            $second->delete();
        }

        /**
         * @dataProvider cases
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
        public function testTwenty26MenuHoldsEditAndDeleteForTheAuthorOnly($theme, $who)
        {
            if ($theme !== 'Twenty26') {
                $this->assertTrue(true);

                return;
            }
            $this->look($theme);
            $as = $who ? static::$$who : null;
            $result = $this->get(static::$entry->getDisplayURL(), $as);
            $this->assertTrue((bool) preg_match('#<details class="idno-more".*?</details>#s', $result['body'], $menu), 'The page should have a "More" menu.');

            $hasEdit = (bool) preg_match($this->attribute('href', static::$entry->getEditURL()), $menu[0]);
            $hasDelete = strpos($menu[0], htmlspecialchars((string) parse_url(static::$entry->getDeleteURL(), PHP_URL_PATH))) !== false;
            $this->assertSame($who === 'author', $hasEdit, 'Edit is in the menu for the author only.');
            $this->assertSame($who === 'author', $hasDelete, 'Delete is in the menu for the author only.');
            $this->assertStringNotContainsString('idno-entry-kebab', $result['body'], 'The old Edit / Delete menu is gone.');
        }
    }

}
