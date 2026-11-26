<?php

namespace IdnoPlugins\Media\Tests {

    use Idno\Core\Idno;
    use Idno\Entities\File;
    use IdnoPlugins\Media\Media;

    /**
     * An audio or video post keeps one file, and saving the post with a new file removes the
     * old one from storage. That happens before the post is saved, so it must not happen for
     * somebody who may not edit the post: the save is then refused, and the file would be gone
     * all the same.
     *
     * The requests are multipart posts over HTTP, so these tests need the site to be running
     * (see IDNO_DOMAIN / IDNO_PORT).
     */
    class MediaReplaceAccessTest extends \Tests\BrowserTestCase
    {

        private static $owner;
        private static $other;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$owner = static::member('mediaown');
            static::$other = static::member('mediaother');
        }

        /// The addresses that save an existing audio or video post
        public static function addresses(): array
        {
            return [
                'its edit page' => [function (Media $post) {
                    return '/media/edit/' . $post->getID();
                }],
                'its own address' => [function (Media $post) {
                    return '/view/' . $post->getID();
                }],
            ];
        }

        /**
         * @dataProvider addresses
         */
        public function testAnotherMemberDoesNotRemoveTheFileOfAPost($address)
        {
            [$post, $fileId, $bytes] = $this->audioPost();

            $answer = $this->upload($address($post), static::$other);

            $this->assertNotEquals(500, $answer['status']);
            $file = File::getByID($fileId);
            $this->assertNotEmpty($file, 'The file of a post must still be stored after somebody who may not edit the post tried to replace it.');
            $this->assertEquals($bytes, $file->getBytes(), 'The file must be the one its owner uploaded.');
            $this->assertEquals($fileId, $this->fileIdOf($post), 'The post must still have its own file.');
        }

        public function testTheOwnerReplacesTheFileOfAPost()
        {
            [$post, $fileId] = $this->audioPost();

            $this->upload('/media/edit/' . $post->getID(), static::$owner);

            $now = $this->fileIdOf($post);
            $this->assertNotEmpty($now, 'The post should still have a file.');
            $this->assertNotEquals($fileId, $now, 'The owner of a post should be able to replace its file.');
        }

        /// A published audio post of the owner, the id of its file and the bytes of that file
        private function audioPost(): array
        {
            $bytes = 'ID3' . random_bytes(64);
            $path = tempnam(sys_get_temp_dir(), 'idno-media-test-');
            file_put_contents($path, $bytes);

            $old = $this->swapUser(static::$owner);
            $post = new Media();
            $post->title = 'Audio ' . static::marker();
            $post->body = 'Audio description';
            $post->media_type = 'audio/mpeg';
            $post->setAccess('PUBLIC');
            $stored = File::createFromFile($path, 'sound.mp3', 'audio/mpeg', true);
            $this->assertNotEmpty($stored, 'The file should have been stored.');
            $post->attachFile($stored);
            $this->assertNotEmpty($post->save(), 'The post should have been saved.');
            $this->swapUser($old);
            unlink($path);
            static::$cleanup[] = $post;

            $fileId = $this->fileIdOf($post);
            $this->assertNotEmpty($fileId);
            $this->assertNotEmpty(File::getByID($fileId));

            return [$post, $fileId, $bytes];
        }

        /// The id of the file a post has now
        private function fileIdOf(Media $post)
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $fresh = \Idno\Common\Entity::getByID($post->getID());
            Idno::site()->db()->setIgnoreAccess(false);
            $attachments = $fresh ? $fresh->getAttachments() : [];
            $first = $attachments ? reset($attachments) : null;

            return $first ? (string) $first['_id'] : '';
        }

        /**
         * Post the form of an audio or video post with a new file, as multipart/form-data, with a
         * valid form token of the member's own session.
         */
        private function upload($path, \Idno\Entities\User $as): array
        {
            $base = rtrim(Idno::site()->config()->getDisplayURL(), '/');
            $action = $base . $path;
            $issued = $this->get('/service/security/csrftoken/?' . http_build_query(['url' => $action]), $as, ['X-Requested-With: XMLHttpRequest']);
            $token = json_decode($issued['body'], true);
            $this->assertNotEmpty($token['token'] ?? null, 'The site should issue a form token. If this is failing, you may need to set IDNO_DOMAIN.');

            $file = tempnam(sys_get_temp_dir(), 'idno-media-test-');
            file_put_contents($file, 'ID3' . random_bytes(64));

            $jar = $this->jar($as);
            $curl = curl_init($action);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($curl, CURLOPT_TIMEOUT, 30);
            curl_setopt($curl, CURLOPT_COOKIEFILE, $jar);
            curl_setopt($curl, CURLOPT_COOKIEJAR, $jar);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Expect:', 'Referer: ' . $base . '/']);
            curl_setopt($curl, CURLOPT_POSTFIELDS, [
                '__bTs' => $token['time'],
                '__bTk' => $token['token'],
                '__bTa' => $action,
                'title' => 'Replaced ' . static::marker(),
                'body' => 'Replaced',
                'access' => 'PUBLIC',
                'media' => new \CURLFile($file, 'audio/mpeg', 'other.mp3'),
            ]);
            $body = (string) curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            unset($curl); // Writes the cookie jar
            unlink($file);

            return ['status' => $status, 'body' => $body];
        }
    }

}
