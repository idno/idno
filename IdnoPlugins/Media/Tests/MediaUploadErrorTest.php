<?php

namespace IdnoPlugins\Media\Tests {

    use Idno\Core\Idno;
    use IdnoPlugins\Media\Media;

    /**
     * An audio or video file that PHP did not keep ends the save with a message in plain
     * words (the limit, not the name of a php.ini directive), for a new post and for an
     * existing one whose file was being replaced.
     */
    class MediaUploadErrorTest extends \Tests\IdnoTestCase
    {

        private $previousUser;
        private $previousPage;
        private $previousFiles;
        private $toDelete = [];

        public function setUp(): void
        {
            $this->previousUser = $this->swapUser($this->user());
            $this->previousPage = Idno::site()->currentPage();
            $this->previousFiles = $_FILES;
            Idno::site()->session()->getAndFlushMessages();

            $page = new \IdnoPlugins\Media\Pages\Edit();
            $page->setInput('title', 'A recording');
            $page->setInput('body', 'Text');
            $page->setInput('access', 'PUBLIC');
            Idno::site()->setCurrentPage($page);
        }

        public function tearDown(): void
        {
            $_FILES = $this->previousFiles;
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
            Idno::site()->setCurrentPage($this->previousPage ?: new \Idno\Pages\Homepage());
            Idno::site()->session()->getAndFlushMessages();
            $this->swapUser($this->previousUser);
        }

        private function messages(): string
        {
            $text = '';
            foreach (Idno::site()->session()->getAndFlushMessages() as $message) {
                $text .= html_entity_decode($message['message'], ENT_QUOTES, 'UTF-8') . "\n";
            }

            return $text;
        }

        private function refused(int $code): array
        {
            return ['media' => ['name' => 'talk.mp3', 'type' => '', 'tmp_name' => '', 'error' => $code, 'size' => 0]];
        }

        public function testANewPostWhoseFileWasRefusedIsNotSaved()
        {
            foreach ([UPLOAD_ERR_INI_SIZE => 'is too large', UPLOAD_ERR_PARTIAL => 'did not finish uploading'] as $code => $reason) {
                $_FILES = $this->refused($code);
                $media = new Media();

                $this->assertFalse($media->saveDataFromInput());
                $this->assertEmpty($media->_id, 'No post was saved.');
                $messages = $this->messages();
                $this->assertStringContainsString('"talk.mp3" ' . $reason, $messages);
                $this->assertStringNotContainsString('php.ini', $messages);
                $this->assertStringNotContainsString('directive', $messages);
            }
        }

        public function testANewPostWithNoFileIsNotSaved()
        {
            foreach ([[], $this->refused(UPLOAD_ERR_NO_FILE)] as $files) {
                $_FILES = $files;
                $media = new Media();

                $this->assertFalse($media->saveDataFromInput());
                $this->assertEmpty($media->_id);
                $this->assertStringContainsString('needs a file', $this->messages());
            }
        }

        public function testAReplacementThatWasRefusedIsReported()
        {
            $media = new Media();
            $media->title = 'An earlier recording';
            $media->setAccess('PUBLIC');
            $this->assertNotEmpty($media->save(true));
            $this->toDelete[] = $media;

            // Before, this saved the new title and said nothing about the file
            $_FILES = $this->refused(UPLOAD_ERR_INI_SIZE);
            $this->assertFalse($media->saveDataFromInput());
            $this->assertStringContainsString('"talk.mp3" is too large', $this->messages());
            $this->assertSame('An earlier recording', Media::getByID($media->getID())->title, 'Nothing was saved.');
        }
    }

}
