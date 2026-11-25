<?php

namespace IdnoPlugins\StaticPages\Tests {

    use IdnoPlugins\StaticPages\StaticPage;

    /**
     * Static pages in Twenty26: the page is a card with its title, and the form is a
     * Twenty26 compose form.
     */
    class ModernTemplatesTest extends \Tests\ModernTemplatesTestCase
    {

        protected function plugin(): string
        {
            return 'StaticPages';
        }

        protected function modernTemplates(): array
        {
            return ['entity/StaticPage', 'entity/StaticPage/edit', 'staticpages/page', 'staticpages/admin', 'staticpages/admin/menu'];
        }

        private function page(): StaticPage
        {
            $page = new StaticPage();
            $page->title = 'About <us> & "them"';
            $page->body = '<p>Who we are.</p>';
            $page->category = 'No Category';

            return $page;
        }

        public function testThePageIsACardWithItsTitle()
        {
            $html = $this->drawModern('staticpages/page', ['object' => $this->keep($this->page())]);

            $this->assertIsTwenty26Markup($html, 'The page');
            $this->assertStringContainsString('<article class="idno-page">', $html);
            $this->assertStringContainsString('<h1 class="idno-entry-title p-name">About  &amp; &quot;them&quot;</h1>', $html);
            $this->assertStringContainsString('class="idno-entry-body e-content"', $html);
            $this->assertStringContainsString('<p>Who we are.</p>', $html);
            // The administrator's controls, each a POST or a plain link
            $this->assertStringContainsString('class="idno-page-actions"', $html);
            $this->assertStringContainsString('admin/staticpages/', $html);
        }

        public function testAHiddenTitleIsNotDrawn()
        {
            $page = $this->page();
            $page->hide_title = 1;
            $html = $this->drawModern('entity/StaticPage', ['object' => $this->keep($page)]);

            $this->assertStringNotContainsString('<h1', $html);
        }

        public function testTheRedirectAddressIsALinkOnlyIfItIsAWebAddress()
        {
            $page = $this->page();
            $page->forward_url = 'javascript:alert(1)';
            $html = $this->drawModern('entity/StaticPage', ['object' => $this->keep($page)]);
            $this->assertStringNotContainsString('href="javascript:', $html);

            $page = $this->page();
            $page->forward_url = 'https://example.com/a?b=1&c=2';
            $html = $this->drawModern('entity/StaticPage', ['object' => $this->keep($page)]);
            $this->assertStringContainsString('href="https://example.com/a?b=1&amp;c=2"', $html);
        }

        public function testTheFormIsAComposeForm()
        {
            $html = $this->drawModern('entity/StaticPage/edit', ['object' => new StaticPage(), 'category' => 'No Category', 'categories' => ['News & views']]);

            $this->assertIsComposeForm($html, 'The page form');
            $this->assertMatchesRegularExpression('~<input type="text" name="title" id="title"~', $html);
            $this->assertStringContainsString('<select name="category" id="category" class="idno-select">', $html);
            $this->assertStringContainsString('<label class="idno-label" for="category">', $html);
            $this->assertStringContainsString('News &amp; views', $html);
            $this->assertStringContainsString('name="forward_url" id="forward_url"', $html);
            $this->assertSame(2, substr_count($html, 'name="hide_title"'));
            $this->assertStringContainsString('name="__bTa" value="/staticpages/edit"', $html);
            $this->assertStringNotContainsString('tinymce', $html, 'TinyMCE is the default look\'s editor.');
        }
    }

}
