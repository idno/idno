<?php

namespace Tests\Characterisation {

    /**
     * How the site serves an uploaded file at /file/<id>/<name>: the bytes, type and length of a
     * whole file, a range request (its quirks included), an unknown id, an id that is not hex, and
     * a file attached to a private entity. Files are stored through
     * \Idno\Entities\File::createFromFile(), as an upload is.
     */
    class FileServingTest extends CharacterisationTestCase
    {
        private const SIZE = 2000;

        /** @var string[] ids of the files a test stored */
        private array $fileIds = [];

        protected function tearDown(): void
        {
            try {
                foreach ($this->fileIds as $id) {
                    $file = \Idno\Entities\File::getByID($id);
                    if ($file) {
                        $file->delete();
                    }
                }
            } finally {
                $this->fileIds = [];
                parent::tearDown();
            }
        }

        /**
         * 2,000 bytes of known content: the letters A to Z in turn.
         */
        private function bytes(): string
        {
            $bytes = '';
            for ($i = 0; $i < self::SIZE; $i++) {
                $bytes .= chr(65 + $i % 26);
            }

            return $bytes;
        }

        private function storeFixtureFile(): string
        {
            $path = tempnam(sys_get_temp_dir(), 'idno-file-serving');
            file_put_contents($path, $this->bytes());
            try {
                $id = \Idno\Entities\File::createFromFile($path, 'name.txt', 'text/plain');
            } finally {
                @unlink($path);
            }
            $this->assertNotEmpty($id, 'The fixture file should have been stored.');
            $this->fileIds[] = $id;

            return $id;
        }

        public function testAFileIsServedWithItsBytesTypeAndLength(): void
        {
            $id = $this->storeFixtureFile();

            $r = $this->site()->request('GET', 'file/' . $id . '/name.txt');

            $this->assertSame(200, $r->status);
            $this->assertSame('text/plain;charset=UTF-8', $r->header('Content-Type'));
            $this->assertSame('2000', $r->header('Content-Length'));
            $this->assertSame($this->bytes(), $r->body);
        }

        public function testARangeIsServedWithExactlyItsBytes(): void
        {
            $id = $this->storeFixtureFile();

            $r = $this->site()->request('GET', 'file/' . $id . '/name.txt', ['Range' => 'bytes=100-199']);

            // Both positions of a range are inclusive: "bytes=100-199" is 100 bytes, and Content-Length says
            // so. It used to say 99 (end minus start) while the body ran on from offset 100 and the client
            // cut it, so a player was one byte short.
            $this->assertSame(206, $r->status);
            $this->assertSame('bytes 100-199/2000', $r->header('Content-Range'));
            $this->assertSame('100', $r->header('Content-Length'));
            $this->assertSame('bytes', $r->header('Accept-Ranges'));
            $this->assertSame(substr($this->bytes(), 100, 100), $r->body);
            $this->assertSame('WXYZABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJ', substr($r->body, 0, 40));
        }

        public function testAnUnknownIdIsNotFound(): void
        {
            $r = $this->site()->request('GET', 'file/' . str_repeat('0', 32) . '/name.txt');

            $this->assertSame(404, $r->status);
        }

        public function testAnIdThatIsNotHexIsNotFound(): void
        {
            $r = $this->site()->request('GET', 'file/zzzz');

            $this->assertSame(404, $r->status);
        }

        public function testAFileAttachedToAPrivateEntityIs403ForAVisitor(): void
        {
            $id      = $this->storeFixtureFile();
            $private = $this->createStatus($this->user(), 'one two three four five six seven eight nine ten private-file-' . uniqid(), $this->user()->getUUID());
            $this->assertTrue(\Idno\Core\Idno::site()->filesystem()->updateMetadata($id, ['attached_entity' => $private->getUUID()]));

            $r = $this->site()->request('GET', 'file/' . $id . '/name.txt');

            $this->assertSame(403, $r->status);
            $this->assertSame('', $r->body);
        }
    }

}
