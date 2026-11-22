<?php

namespace IdnoPlugins\Media\Tests {

    use Idno\Core\Idno;
    use Idno\Core\Webservice;
    use IdnoPlugins\Media\Media;

    /**
     * The page of an audio or video post must load, and its structured data (the JSON-LD
     * block in the page's head) must describe that post.
     */
    class MediaStructuredDataTest extends \Tests\IdnoTestCase
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

        public static function mediaTypes(): array
        {
            return [
                'audio' => ['audio/wav', 'wav', 'AudioObject'],
                'video' => ['video/mp4', 'mp4', 'VideoObject'],
            ];
        }

        /**
         * A one-sample WAV file: small, and enough to be stored and attached.
         */
        private function fileContents(): string
        {
            return 'RIFF' . pack('V', 38) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', 2) . "\0\0";
        }

        private function createMedia(string $mediaType, string $extension, string $marker): array
        {
            $filesystem = Idno::site()->filesystem();
            $fileId = $filesystem->storeContent(
                $this->fileContents(), [
                'filename' => "$marker.$extension",
                'mime_type' => $mediaType
                ]
            );
            $this->assertNotEmpty($fileId, 'The media file should have been stored.');

            $media = new Media();
            $media->setOwner($this->user());
            $media->title = "Recording $marker";
            $media->body = "About the recording $marker";
            $media->media_type = $mediaType;
            $media->setAccess('PUBLIC');
            $media->attachFile($filesystem->findOne($fileId));
            $this->assertNotEmpty($media->save(true), 'The media post should have been saved.');
            $this->toDelete[] = $media;

            return [$media, (string) $fileId];
        }

        /**
         * The JSON-LD block of a page
         */
        private function structuredData(string $html): array
        {
            $this->assertSame(1, preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $match), 'The page should have a JSON-LD block.');
            $data = json_decode($match[1], true);
            $this->assertIsArray($data, 'The JSON-LD block should be JSON.');

            return $data;
        }

        private function assertDescribes(array $data, Media $media, string $fileId, string $mediaType, string $schemaType): void
        {
            $this->assertSame('http://schema.org/', $data['@context']);
            $this->assertSame($schemaType, $data['@type'], "A post of type $mediaType should be a $schemaType.");
            $this->assertSame($media->getUUID(), $data['@id']);
            $this->assertSame($media->getTitle(), $data['name']);
            $this->assertSame($media->body, $data['description']);
            $this->assertSame($mediaType, $data['encodingFormat']);
            $this->assertSame(date('c', $media->getCreatedTime()), $data['uploadDate']);
            $this->assertSame(date('c', $media->getCreatedTime()), $data['datePublished']);
            $this->assertSame($this->user()->getName(), $data['author']['name']);
            $this->assertStringContainsString($fileId, $data['contentUrl'], 'The content URL should be the address of the attached file.');
        }

        /**
         * @dataProvider mediaTypes
         */
        public function testStructuredDataDescribesThePost($mediaType, $extension, $schemaType)
        {
            $old = $this->swapUser($this->user());
            $marker = 'mediamarker' . md5(rand() . microtime(true));
            [$media, $fileId] = $this->createMedia($mediaType, $extension, $marker);

            $this->assertDescribes($media->jsonLDSerialise(), $media, $fileId, $mediaType, $schemaType);

            $this->swapUser($old);
        }

        /**
         * @dataProvider mediaTypes
         */
        public function testPermalinkPageLoadsWithItsStructuredData($mediaType, $extension, $schemaType)
        {
            $old = $this->swapUser($this->user());
            $marker = 'mediamarker' . md5(rand() . microtime(true));
            [$media, $fileId] = $this->createMedia($mediaType, $extension, $marker);
            $this->swapUser($old);

            $result = Webservice::get($media->getURL());

            $this->assertEquals(200, $result['response'], 'The page of a media post should load. If this is failing, you may need to set IDNO_DOMAIN.');
            $this->assertStringContainsString("About the recording $marker", (string) $result['content'], 'The page should show the post.');
            $this->assertDescribes($this->structuredData((string) $result['content']), $media, $fileId, $mediaType, $schemaType);
        }

    }

}
