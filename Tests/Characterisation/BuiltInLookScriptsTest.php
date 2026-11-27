<?php

namespace Tests\Characterisation {

    /**
     * The interactive parts of the built-in look that once ran on jQuery, over HTTP on the test site: the markup each
     * script now selects is there, the script refers to no jQuery, and the endpoint it calls answers as the script
     * expects. What a browser does with the script is not run here.
     */
    class BuiltInLookScriptsTest extends CharacterisationTestCase
    {
        use \Tests\Support\LogsIn;

        /** A jQuery call or plugin: what the look no longer loads. */
        private const JQUERY = '~(?<![\w$])\$\s*\(|(?<![\w$])\$\.[A-Za-z_]|\bjQuery\b|mediaelementplayer|\btinymce\b|WaveSurfer~i';

        public function testTheDiagnosticsPageHasTheButtonAndTheReportElementTheScriptSelectsAndAnswersItsRequest(): void
        {
            $cookie = $this->logIn($this->admin());

            $page = $this->site()->request('GET', 'admin/diagnostics/', ['Cookie' => $cookie]);
            $this->assertSame(200, $page->status, substr($page->body, 0, 1500));
            foreach (['id="diagnostics-report-run"', 'id="diagnostics-report"', 'id="diagnostics-report-text"'] as $marker) {
                $this->assertStringContainsString($marker, $page->body);
            }
            $this->assertNoJquery($page->body);
            $this->assertStringContainsString("getElementById('diagnostics-report-run')", $page->body);
            $this->assertMatchesRegularExpression('~fetch\(\'[^\']*admin\\\\/diagnostics~', $page->body, 'the script asks for this page');
            $this->assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $page->body);

            // The request the script makes: the same address, marked as an XMLHttpRequest, answers the report as text.
            $report = $this->site()->request('GET', 'admin/diagnostics/', ['Cookie' => $cookie, 'X-Requested-With' => 'XMLHttpRequest']);
            $this->assertSame(200, $report->status);
            $this->assertStringStartsWith('Idno Diagnostics: Version', $report->body);
            $this->assertStringContainsString('Running config:', $report->body);
            $this->assertStringNotContainsString('<html', $report->body);
        }

        public function testTheEditFooterAndAnEditPageLoadNoJqueryAndKeepTheirSubmitButtons(): void
        {
            $cookie = $this->logIn($this->user());

            $page = $this->site()->request('GET', 'status/edit', ['Cookie' => $cookie]);
            $this->assertSame(200, $page->status, substr($page->body, 0, 1500));
            $this->assertNoJquery($page->body);
            $this->assertStringContainsString('id="submit-spinner"', $page->body);
            $this->assertStringContainsString('data-saving', $page->body, 'the footer guards the form it follows');
            $this->assertStringNotContainsString('form[method=', $page->body, 'and not every post form of the page');
            $this->assertStringContainsString('setTimeout', $page->body, 'the guard is lifted after a while');
            $this->assertStringContainsString("'pageshow'", $page->body);
            $this->assertMatchesRegularExpression('~<button type="submit"[^>]*>~', $page->body, 'the script guards the form a submit button sends');
            $this->assertStringNotContainsString('mediaelement', $page->body, 'no media player library is loaded on any page');
            $this->assertStringNotContainsString('wavesurfer', $page->body);
            $this->assertStringNotContainsString('exif-js', $page->body);
            $this->assertStringNotContainsString('fitvids', $page->body);
        }

        public function testARequiredRichTextFieldIsCheckedByTheFooterNotByTheBrowser(): void
        {
            $cookie = $this->logIn($this->user());

            $page = $this->site()->request('GET', 'entry/edit', ['Cookie' => $cookie]);
            $this->assertSame(200, $page->status, substr($page->body, 0, 1500));
            $this->assertSame(1, preg_match('~<textarea name="body"[^>]*>~', $page->body, $m), 'the editor\'s field');
            $this->assertStringContainsString('data-required="true"', $m[0]);
            $this->assertDoesNotMatchRegularExpression('~\srequired[\s>=]~', $m[0], 'a hidden field with the required attribute cannot take focus: the browser would refuse the form with an error');
            $this->assertStringContainsString("textarea[data-required]", $page->body);

            // Without JavaScript the server refuses a post with no body and stores nothing.
            $token = $this->formToken($page->body, 'entry/edit/');
            $title = 'Empty body ' . bin2hex(random_bytes(4));
            $post  = $this->site()->request('POST', 'entry/edit/', ['Cookie' => $cookie], $token + ['title' => $title, 'body' => '']);
            $this->assertContains($post->status, [200, 302]);
            \Idno\Common\Entity::clearEntityCache();
            $this->assertFalse(\IdnoPlugins\Text\Entry::getOne(['title' => $title]), 'the server stores no article with an empty body');
        }

