<?php

namespace IdnoPlugins\Text\Tests {

    use IdnoPlugins\Text\Entry;

    /**
     * Articles in Twenty26.
     */
    class ModernTemplatesTest extends \Tests\ModernTemplatesTestCase
    {

        protected function plugin(): string
        {
            return 'Text';
        }

        protected function modernTemplates(): array
        {
            return ['entity/Entry', 'entity/Entry/edit'];
        }

        public function testTheArticleHasItsTitleSummaryAndBody()
        {
            $entry = new Entry();
            $entry->title = 'The "fee" vote';
            $entry->short_description = 'A <short> summary';
            $entry->body = '<p>The senate voted.</p>';
            $html = $this->drawModern('entity/Entry', ['object' => $this->keep($entry)]);

            $this->assertIsTwenty26Markup($html, 'The article');
            $this->assertMatchesRegularExpression('~<h2 class="idno-entry-title p-name"><a\s+href="[^"]+">The &quot;fee&quot; vote</a>~', $html);
            $this->assertStringContainsString('class="idno-entry-subtitle p-summary"', $html);
            $this->assertStringContainsString('class="idno-entry-body e-content entry-content"', $html);
            $this->assertStringContainsString('<p>The senate voted.</p>', $html);
        }

        public function testTheFormIsAComposeForm()
        {
            $html = $this->drawModern('entity/Entry/edit', ['object' => new Entry()]);

            $this->assertIsComposeForm($html, 'The article form');
            $this->assertMatchesRegularExpression('~<input[^>]*name="title"[^>]*id="title"~', $html);
            $this->assertMatchesRegularExpression('~<input[^>]*name="subtitle"[^>]*id="subtitle"~', $html);
            $this->assertStringContainsString('name="body"', $html);
            $this->assertMatchesRegularExpression('~class="idno-editor-content" id="[^"]+-editor" data-placeholder="[^"]+"~', $html, 'The editor is told its placeholder.');
            $this->assertStringContainsString('name="__bTa" value="/entry/edit"', $html);
        }
    }

}
