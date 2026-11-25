<?php

namespace IdnoPlugins\Video\Tests {

    use IdnoPlugins\Video\VideoUrl;
    use PHPUnit\Framework\TestCase;

    /**
     * VideoUrl turns the address of a video on a supported service into a provider, an id
     * and an embed address. It makes no network request, and it refuses anything that is
     * not plainly an address on one of those services.
     */
    class VideoUrlTest extends TestCase
    {

        /**
         * Address, provider, id, embed address, start time in seconds, portrait.
         */
        public static function recognised(): array
        {
            $yt = 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ';

            return [
                'YouTube watch'              => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', $yt, 0, false],
                'YouTube watch, no www'      => ['https://youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', $yt, 0, false],
                'YouTube mobile'             => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ&feature=share', 'youtube', 'dQw4w9WgXcQ', $yt, 0, false],
                'YouTube music'              => ['https://music.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', $yt, 0, false],
                'YouTube, v not first'       => ['https://www.youtube.com/watch?list=PL123&v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', $yt, 0, false],
                'YouTube, upper-case host'   => ['https://WWW.YouTube.com/watch?v=dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', $yt, 0, false],
                'YouTube, spaces around'     => ["  https://www.youtube.com/watch?v=dQw4w9WgXcQ \n", 'youtube', 'dQw4w9WgXcQ', $yt, 0, false],
                'YouTube start in seconds'   => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=90', 'youtube', 'dQw4w9WgXcQ', $yt . '?start=90', 90, false],
                'YouTube start with s'       => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=90s', 'youtube', 'dQw4w9WgXcQ', $yt . '?start=90', 90, false],
                'YouTube start h m s'        => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=1h2m3s', 'youtube', 'dQw4w9WgXcQ', $yt . '?start=3723', 3723, false],
                'YouTube start m s'          => ['https://youtu.be/dQw4w9WgXcQ?t=1m30s', 'youtube', 'dQw4w9WgXcQ', $yt . '?start=90', 90, false],
                'YouTube start parameter'    => ['https://www.youtube.com/embed/dQw4w9WgXcQ?start=42', 'youtube', 'dQw4w9WgXcQ', $yt . '?start=42', 42, false],
                'YouTube start in fragment'  => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ#t=45', 'youtube', 'dQw4w9WgXcQ', $yt . '?start=45', 45, false],
                'YouTube, nonsense start'    => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=soon', 'youtube', 'dQw4w9WgXcQ', $yt, 0, false],
                'youtu.be'                   => ['https://youtu.be/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', $yt, 0, false],
                'youtu.be with si'           => ['https://youtu.be/dQw4w9WgXcQ?si=AbCdEf123456', 'youtube', 'dQw4w9WgXcQ', $yt, 0, false],
                'YouTube shorts'             => ['https://www.youtube.com/shorts/aBc-_123XyZ', 'youtube', 'aBc-_123XyZ', 'https://www.youtube-nocookie.com/embed/aBc-_123XyZ', 0, true],
                'YouTube shorts, slash'      => ['https://youtube.com/shorts/aBc-_123XyZ/?feature=share', 'youtube', 'aBc-_123XyZ', 'https://www.youtube-nocookie.com/embed/aBc-_123XyZ', 0, true],
                'YouTube live'               => ['https://www.youtube.com/live/dQw4w9WgXcQ?si=abc', 'youtube', 'dQw4w9WgXcQ', $yt, 0, false],
                'YouTube embed'              => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', $yt, 0, false],
                'YouTube no-cookie embed'    => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'youtube', 'dQw4w9WgXcQ', $yt, 0, false],
                'Vimeo'                      => ['https://vimeo.com/76979871', 'vimeo', '76979871', 'https://player.vimeo.com/video/76979871?dnt=1', 0, false],
                'Vimeo www'                  => ['https://www.vimeo.com/76979871/', 'vimeo', '76979871', 'https://player.vimeo.com/video/76979871?dnt=1', 0, false],
                'Vimeo unlisted'             => ['https://vimeo.com/76979871/0a1b2c3d4e', 'vimeo', '76979871', 'https://player.vimeo.com/video/76979871?h=0a1b2c3d4e&dnt=1', 0, false],
                'Vimeo unlisted, h'          => ['https://vimeo.com/76979871?h=0a1b2c3d4e', 'vimeo', '76979871', 'https://player.vimeo.com/video/76979871?h=0a1b2c3d4e&dnt=1', 0, false],
                'Vimeo channel'              => ['https://vimeo.com/channels/staffpicks/76979871', 'vimeo', '76979871', 'https://player.vimeo.com/video/76979871?dnt=1', 0, false],
                'Vimeo group'                => ['https://vimeo.com/groups/journalism/videos/76979871', 'vimeo', '76979871', 'https://player.vimeo.com/video/76979871?dnt=1', 0, false],
                'Vimeo player'               => ['https://player.vimeo.com/video/76979871?h=0a1b2c3d4e', 'vimeo', '76979871', 'https://player.vimeo.com/video/76979871?h=0a1b2c3d4e&dnt=1', 0, false],
                'Vimeo start'                => ['https://vimeo.com/76979871#t=1m5s', 'vimeo', '76979871', 'https://player.vimeo.com/video/76979871?dnt=1#t=65s', 65, false],
                'Dailymotion'                => ['https://www.dailymotion.com/video/x8abc12', 'dailymotion', 'x8abc12', 'https://geo.dailymotion.com/player.html?video=x8abc12', 0, false],
                'Dailymotion with a title'   => ['https://www.dailymotion.com/video/x8abc12_a-title-here_news', 'dailymotion', 'x8abc12', 'https://geo.dailymotion.com/player.html?video=x8abc12', 0, false],
                'Dailymotion, no www'        => ['https://dailymotion.com/video/x8abc12?playlist=x6', 'dailymotion', 'x8abc12', 'https://geo.dailymotion.com/player.html?video=x8abc12', 0, false],
                'Dailymotion embed'          => ['https://www.dailymotion.com/embed/video/x8abc12', 'dailymotion', 'x8abc12', 'https://geo.dailymotion.com/player.html?video=x8abc12', 0, false],
                'Dailymotion start'          => ['https://www.dailymotion.com/video/x8abc12?start=30', 'dailymotion', 'x8abc12', 'https://geo.dailymotion.com/player.html?video=x8abc12&startTime=30', 30, false],
                'Dailymotion player'         => ['https://geo.dailymotion.com/player.html?video=x8abc12', 'dailymotion', 'x8abc12', 'https://geo.dailymotion.com/player.html?video=x8abc12', 0, false],
                'dai.ly'                     => ['https://dai.ly/x8abc12', 'dailymotion', 'x8abc12', 'https://geo.dailymotion.com/player.html?video=x8abc12', 0, false],
                'TikTok'                     => ['https://www.tiktok.com/@scout2015/video/6718335390845095173', 'tiktok', '6718335390845095173', 'https://www.tiktok.com/player/v1/6718335390845095173', 0, true],
                'TikTok with a query'        => ['https://www.tiktok.com/@some.user_1/video/6718335390845095173?is_from_webapp=1&lang=en', 'tiktok', '6718335390845095173', 'https://www.tiktok.com/player/v1/6718335390845095173', 0, true],
                'TikTok, no www'             => ['https://tiktok.com/@scout2015/video/6718335390845095173', 'tiktok', '6718335390845095173', 'https://www.tiktok.com/player/v1/6718335390845095173', 0, true],
                'TikTok mobile'              => ['https://m.tiktok.com/v/6718335390845095173.html', 'tiktok', '6718335390845095173', 'https://www.tiktok.com/player/v1/6718335390845095173', 0, true],
                'TikTok player'              => ['https://www.tiktok.com/player/v1/6718335390845095173', 'tiktok', '6718335390845095173', 'https://www.tiktok.com/player/v1/6718335390845095173', 0, true],
            ];
        }

