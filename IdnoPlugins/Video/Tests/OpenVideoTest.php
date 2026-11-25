<?php

namespace IdnoPlugins\Video\Tests {

    use IdnoPlugins\Video\OpenVideo;
    use IdnoPlugins\Video\VideoUrl;
    use PHPUnit\Framework\TestCase;

    /**
     * PeerTube and Loops run on any host, so the address alone cannot say that a host is one
     * of them. VideoUrl::candidate() reads what the address would mean; OpenVideo::resolve()
     * asks the host, and only an answer in the right form gives a video.
     */
    class OpenVideoTest extends TestCase
    {

        const UUID = '913b145c-ab72-4bea-a2a0-6ee692616414';
        const SHORT = 'iWa2a6GGoZqnLinqgeaYR3';

        /// The addresses asked for, in order
        private $asked = [];

        protected function tearDown(): void
        {
            OpenVideo::$fetch = null;
        }

        /**
         * Answer requests from a table of address => [status, body]; anything else is a 404.
         */
        private function answer(array $pages): void
        {
            $this->asked = [];
            OpenVideo::$fetch = function (string $url) use ($pages) {
                $this->asked[] = $url;
                $page = $pages[$url] ?? [404, 'Not found'];

                return ['response' => $page[0], 'content' => is_string($page[1]) ? $page[1] : json_encode($page[1])];
            };
        }

        private function peertubeVideo(array $changes = []): array
        {
            return $changes + [
                'uuid'        => self::UUID,
                'shortUUID'   => self::SHORT,
                'name'        => 'Blender Studio Log 33',
                'embedPath'   => '/videos/embed/' . self::SHORT,
                'aspectRatio' => 1.7778,
            ];
        }

        /**
         * Address, provider, host, id as given, start.
         */
        public static function candidates(): array
        {
            return [
                'PeerTube short'           => ['https://video.blender.org/w/' . self::SHORT, 'peertube', 'video.blender.org', self::SHORT, 0],
                'PeerTube short, start'    => ['https://video.blender.org/w/' . self::SHORT . '?start=1m30s', 'peertube', 'video.blender.org', self::SHORT, 90],
                'PeerTube watch'           => ['https://tube.example.org/videos/watch/' . self::UUID, 'peertube', 'tube.example.org', self::UUID, 0],
                'PeerTube embed'           => ['https://tube.example.org/videos/embed/' . self::UUID . '?start=12', 'peertube', 'tube.example.org', self::UUID, 12],
                'PeerTube, upper-case host' => ['https://Tube.Example.ORG/w/' . self::SHORT, 'peertube', 'tube.example.org', self::SHORT, 0],
                'PeerTube, punycode host'  => ['https://xn--vido-bpa.example/w/' . self::SHORT, 'peertube', 'xn--vido-bpa.example', self::SHORT, 0],
                'Loops'                    => ['https://loops.video/v/gm_hz4W_iG', 'loops', 'loops.video', 'gm_hz4W_iG', 0],
                'Loops, own server'        => ['https://loops.school.example/v/gm_hz4W_iG?t=7', 'loops', 'loops.school.example', 'gm_hz4W_iG', 7],
                'Loops embed'              => ['https://loops.video/embed/gm_hz4W_iG', 'loops', 'loops.video', 'gm_hz4W_iG', 0],
            ];
        }

        /**
         * @dataProvider candidates
         */
        public function testCandidate($address, $provider, $host, $id, $start)
        {
            $this->assertSame(
                ['provider' => $provider, 'host' => $host, 'id' => $id, 'start' => $start],
                VideoUrl::candidate($address)
            );
            $this->assertNull(VideoUrl::parse($address), 'The address alone is never enough for a host that anybody can run.');
        }

