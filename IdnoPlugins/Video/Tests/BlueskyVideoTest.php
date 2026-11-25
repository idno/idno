<?php

namespace IdnoPlugins\Video\Tests {

    use Idno\Core\Idno;
    use IdnoPlugins\Video\OpenVideo;
    use IdnoPlugins\Video\Video;
    use IdnoPlugins\Video\VideoUrl;

    /**
     * A video in a Bluesky post (AT Protocol). The address of the post does not hold what a
     * player needs, so Bluesky's public API is asked, once, when the post is saved. The
     * video is then played by the browser itself from Bluesky's video host: no frame.
     */
    class BlueskyVideoTest extends \Tests\IdnoTestCase
    {

        const DID = 'did:plc:z72i7hdynmk6r22z27h6tvur';
        const RKEY = '3n2opitysuc2j';
        const CID = 'bafkreiapzmyzxc72yow7zhko4fgykat7ppbcvnz4wv7okwcwnesbasy5um';
        const API = 'https://public.api.bsky.app/xrpc/';

        private $toDelete = [];
        private $previousTemplateType;
        private $previousPage;
        private $asked = [];

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/IdnoPlugins/Video');
            \Idno\Core\Bonita\Main::additionalPath(Idno::site()->config()->path . '/Themes/Twenty26');
        }

        public function setUp(): void
        {
            $this->previousTemplateType = Idno::site()->template()->getTemplateType();
            $this->previousPage = Idno::site()->currentPage();
            $this->swapUser($this->admin());
            $this->answer(['embed' => $this->videoEmbed()]);
        }

        public function tearDown(): void
        {
            OpenVideo::$fetch = null;
            Idno::site()->template()->setTemplateType($this->previousTemplateType);
            Idno::site()->setCurrentPage($this->previousPage ?: new \Idno\Pages\Homepage());
            Idno::site()->session()->getAndFlushMessages();
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
        }

        private function videoEmbed(array $changes = []): array
        {
            return $changes + [
                '$type'       => 'app.bsky.embed.video#view',
                'cid'         => self::CID,
                'playlist'    => 'https://video.bsky.app/watch/did%3Aplc%3Az72i7hdynmk6r22z27h6tvur/' . self::CID . '/playlist.m3u8',
                'thumbnail'   => 'https://video.bsky.app/watch/did%3Aplc%3Az72i7hdynmk6r22z27h6tvur/' . self::CID . '/thumbnail.jpg',
                'aspectRatio' => ['width' => 1080, 'height' => 1620],
            ];
        }

        /**
         * Bluesky as it would answer: the handle bsky.app, and one post.
         *
         * @param array $post Changes to the post that is returned; ['posts' => ...] replaces the whole answer
         */
        private function answer(array $post): void
        {
            $uri = 'at://' . self::DID . '/app.bsky.feed.post/' . self::RKEY;
            $posts = $post['posts'] ?? [$post + ['uri' => $uri, 'record' => ['text' => 'A <b>new</b> share sheet']]];
            $pages = [
                self::API . 'com.atproto.identity.resolveHandle?handle=bsky.app' => ['did' => self::DID],
                self::API . 'app.bsky.feed.getPosts?uris=' . rawurlencode($uri) => ['posts' => $posts],
            ];
            $this->asked = [];
            OpenVideo::$fetch = function (string $url) use ($pages) {
                $this->asked[] = $url;

                return isset($pages[$url]) ? ['response' => 200, 'content' => json_encode($pages[$url])] : ['response' => 400, 'content' => '{"error":"InvalidRequest"}'];
            };
        }

        public static function candidates(): array
        {
            return [
                'handle'           => ['https://bsky.app/profile/bsky.app/post/' . self::RKEY, 'bsky.app'],
                'custom domain'    => ['https://bsky.app/profile/news.example.org/post/' . self::RKEY . '?ref=share', 'news.example.org'],
                'DID'              => ['https://bsky.app/profile/' . self::DID . '/post/' . self::RKEY, self::DID],
                'upper-case handle' => ['https://bsky.app/profile/Bsky.App/post/' . self::RKEY, 'bsky.app'],
            ];
        }

        /**
         * @dataProvider candidates
         */
        public function testCandidate($address, $actor)
        {
            $this->assertSame(
                ['provider' => 'bluesky', 'host' => 'bsky.app', 'id' => self::RKEY, 'start' => 0, 'actor' => $actor],
                VideoUrl::candidate($address)
            );
            $this->assertNull(VideoUrl::parse($address));
        }

