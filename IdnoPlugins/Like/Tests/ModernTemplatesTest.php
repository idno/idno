<?php

namespace IdnoPlugins\Like\Tests {

    use IdnoPlugins\Like\Like;

    /**
     * Bookmarks, likes and reposts in Twenty26: the kind of post is an icon the theme
     * has (Font Awesome is not loaded), beside the host the link goes to.
     */
    class ModernTemplatesTest extends \Tests\ModernTemplatesTestCase
    {

        protected function plugin(): string
        {
            return 'Like';
        }

        protected function modernTemplates(): array
        {
            return ['entity/Like', 'entity/Like/edit'];
        }

        public static function kinds(): array
        {
            return [
                'bookmark' => ['bookmarkof', 'u-bookmark-of', 'bookmark'],
                'like'     => ['likeof', 'u-like-of', 'like'],
                'repost'   => ['repostof', 'u-repost-of', 'repost'],
            ];
        }

        /**
         * @dataProvider kinds
         */
        public function testThePostShowsItsKindAndWhereItGoes($field, $class, $kind)
        {
            $like = new Like();
            $like->body = 'https://news.example/a/story?x=1&y=2';
            $like->$field = $like->body;
            $like->pageTitle = 'A <story> & more';
            $like->description = '<p>Worth reading.</p>';
            $html = $this->drawModern('entity/Like', ['object' => $this->keep($like)]);

            $this->assertIsTwenty26Markup($html, 'The ' . $field . ' post');
            $this->assertMatchesRegularExpression('~<p class="idno-entry-kicker idno-entry-kicker-' . $kind . '">\s*<span>news\.example</span>~', $html);
            $this->assertStringNotContainsString('<svg', $html, 'The icon is drawn by the stylesheet.');
            $this->assertMatchesRegularExpression('~<h2 class="idno-entry-title"><a href="https://news\.example/a/story\?x=1&amp;y=2" class="' . $class . ' p-name"~', $html);
            $this->assertStringContainsString('rel="noopener"', $html);
            $this->assertStringNotContainsString('<story>', $html);
            $this->assertStringContainsString('class="idno-entry-body e-content"', $html);
        }

        public function testAnAddressThatIsNotAWebAddressIsNotALink()
        {
            $like = new Like();
            $like->body = 'javascript:alert(1)';
            $like->bookmarkof = $like->body;
            $like->pageTitle = 'Click me';
            $html = $this->drawModern('entity/Like', ['object' => $this->keep($like)]);

            $this->assertStringNotContainsString('href="javascript:', $html);
            $this->assertStringNotContainsString('idno-entry-kicker', $html);
        }

        public function testTheFormIsAComposeForm()
        {
            $html = $this->drawModern('entity/Like/edit', ['object' => new Like()]);

            $this->assertIsComposeForm($html, 'The bookmark form');
            $this->assertMatchesRegularExpression('~<input[^>]*name="body"[^>]*id="body"~', $html);
            $this->assertMatchesRegularExpression('~<input[^>]*name="title"[^>]*id="title"~', $html);
            $this->assertStringContainsString('name="description"', $html);
            $this->assertStringContainsString('name="__bTa" value="/like/edit"', $html);
        }
    }

}
