<?php

namespace IdnoPlugins\Video\Tests {

    use IdnoPlugins\Video\OpenVideo;

    /**
     * The host of a PeerTube or Loops video is named by a member, so asking it anything
     * must never reach this machine's own network, and is done over https only.
     */
    class OpenVideoFetchTest extends \Tests\OutboundHttpTestCase
    {

        public function testAnAddressOnThisMachineIsNotFetched()
        {
            $port = parse_url(static::$server->url('/'), PHP_URL_PORT);

            foreach (["https://127.0.0.1:$port/api/v1/videos/x", "https://localhost:$port/api/v1/videos/x", 'https://169.254.169.254/latest/meta-data/', 'https://10.0.0.1/api'] as $url) {
                $result = OpenVideo::fetch($url);
                $this->assertSame(0, (int) $result['response'], "$url must not be fetched.");
                $this->assertSame('', (string) $result['content']);
            }
            $this->assertSame([], static::$server->requests(), 'No request may be made.');
        }

        public function testOnlyHttpsIsFetched()
        {
            $this->useLocalServerAsProxy();

            $result = OpenVideo::fetch('http://203.0.113.10/api/v1/videos/x');

            $this->assertSame(0, (int) $result['response']);
            $this->assertSame([], static::$server->requests(), 'No request may be made.');
        }
    }

}
