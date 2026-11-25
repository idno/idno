<?php

namespace IdnoPlugins\Status\Tests {

    use IdnoPlugins\Status\Status;

    /**
     * Status updates in Twenty26.
     */
    class ModernTemplatesTest extends \Tests\ModernTemplatesTestCase
    {

        protected function plugin(): string
        {
            return 'Status';
        }

        protected function modernTemplates(): array
        {
            return ['entity/Status', 'entity/Status/edit', 'entity/Reply'];
        }

        public function testTheTagsFollowTheTextAndAreNotInsideIt()
        {
            $status = new Status();
            $status->body = "First line\nSecond <line> #senate";
            $status->tags = 'budget & fees';
            $html = $this->drawModern('entity/Status', ['object' => $this->keep($status)]);

            $this->assertIsTwenty26Markup($html, 'The status update');
            $this->assertMatchesRegularExpression('~<p class="p-name e-content entry-content">First line<br />\s*Second &lt;line&gt; <a[^>]*>#senate</a></p>~', $html);

            // The tag row is a paragraph of its own: inside the text, each of its lines became a line break
            $this->assertMatchesRegularExpression('~</p>\s*<p class="idno-tags tag-row">\s*<a href="[^"]*tag/budget\+%26\+fees" class="p-category" rel="tag">budget &amp; fees</a>\s*</p>~', $html);
        }

        public function testTheFormIsAComposeForm()
        {
            $html = $this->drawModern('entity/Status/edit', ['object' => new Status()]);

            $this->assertIsComposeForm($html, 'The status form');
            $this->assertMatchesRegularExpression('~<textarea name="body"[^>]*id="body"~', $html);
            $this->assertStringContainsString('name="inreplyto[]"', $html);
            $this->assertStringContainsString('name="__bTa" value="/status/edit"', $html);
        }
    }

}