        public static function notCandidates(): array
        {
            return [
                'a profile'              => ['https://bsky.app/profile/bsky.app'],
                'a feed'                 => ['https://bsky.app/profile/bsky.app/feed/whats-hot'],
                'http'                   => ['http://bsky.app/profile/bsky.app/post/' . self::RKEY],
                'look-alike host'        => ['https://bsky.app.evil.example/profile/bsky.app/post/' . self::RKEY],
                'handle with a quote'    => ['https://bsky.app/profile/bsky"app/post/' . self::RKEY],
                'handle is an address'   => ['https://bsky.app/profile/127.0.0.1/post/' . self::RKEY],
                'DID of another method'  => ['https://bsky.app/profile/did:evil:abc/post/' . self::RKEY],
                'DID too short'          => ['https://bsky.app/profile/did:plc:abc/post/' . self::RKEY],
                'record key with markup' => ['https://bsky.app/profile/bsky.app/post/<script>'],
                'record key too long'    => ['https://bsky.app/profile/bsky.app/post/' . str_repeat('a', 40)],
                'record key, traversal'  => ['https://bsky.app/profile/bsky.app/post/..'],
                'extra path'             => ['https://bsky.app/profile/bsky.app/post/' . self::RKEY . '/liked-by'],
            ];
        }

        /**
         * @dataProvider notCandidates
         */
        public function testNotACandidate($address)
        {
            $this->assertNull(VideoUrl::candidate($address));
        }

        public function testVideoPostIsFound()
        {
            $found = OpenVideo::resolve(VideoUrl::candidate('https://bsky.app/profile/bsky.app/post/' . self::RKEY));

            $this->assertCount(2, $this->asked, 'The handle is resolved, then the post is asked for.');
            foreach ($this->asked as $url) {
                $this->assertStringStartsWith(self::API, $url, 'Only Bluesky\'s public API is asked.');
            }
            $video = $found['video'];
            $this->assertSame('bluesky', $video->getProvider());
            $this->assertSame('Bluesky', $video->getProviderName());
            $this->assertSame(self::RKEY, $video->getId());
            $this->assertTrue($video->isNative());
            $this->assertFalse($video->isOpen());
            $this->assertSame('', $video->getEmbedUrl(), 'There is no frame for a Bluesky video.');
            $this->assertSame('https://video.bsky.app/watch/did%3Aplc%3Az72i7hdynmk6r22z27h6tvur/' . self::CID . '/playlist.m3u8', $video->getStreamUrl());
            $this->assertSame('https://video.bsky.app/watch/did%3Aplc%3Az72i7hdynmk6r22z27h6tvur/' . self::CID . '/thumbnail.jpg', $video->getPosterUrl());
            $this->assertTrue($video->isPortrait());
            $this->assertEqualsWithDelta(0.6667, $video->getRatio(), 0.001);
            $this->assertSame('', $found['title']);
        }

        public function testAddressWithADidNeedsOneRequest()
        {
            $found = OpenVideo::resolve(VideoUrl::candidate('https://bsky.app/profile/' . self::DID . '/post/' . self::RKEY));

            $this->assertInstanceOf(VideoUrl::class, $found['video']);
            $this->assertCount(1, $this->asked);
        }

        public function testVideoBesideAQuotedPostIsFound()
        {
            $this->answer(['embed' => ['$type' => 'app.bsky.embed.recordWithMedia#view', 'record' => ['x' => 1], 'media' => $this->videoEmbed(['aspectRatio' => ['width' => 1920, 'height' => 1080]])]]);

            $found = OpenVideo::resolve(VideoUrl::candidate('https://bsky.app/profile/bsky.app/post/' . self::RKEY));

            $this->assertFalse($found['video']->isPortrait());
            $this->assertEqualsWithDelta(1.7778, $found['video']->getRatio(), 0.001);
        }

        public static function badAnswers(): array
        {
            $video = [
                '$type' => 'app.bsky.embed.video#view',
                'cid'   => self::CID,
            ];
            $uri = 'at://' . self::DID . '/app.bsky.feed.post/' . self::RKEY;

            return [
                'no such post'          => [['posts' => []]],
                'a post with no video'  => [['embed' => ['$type' => 'app.bsky.embed.images#view', 'images' => []]]],
                'a post with no embed'  => [[]],
                'another post'          => [['posts' => [['uri' => 'at://' . self::DID . '/app.bsky.feed.post/3aaaaaaaaaaaa', 'embed' => $video]]]],
                'cid with markup'       => [['embed' => ['cid' => '"><script>alert(1)</script>'] + $video]],
                'cid is a path'         => [['embed' => ['cid' => '../../x'] + $video]],
                'cid is a list'         => [['embed' => ['cid' => [self::CID]] + $video]],
                'posts is a string'     => [['posts' => 'none']],
            ];
        }