        public function testTheMediaEditFormHasTheCardAndButtonsOfTheOtherContentForms(): void
        {
            $cookie = $this->logIn($this->user());

            $media  = $this->site()->request('GET', 'media/edit', ['Cookie' => $cookie]);
            $status = $this->site()->request('GET', 'status/edit', ['Cookie' => $cookie]);
            $this->assertSame(200, $media->status);
            foreach (['idno-editor', 'idno-btn idno-btn-primary', 'idno-btn idno-btn-ghost'] as $class) {
                $this->assertStringContainsString($class, $media->body, $class);
                $this->assertStringContainsString($class, $status->body, 'the class is the one the other forms use: ' . $class);
            }
            $this->assertStringContainsString('image-file-input', $media->body, 'the native file field is hidden behind its label button');
            $this->assertStringNotContainsString('btn btn-primary', $media->body);
        }

        public function testTheMediaEditFormShowsTheChosenFileWithoutJquery(): void
        {
            $cookie = $this->logIn($this->user());

            $page = $this->site()->request('GET', 'media/edit', ['Cookie' => $cookie]);
            $this->assertSame(200, $page->status, substr($page->body, 0, 1500));
            $this->assertNoJquery($page->body);
            $this->assertStringContainsString('id="media-filename"', $page->body);
            $this->assertMatchesRegularExpression('~<input[^>]*type="file"[^>]*name="media"[^>]*onchange="[^"]*media-filename~s', $page->body);
            $this->assertMatchesRegularExpression('~<form action="[^"]*media/edit/?" method="post" enctype="multipart/form-data">~', $page->body);
            $this->assertStringContainsString('name="__bTs"', $page->body);
            $this->assertStringNotContainsString('hideContentCreateForm', $page->body, 'the cancel button is a link');
        }

        /**
         * @return iterable<string, array{string, string, string}>
         */
        public static function mediaFiles(): iterable
        {
            yield 'audio' => ['audio/mpeg', 'sound.mp3', '<audio'];
            yield 'video' => ['video/mp4', 'clip.mp4', '<video'];
        }

        /**
         * @dataProvider mediaFiles
         */
        public function testAMediaPostPlaysInTheBrowsersOwnPlayerFromTheFileRoute(string $mime, string $name, string $element): void
        {
            $base = $this->site()->baseUrl();
            $url  = $base . 'file/00000000000040008000000000000022/' . $name;

            $media = new \IdnoPlugins\Media\Media();
            $this->registerFixture($media);
            $media->title       = 'A recording';
            $media->body        = 'Listen';
            $media->media_type  = $mime;
            $media->attachments = [['url' => $url, 'filename' => $name, '_id' => '22', 'mime-type' => $mime]];
            $media->setOwner($this->user());
            $media->setAccess('PUBLIC');
            $media->setPublishStatus('published');
            $media->save(true);

            $page = $this->site()->request('GET', $this->path($media->getURL()));
            $this->assertSame(200, $page->status, substr($page->body, 0, 1500));
            $this->assertNoJquery($page->body);
            $this->assertStringContainsString($element, $page->body);
            $this->assertStringContainsString(' controls', $page->body);
            $this->assertStringContainsString('src="' . $url . '"', $page->body, 'the file is the site\'s file route');
            $this->assertStringNotContainsString('waveform', $page->body);
            $this->assertStringNotContainsString('mediaelement', $page->body);
            $this->assertStringNotContainsString('audio-play-button', $page->body);
        }

