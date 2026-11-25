<?php

namespace IdnoPlugins\Media\Tests {

    use IdnoPlugins\Media\Media;

    /**
     * The Media plugin in Twenty26: an audio or video file is drawn in the shared media
     * frame, like a post of the Video plugin, and the form is a Twenty26 compose form.
     */
    class ModernTemplatesTest extends \Tests\ModernTemplatesTestCase
    {

        protected function plugin(): string
        {
            return 'Media';
        }

        protected function modernTemplates(): array
        {
            return ['entity/Media', 'entity/Media/edit', 'media/shell/head', 'media/shell/footer'];
        }

        private function media(string $type, string $title = 'An interview', string $url = 'https://media.example/file/abc/interview.mp3'): Media
        {
            $media = new Media();
            $media->title = $title;
            $media->body = '<p>What was said. #audio</p>';
            $media->media_type = $type;
            $media->attachments = [['_id' => '0123456789abcdef0123456789abcdef', 'url' => $url, 'mime-type' => $type, 'length' => 10, 'filename' => 'interview']];
            $media->tags = 'audio';

            return $media;
        }

        public function testAudioIsDrawnInTheMediaFrame()
        {
            $html = $this->drawModern('entity/Media', ['object' => $this->keep($this->media('audio/mpeg'))]);

            $this->assertIsTwenty26Markup($html, 'The audio post');
            $this->assertStringContainsString('class="idno-media-frame"', $html);
            $this->assertStringContainsString('idno-media-player idno-audio-player', $html);
            $this->assertMatchesRegularExpression('~<audio class="u-audio[^>]*controls~', $html, 'The audio element plays without JavaScript.');
            $this->assertStringContainsString('<source src="https://media.example/file/abc/interview.mp3" type="audio/mpeg">', $html);
            $this->assertStringContainsString('x-data="audioPlayer"', $html);
            $this->assertStringContainsString('class="idno-audio-play"', $html);
            $this->assertMatchesRegularExpression('~<input type="range" class="idno-audio-seek"[^>]*aria-label="~', $html, 'The seek bar is a labelled range input.');
            $this->assertStringNotContainsString('WaveSurfer', $html, 'The waveform player needs jQuery, which Twenty26 does not load.');
        }

        public function testVideoIsDrawnInTheMediaFrame()
        {
            $html = $this->drawModern('entity/Media', ['object' => $this->keep($this->media('video/mp4', 'A report', 'https://media.example/file/abc/report.mp4'))]);

            $this->assertIsTwenty26Markup($html, 'The video post');
            $this->assertStringContainsString('class="idno-media-frame"', $html);
            $this->assertStringContainsString('idno-media-player idno-media-player-video', $html);
            $this->assertMatchesRegularExpression('~<video class="u-video[^>]*controls~', $html);
            $this->assertStringContainsString('<source src="https://media.example/file/abc/report.mp4" type="video/mp4">', $html);
            $this->assertStringNotContainsString('<audio', $html);
        }

        public function testThePostHasTheSameOrderAsAVideoPost()
        {
            $html = $this->drawModern('entity/Media', ['object' => $this->keep($this->media('audio/mpeg'))]);

            $title = strpos($html, '<h2 class="idno-entry-title p-name">');
            $frame = strpos($html, 'idno-media-frame');
            $body = strpos($html, 'class="idno-entry-body e-content');
            $tags = strpos($html, 'class="p-category"');

            $this->assertNotFalse($title);
            $this->assertNotFalse($body);
            $this->assertNotFalse($tags);
            $this->assertTrue($title < $frame && $frame < $body && $body < $tags, 'Title, player, description, tags.');
        }

        public function testAPostWithoutATitleHasNoHeading()
        {
            $html = $this->drawModern('entity/Media', ['object' => $this->keep($this->media('audio/mpeg', ''))]);

            $this->assertStringNotContainsString('idno-entry-title', $html);
            $this->assertStringContainsString('idno-audio-player', $html);
        }

        public function testTitleAndAddressesAreEscaped()
        {
            $media = $this->media('audio/mpeg"><script>alert(1)</script>', 'A "quoted" <b>title</b>', 'https://media.example/a"onerror="alert(1).mp3');
            $html = $this->drawModern('entity/Media', ['object' => $this->keep($media)]);

            $this->assertStringNotContainsString('<script>alert(1)', $html);
            $this->assertStringNotContainsString('"onerror="', $html);
            $this->assertStringContainsString('A &quot;quoted&quot; title', $html);

            $media = $this->media('audio/mpeg', 'Not a web address', 'javascript:alert(1)');
            $html = $this->drawModern('entity/Media', ['object' => $this->keep($media)]);
            $this->assertStringNotContainsString('javascript:', $html);
            $this->assertStringNotContainsString('<audio', $html, 'A file whose address is not http or https gets no player.');
        }

        public function testAFeedGetsNoPlayer()
        {
            $html = $this->drawModern('entity/Media', ['object' => $this->keep($this->media('audio/mpeg')), 'feed_view' => true]);

            $this->assertStringNotContainsString('<audio', $html);
            $this->assertStringNotContainsString('idno-entry-title', $html);
            $this->assertStringContainsString('What was said.', $html);
        }

        public function testTheFormIsAComposeForm()
        {
            $html = $this->drawModern('entity/Media/edit', ['object' => new Media()]);

            $this->assertIsComposeForm($html, 'The audio form');
            $this->assertStringContainsString('enctype="multipart/form-data"', $html);
            $this->assertMatchesRegularExpression('~<input[^>]*type="file"[^>]*name="media"[^>]*id="media"~', $html);
            $this->assertMatchesRegularExpression('~<label class="idno-file-input" for="media">~', $html, 'The file control has a label.');
            $this->assertMatchesRegularExpression('~<input[^>]*name="title"[^>]*id="title"~', $html);
            $this->assertStringContainsString('name="body"', $html);
            $this->assertStringContainsString('name="__bTa" value="/media/edit"', $html);
        }

        public function testTheDefaultLookIsUnchanged()
        {
            $html = $this->drawDefault('entity/Media', ['object' => $this->keep($this->media('audio/mpeg'))]);

            $this->assertStringContainsString('waveform-player', $html);
            $this->assertStringNotContainsString('idno-media-frame', $html);
        }
    }

}