        /**
         * @dataProvider badAnswers
         */
        public function testAnswerWithoutAVideoIsRefused($post)
        {
            $this->answer($post);

            $this->assertNull(OpenVideo::resolve(VideoUrl::candidate('https://bsky.app/profile/bsky.app/post/' . self::RKEY)));
        }

        public function testAHandleThatDoesNotResolveIsRefused()
        {
            $this->assertNull(OpenVideo::resolve(VideoUrl::candidate('https://bsky.app/profile/nobody.example.org/post/' . self::RKEY)));
            $this->assertCount(1, $this->asked, 'The post is not asked for.');
        }

        public function testANonsenseAspectRatioIsIgnored()
        {
            foreach ([['width' => 0, 'height' => 100], ['width' => '"><script>', 'height' => 1], 'wide', ['width' => 100000, 'height' => 1]] as $ratio) {
                $this->answer(['embed' => $this->videoEmbed(['aspectRatio' => $ratio])]);
                $video = OpenVideo::resolve(VideoUrl::candidate('https://bsky.app/profile/bsky.app/post/' . self::RKEY))['video'];
                $this->assertGreaterThanOrEqual(0.4, $video->getRatio());
                $this->assertLessThanOrEqual(3.0, $video->getRatio());
            }
        }

        public static function tamperedStoredValues(): array
        {
            return [
                'DID with markup'      => [['did' => '"><script>alert(1)</script>', 'cid' => self::CID]],
                'DID with a path'      => [['did' => 'did:plc:z72i7hdynmk6r22z27h6tvur/../../x', 'cid' => self::CID]],
                'DID of another kind'  => [['did' => 'did:evil:z72i7hdynmk6r22z27h6tvur', 'cid' => self::CID]],
                'no DID'               => [['cid' => self::CID]],
                'cid with a quote'     => [['did' => self::DID, 'cid' => 'bafkrei"onerror="alert(1)']],
                'cid is an address'    => [['did' => self::DID, 'cid' => 'https://evil.example/x.m3u8']],
                'no cid'               => [['did' => self::DID]],
            ];
        }

        /**
         * @dataProvider tamperedStoredValues
         */
        public function testTamperedStoredValuesAreRefused($more)
        {
            $this->assertNull(VideoUrl::fromStored('bluesky', self::RKEY, 0, '', false, '', $more));
        }

        public function testStoredValuesGiveTheSameVideo()
        {
            $video = VideoUrl::fromStored('bluesky', self::RKEY, 0, '', true, '', ['did' => self::DID, 'cid' => self::CID, 'ratio' => 0.6667]);

            $this->assertSame('https://video.bsky.app/watch/did%3Aplc%3Az72i7hdynmk6r22z27h6tvur/' . self::CID . '/playlist.m3u8', $video->getStreamUrl());
            $this->assertNull(VideoUrl::fromStored('bluesky', self::RKEY, 0, '', true, 'evil.example', ['did' => self::DID, 'cid' => self::CID]), 'A Bluesky video has no host of its own.');
            $this->assertNull(VideoUrl::fromStored('youtube', 'dQw4w9WgXcQ', 0, '', false, '', ['did' => self::DID, 'cid' => self::CID]), 'Only a Bluesky video has these.');
        }

        private function page(array $input): void
        {
            $page = new \Idno\Pages\Homepage();
            foreach ($input + ['access' => 'PUBLIC'] as $name => $value) {
                $page->setInput($name, $value);
            }
            Idno::site()->setCurrentPage($page);
        }

        private function draw(Video $video, string $templateType, bool $feedView = false): string
        {
            $t = Idno::site()->template();
            $t->setTemplateType($templateType);
            $html = $t->__(['object' => $video, 'feed_view' => $feedView])->draw('entity/Video');
            unset($t->vars['object'], $t->vars['feed_view']);

            return (string) $html;
        }

        private function dom(string $html): \DOMDocument
        {
            $doc = new \DOMDocument();
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>');
            libxml_clear_errors();

            return $doc;
        }

        public static function lookProvider(): array
        {
            return [
                'no theme' => ['default'],
                'Twenty26' => ['modern'],
            ];
        }

