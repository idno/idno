<?php

namespace IdnoPlugins\IndiePub\Tests {

    use IdnoPlugins\IndiePub\Pages\MicroPub\Endpoint;

    /**
     * Micropub lets a client name a photo, video or audio file by URL. Idno downloads it, so
     * the URL, and anything it redirects to, must not be on this machine's own network.
     */
    class MicropubUploadFromUrlTest extends \Tests\OutboundHttpTestCase
    {

        private $files;

        protected function setUp(): void
        {
            parent::setUp();
            $this->files = $_FILES;
            $_FILES = [];
        }

        protected function tearDown(): void
        {
            foreach ($_FILES['photo'] ?? [] as $file) {
                @unlink($file['tmp_name']);
            }
            $_FILES = $this->files;
            parent::tearDown();
        }

        private function uploadFromUrl(string $url)
        {
            $method = new \ReflectionMethod(Endpoint::class, 'uploadFromUrl');
            $method->setAccessible(true);

            return $method->invoke(new Endpoint(), 'photo', [$url]);
        }

        public function testFileIsDownloadedFromAPublicAddress()
        {
            $this->useLocalServerAsProxy();

            $success = $this->uploadFromUrl('http://203.0.113.10/picture.jpg');

            $this->assertNotEmpty($success);
            $this->assertCount(1, $_FILES['photo']);
            $this->assertEquals('landed GET /picture.jpg', file_get_contents($_FILES['photo'][0]['tmp_name']));
            $this->assertEquals('picture.jpg', $_FILES['photo'][0]['name']);
            $this->assertEquals('image/jpeg', $_FILES['photo'][0]['type']);
        }

        public function testFileIsNotDownloadedThroughARedirectToThisMachine()
        {
            $this->useLocalServerAsProxy();
            $start = 'http://203.0.113.10/redirect/picture.jpg?to=' . urlencode(static::$server->url('/secret.jpg'));

            $success = $this->uploadFromUrl($start);

            $this->assertEmpty($success);
            $this->assertArrayNotHasKey('photo', $_FILES);
            $this->assertSame(['GET ' . $start], static::$server->requests(), 'Only the first URL may be requested.');
        }

        public function testFileIsNotDownloadedFromThisMachine()
        {
            $this->useLocalServerAsProxy();

            $success = $this->uploadFromUrl(static::$server->url('/secret.jpg'));

            $this->assertEmpty($success);
            $this->assertArrayNotHasKey('photo', $_FILES);
            $this->assertSame([], static::$server->requests(), 'No request may be made.');
        }
    }

}