        public static function notCandidates(): array
        {
            return [
                'nothing'                   => [''],
                'null'                      => [null],
                'http'                      => ['http://tube.example.org/w/' . self::SHORT],
                'javascript'                => ['javascript://tube.example.org/w/' . self::SHORT],
                'userinfo'                  => ['https://user:pass@tube.example.org/w/' . self::SHORT],
                'userinfo, look-alike'      => ['https://tube.example.org@evil.example/nothing'],
                'a port'                    => ['https://tube.example.org:8443/w/' . self::SHORT],
                'IPv4 host'                 => ['https://203.0.113.10/w/' . self::SHORT],
                'IPv4 host, this machine'   => ['https://127.0.0.1/w/' . self::SHORT],
                'IPv6 host'                 => ['https://[::1]/w/' . self::SHORT],
                'decimal host'              => ['https://2130706433/w/' . self::SHORT],
                'host with no dot'          => ['https://localhost/w/' . self::SHORT],
                'host ending in a dot'      => ['https://tube.example.org./w/' . self::SHORT],
                'host with an underscore'   => ['https://tube_x.example.org/w/' . self::SHORT],
                'numeric top-level domain'  => ['https://tube.example.123/w/' . self::SHORT],
                'backslash'                 => ['https://evil.example\\@tube.example.org/w/' . self::SHORT],
                'a service with fixed hosts' => ['https://www.youtube.com/w/' . self::SHORT],
                'YouTube, bad id'           => ['https://www.youtube.com/v/gm_hz4W_iG'],
                'Vimeo player, as Loops'    => ['https://player.vimeo.com/embed/gm_hz4W_iG'],
                'PeerTube playlist'         => ['https://tube.example.org/w/p/' . self::SHORT],
                'PeerTube channel'          => ['https://tube.example.org/c/blender/videos'],
                'PeerTube, id with a quote' => ['https://tube.example.org/w/iWa2a6GGoZqnLinqge"YR3'],
                'PeerTube, id with a tag'   => ['https://tube.example.org/w/<script>'],
                'PeerTube, id too short'    => ['https://tube.example.org/w/abc'],
                'PeerTube, uuid malformed'  => ['https://tube.example.org/videos/watch/913b145c-ab72-4bea-a2a0-6ee69261641'],
                'PeerTube, path traversal'  => ['https://tube.example.org/videos/watch/../../api'],
                'Loops, id with a quote'    => ['https://loops.video/v/gm_hz"W_iG'],
                'Loops, extra path'         => ['https://loops.video/v/gm_hz4W_iG/extra'],
                'home page'                 => ['https://tube.example.org/'],
                'any page'                  => ['https://example.com/video/1'],
                'new line'                  => ["https://tube.example.org/w/" . self::SHORT . "\nhttps://evil.example/"],
            ];
        }

        /**
         * @dataProvider notCandidates
         */
        public function testNotACandidate($address)
        {
            $this->assertNull(VideoUrl::candidate($address));
        }

        public function testPeerTubeVideoIsConfirmed()
        {
            $api = 'https://video.blender.org/api/v1/videos/' . self::SHORT;
            $this->answer([$api => [200, $this->peertubeVideo()]]);

            $found = OpenVideo::resolve(VideoUrl::candidate('https://video.blender.org/w/' . self::SHORT . '?start=90'));

            $this->assertSame([$api], $this->asked, 'One request, to the host in the address, over https.');
            $this->assertInstanceOf(VideoUrl::class, $found['video']);
            $video = $found['video'];
            $this->assertSame('peertube', $video->getProvider());
            $this->assertSame('PeerTube', $video->getProviderName());
            $this->assertSame('video.blender.org', $video->getHost());
            $this->assertSame(self::UUID, $video->getId(), 'The id that is kept is the full one the host gave.');
            $this->assertSame(90, $video->getStart());
            $this->assertFalse($video->isPortrait());
            $this->assertTrue($video->isOpen());
            $this->assertSame('https://video.blender.org/videos/embed/' . self::UUID . '?start=90s', $video->getEmbedUrl());
            $this->assertSame('Blender Studio Log 33', $found['title']);
        }

        public function testPeerTubePortraitVideo()
        {
            $this->answer(['https://tube.example.org/api/v1/videos/' . self::UUID => [200, $this->peertubeVideo(['aspectRatio' => 0.5625])]]);

            $found = OpenVideo::resolve(VideoUrl::candidate('https://tube.example.org/videos/watch/' . self::UUID));

            $this->assertTrue($found['video']->isPortrait());
            $this->assertSame('https://tube.example.org/videos/embed/' . self::UUID, $found['video']->getEmbedUrl());
        }

        public function testRemoteTitleIsPlainText()
        {
            $this->answer(['https://tube.example.org/api/v1/videos/' . self::UUID => [200, $this->peertubeVideo(['name' => "  A <b>film</b><script>alert(1)</script>\n" . str_repeat('x', 400)])]]);

            $found = OpenVideo::resolve(VideoUrl::candidate('https://tube.example.org/videos/watch/' . self::UUID));

            $this->assertStringStartsWith('A filmalert(1)', $found['title']);
            $this->assertStringNotContainsString('<', $found['title']);
            $this->assertLessThanOrEqual(200, mb_strlen($found['title']));
        }

