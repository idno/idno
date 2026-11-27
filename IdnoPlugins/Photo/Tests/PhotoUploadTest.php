<?php

namespace IdnoPlugins\Photo\Tests {

    use Idno\Core\Idno;
    use Idno\Core\Upload;
    use Idno\Entities\User;
    use IdnoPlugins\Photo\Photo;

    /**
     * A photo post is never saved without its photo. Posting the form with a picture stores
     * the file and shows it; posting it with no file, with a file PHP refused, or with more
     * than PHP reads at all, saves nothing, says why with the limit in plain units, and
     * gives the member back what they typed. Editing a post without choosing a new file
     * keeps its photo.
     *
     * The requests are multipart posts over HTTP, as a browser makes them, so the site has
     * to be running (see IDNO_DOMAIN / IDNO_PORT).
     */
    class PhotoUploadTest extends \Tests\BrowserTestCase
    {

        /// @var User
        private static $author;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$author = static::member('photographer');
        }

        /**
         * A JPEG of the given size in pixels
         */
        private function jpeg(int $width = 640, int $height = 480): string
        {
            $image = imagecreatetruecolor($width, $height);
            imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 60, 40));
            imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width / 2), (int) ($height / 2), imagecolorallocate($image, 250, 220, 190));
            ob_start();
            imagejpeg($image, null, 90);

            return (string) ob_get_clean();
        }

        /**
         * A file slot of the form with nothing chosen, as a browser sends it
         */
        private function emptySlot(): array
        {
            return ['field' => 'photo[]', 'name' => '', 'type' => 'application/octet-stream', 'content' => ''];
        }

        private function picture(string $name, ?string $content = null, string $type = 'image/jpeg'): array
        {
            return ['field' => 'photo[]', 'name' => $name, 'type' => $type, 'content' => $content ?? $this->jpeg()];
        }

        /**
         * Post the photo form as the author, as multipart/form-data.
         *
         * @param  array   $fields Text fields
         * @param  array[] $files  Each 'field', 'name', 'type' and 'content'
         * @param  string  $page   The page whose form is posted (and the address posted to)
         * @return array 'status', 'location' and 'body'
         */
        private function postForm(array $fields, array $files, string $page = '/photo/edit', ?int $padTo = null): array
        {
            $form = $this->get($page, static::$author);
            $tokens = $this->formFields($form['body'], '/photo/edit');
            $this->assertCount(3, $tokens, 'The photo form should hold a form token (status ' . $form['status'] . ').');

            $boundary = '----idnotest' . bin2hex(random_bytes(8));
            $body = '';
            foreach ($tokens + $fields as $name => $value) {
                $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
            }
            foreach ($files as $file) {
                $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"{$file['field']}\"; filename=\"{$file['name']}\"\r\nContent-Type: {$file['type']}\r\n\r\n{$file['content']}\r\n";
            }
            $body .= "--$boundary--\r\n";

            $base = rtrim(Idno::site()->config()->getDisplayURL(), '/');
            $curl = curl_init($base . $page);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HEADER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 60);
            curl_setopt($curl, CURLOPT_COOKIEFILE, $this->jar(static::$author));
            curl_setopt($curl, CURLOPT_COOKIEJAR, $this->jar(static::$author));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type: multipart/form-data; boundary=$boundary", 'Expect:', 'Referer: ' . $base . $page]);
            $response = (string) curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            unset($curl);
            $head = substr($response, 0, $headerSize);

            return ['status' => $status, 'location' => preg_match('/^Location:\s*(\S+)/mi', $head, $match) ? $match[1] : '', 'body' => substr($response, $headerSize)];
        }

        /**
         * The author's photo posts as stored now, newest first
         *
         * @return Photo[]
         */
        private function photos(): array
        {
            $db = Idno::site()->db();
            $db->setIgnoreAccess(true);
            $photos = Photo::get(['owner' => static::$author->getUUID()], [], 50) ?: [];
            $db->setIgnoreAccess(false);

            return array_values($photos);
        }

        /**
         * The author's photo post with this title
         */
        private function photoTitled(string $title): Photo
        {
            foreach ($this->photos() as $photo) {
                if ($photo->title === $title) {
                    return $photo;
                }
            }
            $this->fail('No photo post titled "' . $title . '" was saved.');
        }

        private function path(string $address): string
        {
            $path = (string) parse_url($address, PHP_URL_PATH);
            $query = (string) parse_url($address, PHP_URL_QUERY);

            return $path . ($query !== '' ? '?' . $query : '');
        }

        /**
         * The limits the running site states in its form: [largest file, largest request]
         */
        private function statedLimits(): array
        {
            $form = $this->get('/photo/edit', static::$author);
            $this->assertMatchesRegularExpression('/data-max-file="(\d+)"/', $form['body'], 'The form states the largest file the server keeps.');
            preg_match('/data-max-file="(\d+)"/', $form['body'], $file);
            preg_match('/data-max-total="(\d+)"/', $form['body'], $total);

            return [(int) $file[1], (int) ($total[1] ?? 0)];
        }

        /**
         * Fail unless a refused post saved nothing and the form came back with the reason and the text
         */
        private function assertRefused(array $response, int $before, string $title, string $reason, string $what)
        {
            $this->assertCount($before, $this->photos(), $what . ': nothing may be saved.');
            $this->assertSame(302, $response['status'], $what . ': the member is sent back to the form.');
            $this->assertStringContainsString('/photo/edit', $response['location'], $what . ': the member is sent back to the form.');

            $form = $this->get($this->path($response['location']), static::$author);
            $this->assertStringContainsString($reason, html_entity_decode($form['body'], ENT_QUOTES, 'UTF-8'), $what . ': the member is told why.');
            $this->assertStringNotContainsString('Invalid token', $form['body'], $what);
            $this->assertStringNotContainsString('php.ini', $form['body'], $what);
            if ($title !== '') {
                $this->assertStringContainsString('value="' . $title . '"', $form['body'], $what . ': what the member typed is still in the form.');
            }
        }

        public function testAPhotoIsStoredAndShown()
        {
            $before = count($this->photos());
            $title = 'Baby ' . static::marker();

            $response = $this->postForm(
                ['title' => $title, 'body' => '<p>In the red tub.</p>', 'access' => 'PUBLIC'],
                array_merge([$this->picture('baby.jpg', $this->jpeg(1600, 1200))], array_fill(0, 9, $this->emptySlot()))
            );

            $this->assertSame(302, $response['status']);
            $this->assertCount($before + 1, $this->photos(), 'The photo post should have been saved.');
            $photo = $this->photoTitled($title);

            $attachments = $photo->getAttachments();
            $this->assertCount(1, $attachments, 'The file is attached to the post.');
            $this->assertSame('baby.jpg', $attachments[0]['filename']);
            $this->assertNotEmpty($photo->thumbs_large['baby.jpg']['url'] ?? '', 'A JPEG gets a thumbnail.');

            // The permalink shows the picture, and the picture is served
            $page = $this->get($this->path($photo->getURL()));
            $this->assertSame(200, $page['status']);
            $this->assertSame(1, preg_match('~<img[^>]+src="([^"]*/file/[0-9a-f]+/[^"]*)"[^>]*class="u-photo"~', $page['body'], $shown), 'The permalink shows a picture.');
            $this->assertSame($this->path($photo->thumbs_large['baby.jpg']['url']), $this->path(html_entity_decode($shown[1])), 'The picture shown is the thumbnail of the uploaded file.');
            $this->assertStringContainsString($this->path($attachments[0]['url']), $page['body'], 'It links to the file itself.');
            foreach ([$attachments[0]['url'], $photo->thumbs_large['baby.jpg']['url']] as $address) {
                $file = $this->get($this->path($address));
                $this->assertSame(200, $file['status'], 'The stored file is served: ' . $address);
                $this->assertNotFalse(@imagecreatefromstring($file['body']), 'What is served is the picture.');
            }
        }

        public function testSeveralPhotosAreStored()
        {
            $before = count($this->photos());

            $title = 'Three ' . static::marker();

            $this->postForm(
                ['title' => $title, 'access' => 'PUBLIC'],
                [$this->picture('one.jpg'), $this->picture('two.jpg'), $this->picture('three.jpg'), $this->emptySlot()]
            );

            $this->assertCount($before + 1, $this->photos());
            $this->assertCount(3, $this->photoTitled($title)->getAttachments());
        }

        public function testAPostWithNoFileIsRefused()
        {
            $before = count($this->photos());
            $title = 'No file ' . static::marker();

            // A browser sends every empty slot of the form
            $response = $this->postForm(['title' => $title, 'access' => 'PUBLIC'], array_fill(0, 10, $this->emptySlot()));
            $this->assertRefused($response, $before, $title, 'A photo post needs a photo', 'A post with empty file slots');

            // And a client that sends no file field at all
            $response = $this->postForm(['title' => $title, 'access' => 'PUBLIC'], []);
            $this->assertRefused($response, $before, $title, 'A photo post needs a photo', 'A post with no file field');
        }

        public function testADraftWithNoFileIsRefusedToo()
        {
            $before = count($this->photos());

            $response = $this->postForm(['title' => 'Draft ' . static::marker(), 'access' => 'PUBLIC', 'publish_status' => 'draft'], [$this->emptySlot()]);

            $this->assertRefused($response, $before, '', 'A photo post needs a photo', 'A draft with no file');
        }

        public function testAFileLargerThanTheServerKeepsIsRefused()
        {
            [$maxFile, $maxTotal] = $this->statedLimits();
            if (!$maxFile || $maxFile > 32 * 1024 * 1024 || ($maxTotal && $maxTotal < $maxFile + 2 * 1024 * 1024)) {
                $this->markTestSkipped('The site under test has no upload_max_filesize that a test can go over within post_max_size.');
            }
            $before = count($this->photos());
            $title = 'Too large ' . static::marker();

            // A real JPEG, padded past the limit: PHP drops it and leaves UPLOAD_ERR_INI_SIZE
            $large = $this->jpeg() . str_repeat("\0", $maxFile + 1024);
            $response = $this->postForm(
                ['title' => $title, 'body' => '<p>Kept text.</p>', 'access' => 'PUBLIC'],
                array_merge([$this->picture('large.jpg', $large)], array_fill(0, 9, $this->emptySlot()))
            );

            $this->assertRefused($response, $before, $title, '"large.jpg" is too large: this site accepts files of up to ' . Upload::formatSize($maxFile), 'A file over upload_max_filesize');

            // One good picture does not carry a refused one through
            $response = $this->postForm(['title' => $title, 'access' => 'PUBLIC'], [$this->picture('fine.jpg'), $this->picture('large.jpg', $large)]);
            $this->assertRefused($response, $before, $title, '"large.jpg" is too large', 'A good file and one over the limit');
        }

        public function testARequestLargerThanTheServerReadsIsRefused()
        {
            [, $maxTotal] = $this->statedLimits();
            if (!$maxTotal || $maxTotal > 64 * 1024 * 1024) {
                $this->markTestSkipped('The site under test has no post_max_size that a test can go over.');
            }
            $before = count($this->photos());

            // PHP throws the whole body away, form token included
            $response = $this->postForm(
                ['title' => 'Far too large ' . static::marker(), 'access' => 'PUBLIC'],
                [$this->picture('huge.jpg', $this->jpeg() . str_repeat("\0", $maxTotal + 1024))]
            );

            $this->assertRefused($response, $before, '', 'larger than this site accepts at once (' . Upload::formatSize($maxTotal) . ')', 'A request over post_max_size');
        }

        public function testAFileThatIsNotAPictureIsRefused()
        {
            $before = count($this->photos());
            $title = 'Not a picture ' . static::marker();

            $response = $this->postForm(['title' => $title, 'access' => 'PUBLIC'], [$this->picture('notes.jpg', 'These are notes, not a picture.')]);

            $this->assertRefused($response, $before, $title, '"notes.jpg" is not an image this site can show', 'A text file named .jpg');
        }

        public function testEditingWithoutANewFileKeepsThePhoto()
        {
            $title = 'First title ' . static::marker();
            $this->postForm(['title' => $title, 'access' => 'PUBLIC'], [$this->picture('kept.jpg'), $this->emptySlot()]);
            $photo = $this->photoTitled($title);
            $this->assertCount(1, $photo->getAttachments());
            $fileUrl = $photo->getAttachments()[0]['url'];
            $count = count($this->photos());

            $newTitle = 'Second title ' . static::marker();
            $response = $this->postForm(['title' => $newTitle, 'access' => 'PUBLIC'], array_fill(0, 10, $this->emptySlot()), '/photo/edit/' . $photo->getID());

            $this->assertSame(302, $response['status']);
            $this->assertCount($count, $this->photos(), 'An edit makes no new post.');
            $edited = Photo::getByID($photo->getID());
            $this->assertSame($newTitle, $edited->title, 'The edit was saved.');
            $this->assertCount(1, $edited->getAttachments(), 'The photo is still attached.');
            $this->assertSame($fileUrl, $edited->getAttachments()[0]['url']);
            $this->assertSame(200, $this->get($this->path($fileUrl))['status']);
        }

        public function testBothLooksHaveAFormThatCanCarryAFile()
        {
            foreach (['default' => '/photo/edit', 'Twenty26' => '/photo/edit?_t=modern'] as $look => $address) {
                $page = $this->get($address, static::$author);
                $this->assertSame(200, $page['status']);
                $this->assertSame(1, preg_match('~<form[^>]*action="[^"]*/photo/edit/?"[^>]*>(.*?)</form>~s', $page['body'], $form), "The $look look has the photo form.");
                $this->assertMatchesRegularExpression('~<form[^>]*action="[^"]*/photo/edit/?"[^>]*enctype="multipart/form-data"~', $page['body'], "The $look form is multipart.");
                $this->assertMatchesRegularExpression('~<form[^>]*action="[^"]*/photo/edit/?"[^>]*method="post"~', $page['body']);

                // The file input the server reads is inside the form
                $this->assertGreaterThanOrEqual(1, preg_match_all('~<input\b[^>]*>~s', $form[1], $inputs));
                $fileInputs = array_values(array_filter($inputs[0], function ($input) {
                    return strpos($input, 'type="file"') !== false;
                }));
                $this->assertNotEmpty($fileInputs, "The $look form has a file input inside it.");
                foreach ($fileInputs as $input) {
                    $this->assertStringContainsString('name="photo[]"', $input, "The $look form's file inputs have the name the server reads.");
                    $this->assertStringContainsString('accept="' . Upload::imageAccept() . '"', $input, "The $look form's file inputs say what they accept.");
                }

                // The limits are stated up front, in plain units
                [$maxFile] = $this->statedLimits();
                if ($maxFile) {
                    $this->assertStringContainsString(Upload::formatSize($maxFile), strip_tags($form[1]), "The $look form says how large a file can be.");
                }
                $this->assertStringContainsString('data-message-required=', $form[1], "The $look form of a new post needs a file.");
            }
        }
    }

}
