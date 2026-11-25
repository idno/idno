<?php

namespace IdnoPlugins\Video\Tests {

    use Idno\Core\Idno;
    use IdnoPlugins\Video\Video;
    use IdnoPlugins\Video\VideoUrl;

    /**
     * Saving a video post from a form, and the markup it is drawn as in both looks. The
     * player is an iframe built from the stored provider and id only; everything a member
     * typed is escaped or cleaned.
     */
    class VideoOutputTest extends \Tests\IdnoTestCase
    {

        /// Entities to remove after each test
        private $toDelete = [];

        private $previousTemplateType;
        private $previousPage;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            // Twenty26 only has templates of the "modern" type; the plugin's own are
            // registered here in case the plugin is not enabled in this configuration.
            \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/IdnoPlugins/Video');
            \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/Themes/Twenty26');
        }

        public function setUp(): void
        {
            $this->previousTemplateType = Idno::site()->template()->getTemplateType();
            $this->previousPage = Idno::site()->currentPage();
            $this->swapUser($this->admin());
        }

        public function tearDown(): void
        {
            Idno::site()->template()->setTemplateType($this->previousTemplateType);
            Idno::site()->setCurrentPage($this->previousPage ?: new \Idno\Pages\Homepage());
            Idno::site()->session()->getAndFlushMessages();
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
        }

        public static function lookProvider(): array
        {
            return [
                'no theme' => ['default'],
                'Twenty26' => ['modern'],
            ];
        }

        private function draw(Video $video, string $templateType, bool $feedView = false): string
        {
            $t = Idno::site()->template();
            $t->setTemplateType($templateType);
            $html = $t->__(['object' => $video, 'feed_view' => $feedView])->draw('entity/Video');
            unset($t->vars['object'], $t->vars['feed_view']);

            return (string) $html;
        }

        /**
         * A video as it would be after a save, without touching the database.
         */
        private function video(string $address, string $title = 'A film', string $body = 'About the film'): Video
        {
            $video = new Video();
            $this->assertTrue($video->setVideoAddress($address), "$address should be accepted.");
            $video->title = $title;
            $video->body = $body;

            return $video;
        }

        private function dom(string $html): \DOMDocument
        {
            $doc = new \DOMDocument();
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="UTF-8"><div id="markup-under-test">' . $html . '</div>');
            libxml_clear_errors();

            return $doc;
        }

        /**
         * Fail if the markup has an element or attribute that runs script, an address that
         * is not http or https, or an iframe that is not a player on an allowed host.
         */
        private function assertMarkupIsSafe(string $html): void
        {
            foreach ($this->dom($html)->getElementById('markup-under-test')->getElementsByTagName('*') as $element) {
                $this->assertNotContains(
                    $element->nodeName,
                    ['script', 'object', 'embed', 'svg', 'style', 'link', 'meta', 'base', 'form'],
                    'Unexpected element in: ' . $html
                );
                foreach ($element->attributes as $attribute) {
                    $name = strtolower($attribute->nodeName);
                    $this->assertStringStartsNotWith('on', $name, "Unexpected attribute \"$name\" in: " . $html);
                    $this->assertNotContains($name, ['srcdoc', 'formaction'], "Unexpected attribute \"$name\" in: " . $html);
                    if (in_array($name, ['href', 'src', 'action'])) {
                        $this->assertMatchesRegularExpression(
                            '~^($|#|/[^/]|https?://)~i',
                            trim($attribute->nodeValue),
                            "The address in $name is not an http or https one: " . $html
                        );
                    }
                }
                // The cleaner of rich text can leave a frame with no address, which loads nothing
                if ($element->nodeName === 'iframe' && $element->hasAttribute('src')) {
                    $src = parse_url($element->getAttribute('src'));
                    $this->assertSame('https', $src['scheme'] ?? '', 'The player must be loaded over https: ' . $html);
                    $this->assertContains($src['host'] ?? '', VideoUrl::EMBED_HOSTS, 'The player must be on an allowed host: ' . $html);
                }
            }
        }

        /**
         * The frames in the markup that load something.
         */
        private function iframes(string $html): array
        {
            return array_values(array_filter(
                iterator_to_array($this->dom($html)->getElementsByTagName('iframe')),
                function ($iframe) {
                    return $iframe->hasAttribute('src');
                }
            ));
        }