        /**
         * @dataProvider lookProvider
         */
        public function testPostingAndDrawingABlueskyVideo($look)
        {
            // Open services being off does not matter: Bluesky's hosts are known
            $previous = Idno::site()->config()->video_open_services;
            Idno::site()->config()->video_open_services = false;
            $address = 'https://bsky.app/profile/bsky.app/post/' . self::RKEY;
            $this->page(['video' => $address, 'title' => 'Fish & chips', 'body' => 'From Bluesky']);

            $video = new Video();
            try {
                $this->assertTrue($video->saveDataFromInput());
            } finally {
                Idno::site()->config()->video_open_services = $previous;
            }
            $this->toDelete[] = $video;

            $saved = Video::getByID($video->getID());
            $this->assertSame('bluesky', $saved->video_provider);
            $this->assertSame(self::RKEY, $saved->video_id);
            $this->assertSame(self::DID, $saved->video_did);
            $this->assertSame(self::CID, $saved->video_cid);

            $html = $this->draw($saved, $look);
            $doc = $this->dom($html);

            $this->assertCount(0, $doc->getElementsByTagName('iframe'), 'A Bluesky video is not framed.');
            $videos = $doc->getElementsByTagName('video');
            $this->assertCount(1, $videos);
            $player = $videos[0];
            $this->assertSame($saved->getVideo()->getStreamUrl(), $player->getAttribute('src'));
            $this->assertSame($saved->getVideo()->getPosterUrl(), $player->getAttribute('poster'));
            $this->assertSame('video.bsky.app', parse_url($player->getAttribute('src'), PHP_URL_HOST));
            $this->assertTrue($player->hasAttribute('controls'));
            $this->assertTrue($player->hasAttribute('playsinline'));
            $this->assertFalse($player->hasAttribute('autoplay'));
            $this->assertSame('none', $player->getAttribute('preload'), 'Nothing but the picture is fetched until a visitor presses play.');
            $this->assertStringContainsString('Fish & chips', $player->getAttribute('title'));
            foreach ($player->attributes as $attribute) {
                $this->assertStringStartsNotWith('on', strtolower($attribute->nodeName));
            }
            $this->assertStringContainsString('Watch on Bluesky', $html);
            $this->assertMatchesRegularExpression('~<a href="' . preg_quote($address, '~') . '"[^>]*class="[^"]*\bu-video\b~', $html);

            // Feeds get the link alone
            $feed = $this->draw($saved, $look, true);
            $this->assertStringNotContainsString('<video', $feed);
            $this->assertStringNotContainsString('<script', $feed);
            $this->assertStringContainsString('href="' . $address . '"', $feed);
        }

        /**
         * @dataProvider lookProvider
         */
        public function testTamperedBlueskyRecordHasNoPlayer($look)
        {
            $video = new Video();
            $this->assertTrue($video->setVideoAddress('https://bsky.app/profile/bsky.app/post/' . self::RKEY));
            $video->title = 'A film';

            foreach ([['video_cid' => '"><script>alert(1)</script>'], ['video_did' => 'did:plc:x" onerror="alert(1)'], ['video_cid' => 'https://evil.example/x']] as $changes) {
                $copy = clone $video;
                foreach ($changes as $name => $value) {
                    $copy->$name = $value;
                }
                $html = $this->draw($copy, $look);
                $this->assertStringNotContainsString('<video', $html);
                $this->assertStringNotContainsString('alert(1)', $html);
                $this->assertStringNotContainsString('evil.example', $html);
                $this->assertStringContainsString('href="https://bsky.app/profile/bsky.app/post/' . self::RKEY . '"', $html);
            }
        }

        public function testAPostWithNoVideoIsRefusedWithAReason()
        {
            $this->answer(['embed' => ['$type' => 'app.bsky.embed.images#view']]);
            $this->page(['video' => 'https://bsky.app/profile/bsky.app/post/' . self::RKEY, 'title' => 'A film']);

            $video = new Video();
            $this->assertFalse($video->saveDataFromInput());

            $message = Idno::site()->session()->getAndFlushMessages()[0]['message'];
            $this->assertStringContainsString('Bluesky', $message);
            $this->assertStringNotContainsString('<', $message);
        }

        public function testBlueskyIsNamedAmongTheServices()
        {
            $this->assertContains('Bluesky', VideoUrl::supportedServices());
            $this->assertContains('Bluesky', Video::serviceNames());
        }
    }

}
