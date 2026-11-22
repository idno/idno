<?php

namespace IdnoPlugins\Photo\Tests {

    use Idno\Core\Idno;
    use IdnoPlugins\Photo\Photo;

    /**
     * The structured data (JSON-LD) of a photo post describes the post, and names its
     * image when it has one. A post whose file is gone still has structured data.
     */
    class PhotoStructuredDataTest extends \Tests\IdnoTestCase
    {

        private $toDelete = [];

        public function tearDown(): void
        {
            $old = $this->swapUser($this->admin());
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
            $this->swapUser($old);
        }

        private function photo(): Photo
        {
            $photo = new Photo();
            $photo->setOwner($this->user());
            $photo->title = 'A photo ' . md5(rand() . microtime(true));
            $photo->body = 'About the photo';
            $photo->setAccess('PUBLIC');

            return $photo;
        }

        public function testPhotoWithoutAFile()
        {
            $old = $this->swapUser($this->user());

            $photo = $this->photo();
            $this->assertNotEmpty($photo->save(true), 'The photo post should have been saved.');
            $this->toDelete[] = $photo;

            $data = $photo->jsonLDSerialise();

            $this->assertSame('Photograph', $data['@type']);
            $this->assertSame($photo->getTitle(), $data['name']);
            $this->assertSame($photo->getURL(), $data['url']);
            $this->assertArrayNotHasKey('image', $data, 'A photo post with no file has no image to name.');

            $this->swapUser($old);
        }

        public function testPhotoWithAFile()
        {
            $old = $this->swapUser($this->user());

            $filesystem = Idno::site()->filesystem();
            // A one-pixel GIF
            $fileId = $filesystem->storeContent(
                base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), [
                'filename' => 'pixel.gif',
                'mime_type' => 'image/gif'
                ]
            );
            $this->assertNotEmpty($fileId, 'The image should have been stored.');

            $photo = $this->photo();
            $photo->attachFile($filesystem->findOne($fileId));
            $this->assertNotEmpty($photo->save(true), 'The photo post should have been saved.');
            $this->toDelete[] = $photo;

            $data = $photo->jsonLDSerialise();

            $this->assertSame('Photograph', $data['@type']);
            $this->assertSame($photo->getTitle(), $data['name']);
            $this->assertStringContainsString((string) $fileId, $data['image'], 'The image should be the address of the attached file.');

            $this->swapUser($old);
        }

    }

}