        public static function badPeerTubeAnswers(): array
        {
            $good = [
                'uuid'      => self::UUID,
                'shortUUID' => self::SHORT,
                'name'      => 'A film',
                'embedPath' => '/videos/embed/' . self::SHORT,
            ];

            return [
                'not found'               => [404, 'Not found'],
                'server error'            => [500, json_encode($good)],
                'refused by the fetcher'  => [0, ''],
                'not JSON'                => [200, '<html><body>Welcome</body></html>'],
                'a JSON string'           => [200, '"peertube"'],
                'empty object'            => [200, '{}'],
                'another video'           => [200, json_encode(['uuid' => '00000000-0000-4000-8000-000000000000', 'shortUUID' => 'aaaaaaaaaaaaaaaaaaaaaa'] + $good)],
                'uuid with markup'        => [200, json_encode(['uuid' => '"><script>alert(1)</script>'] + $good)],
                'uuid is a list'          => [200, json_encode(['uuid' => [self::UUID]] + $good)],
                'no embed path'           => [200, json_encode(array_diff_key($good, ['embedPath' => 1]))],
                'embed path elsewhere'    => [200, json_encode(['embedPath' => 'https://evil.example/embed'] + $good)],
                'embed path of another'   => [200, json_encode(['embedPath' => '/videos/embed/aaaaaaaaaaaaaaaaaaaaaa'] + $good)],
                'very large'              => [200, json_encode(['padding' => str_repeat('x', 600000)] + $good)],
            ];
        }

        /**
         * @dataProvider badPeerTubeAnswers
         */
        public function testPeerTubeAnswerThatIsNotAVideoIsRefused($status, $body)
        {
            $this->answer(['https://tube.example.org/api/v1/videos/' . self::SHORT => [$status, $body]]);

            $this->assertNull(OpenVideo::resolve(VideoUrl::candidate('https://tube.example.org/w/' . self::SHORT)));
            $this->assertCount(1, $this->asked);
        }

        private function nodeinfo(string $host, string $software): array
        {
            return [
                "https://$host/.well-known/nodeinfo" => [200, ['links' => [
                    ['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.0', 'href' => "https://$host/nodeinfo/2.0"],
                    ['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1', 'href' => "https://$host/nodeinfo/2.1"],
                ]]],
                "https://$host/nodeinfo/2.1" => [200, ['version' => '2.1', 'software' => ['name' => $software, 'version' => '1.0.0'], 'protocols' => ['activitypub']]],
                "https://$host/nodeinfo/2.0" => [200, ['version' => '2.0', 'software' => ['name' => $software, 'version' => '1.0.0'], 'protocols' => ['activitypub']]],
            ];
        }

        public function testLoopsVideoIsConfirmed()
        {
            $this->answer($this->nodeinfo('loops.school.example', 'loops'));

            $found = OpenVideo::resolve(VideoUrl::candidate('https://loops.school.example/v/gm_hz4W_iG?t=7'));

            $this->assertCount(2, $this->asked);
            $this->assertSame('https://loops.school.example/.well-known/nodeinfo', $this->asked[0]);
            $video = $found['video'];
            $this->assertSame('loops', $video->getProvider());
            $this->assertSame('loops.school.example', $video->getHost());
            $this->assertSame('gm_hz4W_iG', $video->getId());
            $this->assertTrue($video->isPortrait(), 'Loops videos are shot upright.');
            $this->assertSame('https://loops.school.example/embed/gm_hz4W_iG?t=7', $video->getEmbedUrl());
            $this->assertSame('', $found['title']);
        }

        public function testAHostThatRunsSomethingElseIsNotLoops()
        {
            $this->answer($this->nodeinfo('social.example', 'mastodon'));

            $this->assertNull(OpenVideo::resolve(VideoUrl::candidate('https://social.example/v/gm_hz4W_iG')));
        }