        public static function playerProvider(): array
        {
            $addresses = [
                'YouTube'     => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30s', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=30'],
                'Vimeo'       => ['https://vimeo.com/76979871/0a1b2c3d4e', 'https://player.vimeo.com/video/76979871?h=0a1b2c3d4e&dnt=1'],
                'Dailymotion' => ['https://www.dailymotion.com/video/x8abc12', 'https://geo.dailymotion.com/player.html?video=x8abc12'],
                'TikTok'      => ['https://www.tiktok.com/@scout2015/video/6718335390845095173', 'https://www.tiktok.com/player/v1/6718335390845095173'],
            ];
            $cases = [];
            foreach (self::lookProvider() as $lookName => $look) {
                foreach ($addresses as $name => $address) {
                    $cases["$lookName, $name"] = array_merge($look, $address);
                }
            }

            return $cases;
        }

        /**
         * @dataProvider playerProvider
         */
        public function testPlayerIsDrawn($look, $address, $embed)
        {
            $html = $this->draw($this->video($address, 'Fish & chips', 'A <em>short</em> film about #lunch'), $look);

            $this->assertMarkupIsSafe($html);

            $iframes = $this->iframes($html);
            $this->assertCount(1, $iframes, 'There should be one player: ' . $html);
            $iframe = $iframes[0];
            $this->assertSame($embed, $iframe->getAttribute('src'));
            $this->assertSame('lazy', $iframe->getAttribute('loading'));
            $this->assertSame('strict-origin-when-cross-origin', $iframe->getAttribute('referrerpolicy'));
            $this->assertTrue($iframe->hasAttribute('allowfullscreen'));
            $this->assertStringContainsString('Fish & chips', $iframe->getAttribute('title'));
            $allow = array_map('trim', explode(';', $iframe->getAttribute('allow')));
            $this->assertEmpty(
                array_diff(array_filter($allow), ['encrypted-media', 'fullscreen', 'picture-in-picture', 'web-share']),
                'The player is allowed no more than it needs: ' . $iframe->getAttribute('allow')
            );
            $this->assertStringNotContainsString('autoplay', $iframe->getAttribute('allow'));

            // Microformats: a name, the description, and the address of the video
            $this->assertMatchesRegularExpression('~class="[^"]*\bp-name\b[^"]*"~', $html);
            $this->assertStringContainsString('Fish &amp; chips', $html);
            $this->assertMatchesRegularExpression('~class="[^"]*\be-content\b[^"]*"~', $html);
            $this->assertStringContainsString('A <em>short</em> film about', $html);
            $this->assertStringContainsString('lunch</a>', $html, 'Hashtags in the description should be linked.');
            $this->assertMatchesRegularExpression(
                '~<a href="' . preg_quote(htmlspecialchars($address), '~') . '"[^>]*class="[^"]*\bu-video\b[^"]*"~',
                $html,
                'The address of the video should be linked beside the player.'
            );
        }

        /**
         * @dataProvider lookProvider
         */
        public function testPortraitVideoIsMarked($look)
        {
            $portrait = $this->draw($this->video('https://www.youtube.com/shorts/aBc-_123XyZ'), $look);
            $landscape = $this->draw($this->video('https://www.youtube.com/watch?v=dQw4w9WgXcQ'), $look);

            $this->assertStringContainsString('aspect-ratio:9/16', str_replace(' ', '', $portrait));
            $this->assertStringContainsString('aspect-ratio:16/9', str_replace(' ', '', $landscape));
        }

        /**
         * Feeds get the link and the description, not a frame, which readers remove.
         *
         * @dataProvider lookProvider
         */
        public function testFeedViewHasALinkAndNoPlayer($look)
        {
            $html = $this->draw($this->video('https://vimeo.com/76979871', 'A film', 'About the film'), $look, true);

            $this->assertMarkupIsSafe($html);
            $this->assertCount(0, $this->iframes($html));
            $this->assertStringContainsString('href="https://vimeo.com/76979871"', $html);
            $this->assertStringContainsString('About the film', $html);
        }

        public static function hostileTextProvider(): array
        {
            $values = [
                'script in the title'       => ['<script>alert(1)</script>', 'About'],
                'quote in the title'        => ['A film" onmouseover="alert(1)', 'About'],
                'tag closing in the title'  => ['"></iframe><img src=x onerror=alert(1)>', 'About'],
                'script in the description' => ['A film', 'About <script>alert(1)</script>'],
                'handler in description'    => ['A film', 'About <img src="https://example.com/x.png" onerror="alert(1)">'],
                'frame in the description'  => ['A film', 'About <iframe src="https://evil.example/"></iframe>'],
                'link in the description'   => ['A film', 'About <a href="javascript:alert(1)">this</a>'],
            ];
            $cases = [];
            foreach (self::lookProvider() as $lookName => $look) {
                foreach ($values as $name => $value) {
                    $cases["$lookName, $name"] = array_merge($look, $value);
                }
            }

            return $cases;
        }

        /**
         * A record that is already stored with hostile text.
         *
         * @dataProvider hostileTextProvider
         */
        public function testHostileTextIsEscaped($look, $title, $body)
        {
            foreach ([false, true] as $feedView) {
                $html = $this->draw($this->video('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $title, $body), $look, $feedView);

                $this->assertMarkupIsSafe($html);
                $this->assertStringNotContainsString('<script', $html);
                $this->assertStringNotContainsString('evil.example', $html);
                $this->assertCount($feedView ? 0 : 1, $this->iframes($html), 'Only the player may be a frame: ' . $html);
            }
        }

        public static function tamperedProvider(): array
        {
            $values = [
                'unknown provider'      => [['video_provider' => 'evil']],
                'provider is a host'    => [['video_provider' => 'evil.example']],
                'id with markup'        => [['video_id' => '"><script>alert(1)</script>']],
                'id with a handler'     => [['video_id' => 'x" onload="alert(1)']],
                'id is an address'      => [['video_id' => 'https://evil.example/']],
                'id of another service' => [['video_provider' => 'vimeo']],
                'no id'                 => [['video_id' => '']],
            ];
            $cases = [];
            foreach (self::lookProvider() as $lookName => $look) {
                foreach ($values as $name => $value) {
                    $cases["$lookName, $name"] = array_merge($look, $value);
                }
            }

            return $cases;
        }

        /**
         * A stored record whose provider or id is not what the parser would have stored
         * gets no player: the link remains.
         *
         * @dataProvider tamperedProvider
         */
        public function testTamperedRecordHasNoPlayer($look, $changes)
        {
            $video = $this->video('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
            foreach ($changes as $name => $value) {
                $video->$name = $value;
            }

            $html = $this->draw($video, $look);

            $this->assertMarkupIsSafe($html);
            $this->assertCount(0, $this->iframes($html), 'There should be no player: ' . $html);
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('evil.example', $html);
            $this->assertStringContainsString('href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"', $html);
            $this->assertStringContainsString('A film', $html);
        }

        public static function tamperedAddressProvider(): array
        {
            $values = [
                'javascript'       => ['javascript:alert(1)'],
                'quote'            => ['https://example.com/" onmouseover="alert(1)'],
                'tag'              => ['"><script>alert(1)</script>'],
                'data'             => ['data:text/html,<script>alert(1)</script>'],
            ];
            $cases = [];
            foreach (self::lookProvider() as $lookName => $look) {
                foreach ($values as $name => $value) {
                    $cases["$lookName, $name"] = array_merge($look, $value);
                }
            }

            return $cases;
        }

        /**
         * The stored address is only ever printed as an http or https link. The player
         * does not depend on it.
         *
         * @dataProvider tamperedAddressProvider
         */
        public function testTamperedAddressIsNotLinked($look, $address)
        {
            $video = $this->video('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
            $video->video_url = $address;

            foreach ([false, true] as $feedView) {
                $html = $this->draw($video, $look, $feedView);

                $this->assertMarkupIsSafe($html);
                $this->assertStringNotContainsString('<script', $html);
                $this->assertStringNotContainsString('javascript:', $html);
                $this->assertStringNotContainsString('onmouseover="', $html);
            }
            $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $this->iframes($this->draw($video, $look))[0]->getAttribute('src'));
        }

        private function page(array $input): void
        {
            $page = new \Idno\Pages\Homepage();
            foreach ($input + ['access' => 'PUBLIC'] as $name => $value) {
                $page->setInput($name, $value);
            }
            Idno::site()->setCurrentPage($page);
        }

        public function testSavingFromAForm()
        {
            $this->page([
                'video' => ' https://youtu.be/dQw4w9WgXcQ?t=1m30s ',
                'title' => 'Council meeting <b>highlights</b>',
                'body'  => 'Filmed by <em>us</em><script>alert(1)</script> #localnews',
                'tags'  => '#council',
            ]);

            $video = new Video();
            $this->assertTrue($video->saveDataFromInput());
            $this->toDelete[] = $video;

            $saved = Video::getByID($video->getID());
            $this->assertInstanceOf(Video::class, $saved);
            $this->assertSame('https://youtu.be/dQw4w9WgXcQ?t=1m30s', $saved->video_url);
            $this->assertSame('link', $saved->video_source);
            $this->assertSame('youtube', $saved->video_provider);
            $this->assertSame('dQw4w9WgXcQ', $saved->video_id);
            $this->assertEquals(90, $saved->video_start);
            $this->assertSame('Council meeting highlights', $saved->getTitle());
            $this->assertStringContainsString('Filmed by <em>us</em>', $saved->body);
            $this->assertStringNotContainsString('script', $saved->body);
            $this->assertSame('published', $saved->getPublishStatus());
            $this->assertStringContainsString('council-meeting-highlights', $saved->getURL(), 'The permalink should be made from the title.');
            $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=90', $saved->getVideo()->getEmbedUrl());
        }

        public function testSavingWithoutATitle()
        {
            $this->page(['video' => 'https://vimeo.com/76979871', 'body' => 'Just a description']);

            $video = new Video();
            $this->assertTrue($video->saveDataFromInput());
            $this->toDelete[] = $video;

            $this->assertSame('', (string) $video->title);
            $this->assertSame('Video on Vimeo', $video->getTitle());
            foreach (self::lookProvider() as $look) {
                $this->assertCount(1, $this->iframes($this->draw($video, $look[0])));
            }
        }

        public function testSavingAsADraft()
        {
            $this->page(['video' => 'https://vimeo.com/76979871', 'title' => 'Not yet', 'publish_status' => 'draft']);

            $video = new Video();
            $this->assertTrue($video->saveDataFromInput());
            $this->toDelete[] = $video;

            $this->assertSame('draft', $video->getPublishStatus());
        }

        public static function refusedAddressProvider(): array
        {
            return [
                'nothing'         => [''],
                'javascript'      => ['javascript:alert(1)'],
                'another site'    => ['https://example.com/video/1'],
                'look-alike host' => ['https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'],
                'http'            => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ'],
                'markup'          => ['https://www.youtube.com/watch?v="><script>alert(1)</script>'],
            ];
        }

        /**
         * @dataProvider refusedAddressProvider
         */
        public function testAddressThatIsNotRecognisedIsRefused($address)
        {
            $this->page(['video' => $address, 'title' => 'A film']);
            Idno::site()->session()->getAndFlushMessages();

            $video = new Video();
            $this->assertFalse($video->saveDataFromInput());
            $this->assertEmpty($video->getID(), 'Nothing should have been saved.');

            $messages = Idno::site()->session()->getAndFlushMessages();
            $this->assertCount(1, $messages);
            $text = $messages[0]['message'];
            foreach (VideoUrl::supportedServices() as $service) {
                $this->assertStringContainsString($service, $text, 'The message should name the services that can be used.');
            }
            $this->assertStringNotContainsString('<', $text);
        }

        /**
         * Editing a post with an address that is refused leaves the stored video as it was.
         */
        public function testRefusedAddressDoesNotChangeAStoredVideo()
        {
            $this->page(['video' => 'https://vimeo.com/76979871', 'title' => 'A film']);
            $video = new Video();
            $this->assertTrue($video->saveDataFromInput());
            $this->toDelete[] = $video;

            $this->page(['video' => 'https://example.com/nope', 'title' => 'Changed']);
            $this->assertFalse($video->saveDataFromInput());

            $saved = Video::getByID($video->getID());
            $this->assertSame('https://vimeo.com/76979871', $saved->video_url);
            $this->assertSame('A film', $saved->getTitle());
        }

        /**
         * What other servers are sent: a Note with the title, the description and a link.
         */
        public function testFederatedAsANoteWithALink()
        {
            $video = $this->video('https://vimeo.com/76979871?a=1&b=2', 'Fish & chips', 'A <em>short</em> film');

            $this->assertSame('video', $video->getActivityStreamsObjectType());
            $this->assertSame('Note', \IdnoPlugins\ActivityPub\ActivityBuilder::TYPE_MAP[$video->getActivityStreamsObjectType()]);

            $content = $video->getFormattedContent();
            $this->assertStringContainsString('Fish &amp; chips', $content);
            $this->assertStringContainsString('A <em>short</em> film', $content);
            $this->assertStringContainsString('<a href="https://vimeo.com/76979871?a=1&amp;b=2"', $content);
            $this->assertStringNotContainsString('<iframe', $content);
            $this->assertMarkupIsSafe($content);
        }

        public function testFederatedContentOfATamperedRecordIsSafe()
        {
            $video = $this->video('https://vimeo.com/76979871', '<script>alert(1)</script>', 'About <script>alert(2)</script>');
            $video->video_url = 'javascript:alert(3)';

            $content = $video->getFormattedContent();
            $this->assertMarkupIsSafe($content);
            $this->assertStringNotContainsString('<script', $content);
            $this->assertStringNotContainsString('javascript:', $content);
        }

        public function testFeedMetadataCarriesTheLink()
        {
            $video = $this->video('https://vimeo.com/76979871');
            $this->assertSame(['type' => 'video', 'video' => 'https://vimeo.com/76979871'], $video->getMetadataForFeed());

            $video->video_url = 'javascript:alert(1)';
            $this->assertSame(['type' => 'video'], $video->getMetadataForFeed());
        }

        /**
         * The content type is offered in the "new post" chooser, with an icon in both looks.
         */
        public function testContentType()
        {
            $type = new \IdnoPlugins\Video\ContentType();

            $this->assertSame(Video::class, $type->getEntityClass());
            $this->assertSame('videos', $type->getCategoryTitleSlug());
            $this->assertStringEndsWith('/video/edit', $type->getEditURL());
            $this->assertNotSame('', $type->getDescription());

            foreach (self::lookProvider() as $look) {
                Idno::site()->template()->setTemplateType($look[0]);
                $this->assertNotEmpty(trim((string) $type->getIcon()), 'There should be an icon in the ' . $look[0] . ' look.');
            }
            Idno::site()->template()->setTemplateType('modern');
            $icon = Idno::site()->template()->__(['icon' => $type->getIconName()])->draw('shell/icon');
            $fallback = Idno::site()->template()->__(['icon' => 'no-such-icon'])->draw('shell/icon');
            $this->assertNotSame($fallback, $icon, 'Twenty26 should know the icon the chooser asks for.');
        }

        /**
         * @dataProvider lookProvider
         */
        public function testEditFormIsDrawn($look)
        {
            $video = $this->video('https://vimeo.com/76979871?a=1&b=2', 'A "quoted" title', 'About it');
            Idno::site()->template()->setTemplateType($look);
            $html = Idno::site()->template()->__(['object' => $video])->draw('entity/Video/edit');

            $this->assertMatchesRegularExpression('~<input[^>]*name="video"[^>]*value="https://vimeo.com/76979871\?a=1&amp;b=2"|<input[^>]*value="https://vimeo.com/76979871\?a=1&amp;b=2"[^>]*name="video"~', $html);
            $this->assertMatchesRegularExpression('~<input[^>]*name="title"~', $html);
            $this->assertStringContainsString('A &quot;quoted&quot; title', $html);
            $this->assertStringContainsString('name="body"', $html);
            foreach (VideoUrl::supportedServices() as $service) {
                $this->assertStringContainsString($service, $html, 'The form should say which services can be used.');
            }
        }
    }

}