        /**
         * @dataProvider recognised
         */
        public function testRecognisedAddress($address, $provider, $id, $embed, $start, $portrait)
        {
            $video = VideoUrl::parse($address);

            $this->assertInstanceOf(VideoUrl::class, $video, "$address should be recognised.");
            $this->assertSame($provider, $video->getProvider());
            $this->assertSame($id, $video->getId());
            $this->assertSame($start, $video->getStart());
            $this->assertSame($portrait, $video->isPortrait());
            $this->assertSame($embed, $video->getEmbedUrl());
            $this->assertEmbedAddressIsAllowed($video->getEmbedUrl());
        }

        /**
         * What is stored for a recognised address gives the same video back.
         *
         * @dataProvider recognised
         */
        public function testStoredPartsGiveTheSameEmbedAddress($address, $provider, $id, $embed)
        {
            $video = VideoUrl::parse($address);
            $again = VideoUrl::fromStored($video->getProvider(), $video->getId(), $video->getStart(), $video->getKey(), $video->isPortrait());

            $this->assertInstanceOf(VideoUrl::class, $again);
            $this->assertSame($embed, $again->getEmbedUrl());
        }

        public static function refused(): array
        {
            return [
                'nothing'                        => [''],
                'null'                           => [null],
                'an array'                       => [['https://youtu.be/dQw4w9WgXcQ']],
                'words'                          => ['my holiday video'],
                'javascript'                     => ['javascript:alert(1)'],
                'javascript with a host'         => ['javascript://www.youtube.com/watch?v=dQw4w9WgXcQ'],
                'data'                           => ['data:text/html,<script>alert(1)</script>'],
                'http'                           => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ'],
                'ftp'                            => ['ftp://www.youtube.com/watch?v=dQw4w9WgXcQ'],
                'no scheme'                      => ['www.youtube.com/watch?v=dQw4w9WgXcQ'],
                'protocol-relative'              => ['//www.youtube.com/watch?v=dQw4w9WgXcQ'],
                'look-alike host'                => ['https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'],
                'look-alike host, suffix'        => ['https://notyoutube.com/watch?v=dQw4w9WgXcQ'],
                'look-alike host, hyphen'        => ['https://www.youtube.com-evil.example/watch?v=dQw4w9WgXcQ'],
                'look-alike host, trailing dot'  => ['https://www.youtube.com./watch?v=dQw4w9WgXcQ'],
                'provider in the path'           => ['https://evil.example/www.youtube.com/watch?v=dQw4w9WgXcQ'],
                'provider in the query'          => ['https://evil.example/?u=https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
                'userinfo'                       => ['https://user:pass@www.youtube.com/watch?v=dQw4w9WgXcQ'],
                'userinfo, look-alike'           => ['https://www.youtube.com@evil.example/watch?v=dQw4w9WgXcQ'],
                'userinfo, user only'            => ['https://www.youtube.com:443@evil.example/watch?v=dQw4w9WgXcQ'],
                'backslash host'                 => ['https://evil.example\\@www.youtube.com/watch?v=dQw4w9WgXcQ'],
                'a port'                         => ['https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ'],
                'id with a quote'                => ['https://www.youtube.com/watch?v=dQw4w9WgXc"'],
                'id with quotes and a handler'   => ['https://www.youtube.com/watch?v=x" onload="alert(1)'],
                'id with angle brackets'         => ['https://www.youtube.com/watch?v=<script>x</script>'],
                'id with encoded brackets'       => ['https://www.youtube.com/watch?v=%3Cscript%3E'],
                'id too short'                   => ['https://www.youtube.com/watch?v=dQw4w9WgXc'],
                'id too long'                    => ['https://www.youtube.com/watch?v=dQw4w9WgXcQQ'],
                'id as an array'                 => ['https://www.youtube.com/watch?v[]=dQw4w9WgXcQ'],
                'no id'                          => ['https://www.youtube.com/watch'],
                'YouTube channel'                => ['https://www.youtube.com/@somechannel'],
                'YouTube playlist'               => ['https://www.youtube.com/playlist?list=PL1234567890'],
                'youtu.be, extra path'           => ['https://youtu.be/dQw4w9WgXcQ/extra'],
                'shorts, path traversal'         => ['https://www.youtube.com/shorts/../watch'],
                'Vimeo, not a number'            => ['https://vimeo.com/about'],
                'Vimeo, quote in the key'        => ['https://vimeo.com/76979871?h=0a1b2c"3d4e'],
                'Vimeo, number too long'         => ['https://vimeo.com/7697987176979871769798'],
                'Dailymotion, quote'             => ['https://www.dailymotion.com/video/x8ab"c12'],
                'Dailymotion, upper case'        => ['https://www.dailymotion.com/video/X8ABC12<'],
                'Dailymotion player, other page' => ['https://geo.dailymotion.com/other.html?video=x8abc12'],
                'TikTok profile'                 => ['https://www.tiktok.com/@scout2015'],
                'TikTok short link'              => ['https://vm.tiktok.com/ZMabcdefg/'],
                'TikTok, id not a number'        => ['https://www.tiktok.com/@scout2015/video/67183353908450abc'],
                'TikTok, handle with a bracket'  => ['https://www.tiktok.com/@<b>/video/6718335390845095173/"><script>'],
                'PeerTube'                       => ['https://peertube.example/w/kkGMgK9ZtnKfYAgnEtQxbv'],
                'PeerTube, uuid'                 => ['https://peertube.example/videos/watch/9c9de5e8-0a1e-484a-b099-e80766180a6d'],
                'a new line in the address'      => ["https://www.youtube.com/watch?v=dQw4w9WgXcQ\nhttps://evil.example/"],
                'a tab in the host'              => ["https://www.you\ttube.com/watch?v=dQw4w9WgXcQ"],
                'a null byte'                    => ["https://www.youtube.com/watch?v=dQw4w9WgXcQ\0"],
                'very long'                      => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&x=' . str_repeat('a', 3000)],
            ];
        }

        /**
         * @dataProvider refused
         */
        public function testRefusedAddress($address)
        {
            $this->assertNull(VideoUrl::parse($address));
        }

        public static function tamperedStoredParts(): array
        {
            return [
                'unknown provider'         => ['evil', 'dQw4w9WgXcQ', 0, ''],
                'provider is an address'   => ['https://evil.example/', 'dQw4w9WgXcQ', 0, ''],
                'provider, upper case'     => ['YouTube', 'dQw4w9WgXcQ', 0, ''],
                'provider is empty'        => ['', 'dQw4w9WgXcQ', 0, ''],
                'provider is an array'     => [['youtube'], 'dQw4w9WgXcQ', 0, ''],
                'id with a quote'          => ['youtube', 'dQw4w9WgXc"', 0, ''],
                'id with markup'           => ['youtube', '"><script>alert(1)</script>', 0, ''],
                'id is a path'             => ['youtube', '../../evil', 0, ''],
                'id with a host'           => ['youtube', 'x@evil.exam', 0, ''],
                'id with a new line'       => ['youtube', "dQw4w9WgXcQ\n", 0, ''],
                'id is an array'           => ['youtube', ['dQw4w9WgXcQ'], 0, ''],
                'id of another provider'   => ['vimeo', 'dQw4w9WgXcQ', 0, ''],
                'TikTok id for Dailymotion' => ['tiktok', 'x8abc12', 0, ''],
                'key with a quote'         => ['vimeo', '76979871', 0, '0a1b"2c3d'],
                'key with an ampersand'    => ['vimeo', '76979871', 0, '0a1b2c&autoplay=1'],
            ];
        }

        /**
         * A stored record can have been changed by hand or by another program.
         *
         * @dataProvider tamperedStoredParts
         */
        public function testTamperedStoredPartsAreRefused($provider, $id, $start, $key)
        {
            $this->assertNull(VideoUrl::fromStored($provider, $id, $start, $key));
        }

        public function testANonsenseStoredStartTimeIsIgnored()
        {
            $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', VideoUrl::fromStored('youtube', 'dQw4w9WgXcQ', '"><script>', '')->getEmbedUrl());
            $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', VideoUrl::fromStored('youtube', 'dQw4w9WgXcQ', -5, '')->getEmbedUrl());
            $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?start=12', VideoUrl::fromStored('youtube', 'dQw4w9WgXcQ', '12', '')->getEmbedUrl());
        }

        public function testProviderNames()
        {
            $this->assertSame('YouTube', VideoUrl::parse('https://youtu.be/dQw4w9WgXcQ')->getProviderName());
            $this->assertSame('Vimeo', VideoUrl::parse('https://vimeo.com/76979871')->getProviderName());
            $this->assertSame(['YouTube', 'Vimeo', 'Dailymotion', 'TikTok', 'Bluesky'], VideoUrl::supportedServices());
        }

        /**
         * Every embed address is https, on a host from the list, with no userinfo or port.
         */
        private function assertEmbedAddressIsAllowed(string $embed): void
        {
            $parts = parse_url($embed);

            $this->assertSame('https', $parts['scheme']);
            $this->assertContains($parts['host'], VideoUrl::EMBED_HOSTS);
            $this->assertArrayNotHasKey('user', $parts);
            $this->assertArrayNotHasKey('pass', $parts);
            $this->assertArrayNotHasKey('port', $parts);
            $this->assertDoesNotMatchRegularExpression('/["\'<>\s\\\\]/', $embed);
        }
    }

}
