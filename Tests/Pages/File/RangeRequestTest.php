<?php

namespace Tests\Pages\File {

    use Idno\Core\Idno;
    use Idno\Core\Webservice;
    use Idno\Pages\File\View;

    /**
     * Requests for part of a stored file (the Range header, RFC 9110 section 14), which is how
     * browsers play and seek audio and video. The answer must hold exactly the bytes that its
     * Content-Range header names, and Content-Length must be their number: a player that is
     * sent fewer bytes than it was told to expect asks for the rest again, forever.
     */
    class RangeRequestTest extends \Tests\IdnoTestCase
    {

        /// The size of the stored file, in bytes
        const SIZE = 1000;

        /// The contents of the stored file
        private static $contents;

        /// The ID of the stored file
        private static $fileId;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            // Local file storage lives in a directory named after the site's host, and the web
            // server can know the site by a different host name than this process does. Pin the
            // directory name in the saved configuration so both look in the same place.
            Idno::site()->config()->file_path_host = Idno::site()->config()->pathHost();
            Idno::site()->config()->save();

            // 250 four-digit numbers, so that every part of the file is different
            self::$contents = '';
            for ($i = 0; $i < self::SIZE / 4; $i++) {
                self::$contents .= sprintf('%04d', $i);
            }

            self::$fileId = Idno::site()->filesystem()->storeContent(
                self::$contents, [
                'filename' => 'range-' . md5(rand() . microtime(true)) . '.mp4',
                'mime_type' => 'video/mp4'
                ]
            );
        }

        public static function tearDownAfterClass(): void
        {
            if (self::$fileId && ($file = Idno::site()->filesystem()->findOne(self::$fileId))) {
                $file->delete();
            }
            self::$fileId = null;

            Idno::site()->config()->file_path_host = null;
            Idno::site()->config()->save();

            parent::tearDownAfterClass();
        }

        /**
         * Ask the site for the stored file, with a Range header.
         */
        private function request($range)
        {
            $this->assertNotEmpty(self::$fileId, 'The file should have been stored.');

            return Webservice::get(Idno::site()->config()->getDisplayURL() . 'file/' . self::$fileId, null, ['Range: ' . $range]);
        }

        /**
         * The values of one header in a response.
         */
        private function headerValues($result, $name)
        {
            preg_match_all('/^' . preg_quote($name, '/') . ':\s*(.*?)\s*$/mi', (string) $result['header'], $matches);

            return $matches[1];
        }

        /**
         * Ranges that can be satisfied: the header, then the first and last byte to expect.
         */
        public function satisfiableRanges()
        {
            $last = self::SIZE - 1;

            return [
                'the last byte, as first-last' => ["bytes=$last-$last", $last, $last],
                'the last byte, as first-' => ["bytes=$last-", $last, $last],
                'the first byte' => ['bytes=0-0', 0, 0],
                'a range in the middle' => ['bytes=400-599', 400, 599],
                'from a byte to the end' => ['bytes=250-', 250, $last],
                'the whole file' => ['bytes=0-', 0, $last],
                'the last 100 bytes' => ['bytes=-100', self::SIZE - 100, $last],
                'an end past the end of the file' => ['bytes=900-5000', 900, $last],
                'more last bytes than the file has' => ['bytes=-5000', 0, $last],
            ];
        }

        /**
         * Headers that are not a single range of bytes, and are ignored.
         */
        public function ignoredRanges()
        {
            return [
                'more than one range' => ['bytes=0-1,5-6'],
                'another unit' => ['items=0-5'],
                'no positions' => ['bytes=-'],
                'not a range' => ['bytes=abc'],
                'no unit' => ['0-5'],
            ];
        }

        /**
         * Ranges that can't be satisfied.
         */
        public function unsatisfiableRanges()
        {
            return [
                'starting after the last byte' => ['bytes=' . self::SIZE . '-'],
                'starting far after the last byte' => ['bytes=5000-6000'],
                'the last zero bytes' => ['bytes=-0'],
                'ending before it starts' => ['bytes=600-400'],
            ];
        }

        /**
         * @dataProvider satisfiableRanges
         */
        public function testParseRange($range, $first, $last)
        {
            $this->assertSame([$first, $last], View::parseRange($range, self::SIZE));
        }

        /**
         * @dataProvider ignoredRanges
         */
        public function testParseRangeIgnoresWhatIsNotOneRangeOfBytes($range)
        {
            $this->assertNull(View::parseRange($range, self::SIZE));
        }

        /**
         * @dataProvider unsatisfiableRanges
         */
        public function testParseRangeRefusesWhatCannotBeSatisfied($range)
        {
            $this->assertFalse(View::parseRange($range, self::SIZE));
        }

        public function testParseRangeOfEmptyFile()
        {
            $this->assertFalse(View::parseRange('bytes=0-', 0));
            $this->assertFalse(View::parseRange('bytes=-10', 0));
        }

        /**
         * @dataProvider satisfiableRanges
         */
        public function testRangeIsServed($range, $first, $last)
        {
            $result = $this->request($range);
            $length = $last - $first + 1;

            $this->assertEquals(206, $result['response'], "A request for $range should be answered with partial content. If this is failing, you may need to set IDNO_DOMAIN.");
            $this->assertSame(["bytes $first-$last/" . self::SIZE], $this->headerValues($result, 'Content-Range'), "The answer to $range should say which bytes it holds.");
            $this->assertSame([(string) $length], $this->headerValues($result, 'Content-Length'), "The answer to $range should announce $length bytes.");
            $this->assertSame($length, strlen((string) $result['content']), "The answer to $range should hold $length bytes.");
            $this->assertSame(substr(self::$contents, $first, $length), $result['content'], "The answer to $range should hold bytes $first to $last of the file.");
        }

        /**
         * @dataProvider ignoredRanges
         */
        public function testWholeFileIsServedWhenRangeIsIgnored($range)
        {
            $result = $this->request($range);

            $this->assertEquals(200, $result['response'], "A Range header that is not one range of bytes ($range) should be ignored.");
            $this->assertSame([], $this->headerValues($result, 'Content-Range'));
            $this->assertSame([(string) self::SIZE], $this->headerValues($result, 'Content-Length'));
            $this->assertSame(self::$contents, $result['content']);
        }

        /**
         * @dataProvider unsatisfiableRanges
         */
        public function testUnsatisfiableRangeIsRefused($range)
        {
            $result = $this->request($range);

            $this->assertEquals(416, $result['response'], "A request for $range can't be satisfied.");
            $this->assertSame(['bytes */' . self::SIZE], $this->headerValues($result, 'Content-Range'), 'The answer should say how large the file is.');
            $this->assertSame('', (string) $result['content'], 'No part of the file should be sent.');
        }

        public function testWholeFileIsServedWithoutRange()
        {
            $result = Webservice::get(Idno::site()->config()->getDisplayURL() . 'file/' . self::$fileId);

            $this->assertEquals(200, $result['response']);
            $this->assertSame(['bytes'], $this->headerValues($result, 'Accept-Ranges'), 'The answer should say that ranges of bytes can be requested.');
            $this->assertSame([(string) self::SIZE], $this->headerValues($result, 'Content-Length'));
            $this->assertSame(self::$contents, $result['content']);
        }

    }

}
