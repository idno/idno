<?php

namespace IdnoPlugins\Photo\Tests {

    use Idno\Core\Idno;
    use IdnoPlugins\Photo\Photo;

    /**
     * What PHP reports about a file it did not keep ends the save: every upload error code
     * gives the member a message, and no post is saved, so nothing is published or sent to
     * other servers. (Over HTTP only "too large" can be made to happen; the other codes are
     * given to the plugin here as PHP would give them.)
     */
    class PhotoUploadErrorTest extends \Tests\IdnoTestCase
    {

        private $previousUser;
        private $previousPage;
        private $previousFiles;
        /// Photo posts that an event announced while a test was running
        private static $saved = [];
        private static $listening = false;
        private static $registered = false;

        public function setUp(): void
        {
            $this->previousUser = $this->swapUser($this->user());
            $this->previousPage = Idno::site()->currentPage();
            $this->previousFiles = $_FILES;
            Idno::site()->session()->getAndFlushMessages();

            $page = new \IdnoPlugins\Photo\Pages\Edit();
            $page->setInput('title', 'A photo that did not arrive');
            $page->setInput('body', 'Text');
            $page->setInput('access', 'PUBLIC');
            Idno::site()->setCurrentPage($page);

            // Federation, webhooks and syndication all start from these events. A listener
            // can't be removed again, so there is one, and it only counts during these tests
            if (!static::$registered) {
                static::$registered = true;
                foreach (['save', 'saved', 'published', 'post/image'] as $name) {
                    Idno::site()->events()->addListener($name, function (\Idno\Core\Event $event) {
                        $data = $event->data();
                        if (static::$listening && !empty($data['object']) && $data['object'] instanceof Photo) {
                            static::$saved[] = $data['object'];
                        }
                    });
                }
            }
            static::$saved = [];
            static::$listening = true;
        }

        public function tearDown(): void
        {
            static::$listening = false;
            $_FILES = $this->previousFiles;
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

        /**
         * $_FILES as PHP fills it for the ten photo[] inputs of the form, the first with an error
         */
        private function filesWithError(int $code, string $name = 'baby.jpg'): array
        {
            $files = ['name' => [$name], 'type' => [''], 'tmp_name' => [''], 'error' => [$code], 'size' => [0]];
            for ($n = 1; $n < 10; $n++) {
                $files['name'][] = '';
                $files['type'][] = '';
                $files['tmp_name'][] = '';
                $files['error'][] = UPLOAD_ERR_NO_FILE;
                $files['size'][] = 0;
            }

            return ['photo' => $files];
        }

        public static function errorCodes(): array
        {
            return [
                'over upload_max_filesize' => [UPLOAD_ERR_INI_SIZE, 'is too large'],
                'over the form\'s MAX_FILE_SIZE' => [UPLOAD_ERR_FORM_SIZE, 'is too large'],
                'cut short' => [UPLOAD_ERR_PARTIAL, 'did not finish uploading'],
                'no temporary folder' => [UPLOAD_ERR_NO_TMP_DIR, 'could not store'],
                'disk not writable' => [UPLOAD_ERR_CANT_WRITE, 'could not store'],
                'stopped by an extension' => [UPLOAD_ERR_EXTENSION, 'could not store'],
            ];
        }

        /**
         * @dataProvider errorCodes
         */
        public function testAnUploadErrorSavesNothing(int $code, string $reason)
        {
            $_FILES = $this->filesWithError($code);
            $photo = new Photo();

            $this->assertFalse($photo->saveDataFromInput(), 'The save is refused.');

            $this->assertEmpty($photo->_id, 'No post was saved.');
            $this->assertSame([], static::$saved, 'Nothing was announced: no event that publishes or federates a post was triggered.');
            $messages = $this->messages();
            $this->assertStringContainsString('"baby.jpg"', $messages, 'The member is told which file.');
            $this->assertStringContainsString($reason, $messages);
            $this->assertStringContainsString('Nothing was saved', $messages);
        }

        public function testAFileWithNoErrorCodeAndNoContentSavesNothing()
        {
            // A file entry that says it is fine but has no temporary file behind it
            $_FILES = ['photo' => ['name' => ['baby.jpg'], 'type' => ['image/jpeg'], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_OK], 'size' => [0]]];
            $photo = new Photo();

            $this->assertFalse($photo->saveDataFromInput());
            $this->assertEmpty($photo->_id);
            $this->assertStringContainsString('"baby.jpg"', $this->messages());
        }

        public function testNoFilesAtAllSavesNothing()
        {
            foreach ([[], $this->filesWithError(UPLOAD_ERR_NO_FILE, '')] as $files) {
                $_FILES = $files;
                $photo = new Photo();

                $this->assertFalse($photo->saveDataFromInput());
                $this->assertEmpty($photo->_id);
                $this->assertSame([], static::$saved);
                $this->assertStringContainsString('A photo post needs a photo', $this->messages());
            }
        }

        public function testOneFileFieldIsReadLikeSeveral()
        {
            // name="photo" rather than name="photo[]"
            $_FILES = ['photo' => ['name' => 'baby.jpg', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]];
            $photo = new Photo();

            $this->assertFalse($photo->saveDataFromInput());
            $this->assertStringContainsString('is too large', $this->messages());

            $this->assertSame([], Photo::uploadedFiles('nothing-by-this-name'));
            $_FILES = ['photo' => [['tmp_name' => '/tmp/x', 'name' => 'x.jpg', 'type' => 'image/jpeg', 'size' => 1]]];
            $this->assertCount(1, Photo::uploadedFiles('photo'), 'The list the Micropub endpoint builds is read too.');
        }
    }

}
