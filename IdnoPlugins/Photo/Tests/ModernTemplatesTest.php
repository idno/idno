<?php

namespace IdnoPlugins\Photo\Tests {

    use IdnoPlugins\Photo\Photo;

    /**
     * Photos in Twenty26: the pictures of a post sit in a grid, and the form is a
     * Twenty26 compose form.
     */
    class ModernTemplatesTest extends \Tests\ModernTemplatesTestCase
    {

        protected function plugin(): string
        {
            return 'Photo';
        }

        protected function modernTemplates(): array
        {
            return ['entity/Photo', 'entity/Photo/edit'];
        }

        private function photo(int $pictures): Photo
        {
            $photo = new Photo();
            $photo->title = 'Senate "night"';
            $photo->body = '<p>Four frames.</p>';
            $attachments = [];
            for ($n = 1; $n <= $pictures; $n++) {
                $attachments[] = ['_id' => str_repeat((string) $n, 32), 'url' => "https://photos.example/file/$n/picture$n.jpg", 'mime-type' => 'image/jpeg', 'length' => 10, 'filename' => "picture$n.jpg"];
            }
            $photo->attachments = $attachments;

            return $photo;
        }

        public function testOnePictureFillsThePost()
        {
            $html = $this->drawModern('entity/Photo', ['object' => $this->keep($this->photo(1))]);

            $this->assertIsTwenty26Markup($html, 'The photo post');
            $this->assertStringContainsString('<h2 class="idno-entry-title p-name">', $html);
            $this->assertMatchesRegularExpression('~<div class="idno-photo-grid\s*">~', $html);
            $this->assertSame(1, substr_count($html, 'class="u-photo"'));
            $this->assertStringContainsString('alt="Senate &quot;night&quot;"', $html);
            $this->assertStringContainsString('loading="lazy"', $html);
        }

        public function testSeveralPicturesShareAGrid()
        {
            $html = $this->drawModern('entity/Photo', ['object' => $this->keep($this->photo(4))]);

            $this->assertStringContainsString('<div class="idno-photo-grid multiple-images">', $html);
            $this->assertSame(4, substr_count($html, 'class="u-photo"'));
            $this->assertSame(4, substr_count($html, 'class="idno-photo-view"'));

            // The pictures are inside the grid and the caption after it
            $grid = strpos($html, 'idno-photo-grid');
            $caption = strrpos($html, 'Four frames.');
            $this->assertTrue($grid < strrpos($html, 'u-photo') && strrpos($html, 'u-photo') < $caption);
        }

        public function testTheFormIsAComposeForm()
        {
            $html = $this->drawModern('entity/Photo/edit', ['object' => new Photo()]);

            $this->assertIsComposeForm($html, 'The photo form');
            $this->assertStringContainsString('enctype="multipart/form-data"', $html);
            $this->assertStringContainsString('name="photo[]"', $html);
            $this->assertMatchesRegularExpression('~<input[^>]*name="title"[^>]*id="title"~', $html);
            $this->assertStringContainsString('class="idno-editor-field-label"', $html, 'The description has its label.');
            $this->assertStringContainsString('name="__bTa" value="/photo/edit"', $html);
        }

        public function testTheFormNeedsNoScriptThatTwenty26DoesNotLoad()
        {
            $html = $this->drawModern('entity/Photo/edit', ['object' => new Photo()]);

            // Twenty26 loads neither jQuery nor the default look's Template object: a call to
            // either stops the script that shows the chosen photo
            preg_match_all('~<script\b[^>]*>(.*?)</script>~s', $html, $scripts);
            $this->assertNotEmpty($scripts[1], 'The form shows the chosen photo with a script of its own.');
            foreach ($scripts[1] as $script) {
                $this->assertStringNotContainsString('$(', $script);
                $this->assertStringNotContainsString('jQuery', $script);
                $this->assertStringNotContainsString('Template.', $script);
            }
            $this->assertDoesNotMatchRegularExpression('~\son[a-z]+="[^"]*(Template\.|\$\(|jQuery)~', $html, 'No inline handler calls them either.');

            // The file inputs are inside the form, with the name the server reads
            $form = substr($html, 0, (int) strpos($html, '</form>'));
            $this->assertSame(10, preg_match_all('~<input\b[^>]*type="file"[^>]*>~', $form, $inputs));
            foreach ($inputs[0] as $input) {
                $this->assertStringContainsString('name="photo[]"', $input);
                $this->assertStringContainsString('accept="' . \Idno\Core\Upload::imageAccept() . '"', $input);
            }
            $this->assertStringContainsString('data-upload-limits', $form, 'The form states what the server accepts.');
            $this->assertStringContainsString('data-upload-error', $form);
        }
    }

}