        public static function badNodeinfoLinks(): array
        {
            return [
                'another host'      => ['https://evil.example/nodeinfo/2.1'],
                'http'              => ['http://loops.school.example/nodeinfo/2.1'],
                'this machine'      => ['https://127.0.0.1/nodeinfo/2.1'],
                'with userinfo'     => ['https://loops.school.example@evil.example/nodeinfo/2.1'],
                'not an address'    => ['javascript:alert(1)'],
                'a list'            => [['https://loops.school.example/nodeinfo/2.1']],
            ];
        }

        /**
         * The description of the software must be on the host itself.
         *
         * @dataProvider badNodeinfoLinks
         */
        public function testNodeinfoElsewhereIsNotFollowed($href)
        {
            $pages = $this->nodeinfo('loops.school.example', 'loops');
            $pages['https://loops.school.example/.well-known/nodeinfo'] = [200, ['links' => [['rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1', 'href' => $href]]]];
            $pages['https://evil.example/nodeinfo/2.1'] = $pages['https://loops.school.example/nodeinfo/2.1'];
            $this->answer($pages);

            $this->assertNull(OpenVideo::resolve(VideoUrl::candidate('https://loops.school.example/v/gm_hz4W_iG')));
            $this->assertSame(['https://loops.school.example/.well-known/nodeinfo'], $this->asked, 'Nothing else may be requested.');
        }

        public function testNothingIsAskedOfAnythingThatIsNotACandidate()
        {
            $this->answer([]);

            $this->assertNull(OpenVideo::resolve(null));
            $this->assertNull(OpenVideo::resolve(['provider' => 'peertube', 'host' => '127.0.0.1', 'id' => self::UUID, 'start' => 0]));
            $this->assertNull(OpenVideo::resolve(['provider' => 'peertube', 'host' => 'tube.example.org/x', 'id' => self::UUID, 'start' => 0]));
            $this->assertNull(OpenVideo::resolve(['provider' => 'peertube', 'host' => 'tube.example.org', 'id' => '../../admin', 'start' => 0]));
            $this->assertNull(OpenVideo::resolve(['provider' => 'youtube', 'host' => 'tube.example.org', 'id' => 'dQw4w9WgXcQ', 'start' => 0]));
            $this->assertSame([], $this->asked);
        }

        public static function storedOpenVideos(): array
        {
            return [
                'PeerTube'                 => ['peertube', self::UUID, 'tube.example.org', true],
                'Loops'                    => ['loops', 'gm_hz4W_iG', 'loops.video', true],
                'PeerTube, short id'       => ['peertube', self::SHORT, 'tube.example.org', false],
                'no host'                  => ['peertube', self::UUID, '', false],
                'host is a list'           => ['peertube', self::UUID, ['tube.example.org'], false],
                'host with a path'         => ['peertube', self::UUID, 'tube.example.org/x', false],
                'host with a quote'        => ['peertube', self::UUID, 'tube.example.org" onload="alert(1)', false],
                'host with userinfo'       => ['peertube', self::UUID, 'a@evil.example', false],
                'host with a port'         => ['peertube', self::UUID, 'tube.example.org:8443', false],
                'host is an IP address'    => ['peertube', self::UUID, '127.0.0.1', false],
                'host in upper case'       => ['peertube', self::UUID, 'Tube.Example.org', false],
                'host of a fixed service'  => ['loops', 'gm_hz4W_iG', 'www.youtube.com', false],
                'host on a fixed service'  => ['youtube', 'dQw4w9WgXcQ', 'evil.example', false],
            ];
        }

        /**
         * What is stored is checked as strictly when it is read back.
         *
         * @dataProvider storedOpenVideos
         */
        public function testStoredOpenVideo($provider, $id, $host, $accepted)
        {
            $video = VideoUrl::fromStored($provider, $id, 0, '', false, $host);

            if ($accepted) {
                $this->assertInstanceOf(VideoUrl::class, $video);
                $this->assertSame('https', parse_url($video->getEmbedUrl(), PHP_URL_SCHEME));
                $this->assertSame($host, parse_url($video->getEmbedUrl(), PHP_URL_HOST));
            } else {
                $this->assertNull($video);
            }
        }

        public function testServiceNames()
        {
            $this->assertSame(['YouTube', 'Vimeo', 'Dailymotion', 'TikTok'], VideoUrl::supportedServices());
            $this->assertSame(['YouTube', 'Vimeo', 'Dailymotion', 'TikTok', 'PeerTube', 'Loops'], VideoUrl::supportedServices(true));
        }
    }

}