        public function testAStaticPageIsSavedExactlyAsTheAdministratorWroteItAndOpensAsSource(): void
        {
            $cookie = $this->logIn($this->admin());

            $edit = $this->site()->request('GET', 'staticpages/edit', ['Cookie' => $cookie]);
            $this->assertSame(200, $edit->status, substr($edit->body, 0, 1500));
            $this->assertNoJquery($edit->body);
            $this->assertMatchesRegularExpression('~<textarea name="body" id="body"~', $edit->body, 'the body is edited as source');
            $this->assertStringNotContainsString('x-data="tiptapEditor', $edit->body, 'no editor that rewrites markup it does not know');
            $this->assertStringContainsString('id="moreoptions"', $edit->body);
            $this->assertStringContainsString('x-show="open"', $edit->body);
            $this->assertStringNotContainsString('hideContentCreateForm', $edit->body);

            $body = "<form action=\"/x\" method=\"post\" id=\"contact\">\n<input name=\"a\" value='1 & 2'>\n</form>\n"
                . "<style>#contact { color: red; }</style>\n"
                . "<iframe src=\"https://example.org/embed\" id=\"frame\" width=\"300\"></iframe>\n"
                . "<p id=\"intro\" class=\"lead\">Caf\u{e9} &amp; <b>tea</b></p>\n</textarea><!-- a stray end tag -->";

            $token = $this->formToken($edit->body, 'staticpage/edit');
            $title = 'Source page ' . bin2hex(random_bytes(4));
            $post  = $this->site()->request('POST', 'staticpage/edit', ['Cookie' => $cookie], $token + [
                'title'      => $title,
                'body'       => $body,
                'category'   => 'No Category',
                'hide_title' => '0',
                'access'     => 'PUBLIC',
            ]);
            $this->assertContains($post->status, [200, 302], substr($post->body, 0, 1500));

            \Idno\Common\Entity::clearEntityCache();
            $stored = \IdnoPlugins\StaticPages\StaticPage::getOne(['title' => $title]);
            $this->assertInstanceOf(\IdnoPlugins\StaticPages\StaticPage::class, $stored, 'the page was saved');
            $this->registerFixture($stored);
            $this->assertSame($body, $stored->body, 'the body is stored byte for byte as it was posted');

            // Opened again, the source is the textarea's text: what a browser puts in the field is the body.
            $again = $this->site()->request('GET', 'staticpages/edit/' . $stored->getID(), ['Cookie' => $cookie]);
            $this->assertSame(200, $again->status, substr($again->body, 0, 1500));
            $this->assertSame(1, preg_match('~<textarea name="body" id="body"[^>]*>\n(.*?)</textarea>~s', $again->body, $m), 'one body field');
            $this->assertSame($body, html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        public function testNoPageLoadsJqueryOrALibraryThatNeedsIt(): void
        {
            foreach (['', 'content/all'] as $path) {
                $page = $this->site()->request('GET', $path);
                $this->assertSame(200, $page->status, $path);
                preg_match_all('~<script[^>]*\ssrc="([^"]+)"~', $page->body, $m);
                $this->assertContains($this->site()->baseUrl() . 'js/idno-2026.min.js', $m[1], $path);
                foreach ($m[1] as $src) {
                    $this->assertDoesNotMatchRegularExpression('~jquery|mediaelement|wavesurfer|exif|fitvids|tinymce~i', $src, 'a script that needs jQuery or is unused: ' . $src);
                }
            }
        }

        private function assertNoJquery(string $html): void
        {
            // Scripts only: the page's own text may say the word.
            preg_match_all('~<script\b[^>]*>(.*?)</script>~is', $html, $scripts);
            foreach ($scripts[1] as $script) {
                $this->assertSame(0, preg_match(self::JQUERY, $script, $hit), 'a script calls jQuery or a plugin of it: ' . ($hit[0] ?? ''));
            }
            preg_match_all('~\son[a-z]+="([^"]*)"~i', $html, $handlers);
            foreach ($handlers[1] as $handler) {
                $this->assertSame(0, preg_match(self::JQUERY, html_entity_decode($handler), $hit), 'an inline handler calls jQuery: ' . ($hit[0] ?? ''));
            }
        }

    }

}
