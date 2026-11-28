<?php

namespace Tests\Pages\File {

    use Idno\Core\Idno;

    /**
     * The files of a private site (the "walled_garden" setting). A visitor who is not signed in
     * can read no post there, and so no file that belongs to one, even with the file's address.
     *
     * The requests are made over HTTP, so this test needs the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class PrivateSiteFileTest extends \Tests\BrowserTestCase
    {

        private $files = [];

        public function setUp(): void
        {
            // Local file storage lives in a directory named after the site's host, and the web
            // server can know the site by a different host name than this process does. Pin the
            // directory name in the saved configuration so both look in the same place.
            Idno::site()->config()->file_path_host = Idno::site()->config()->pathHost();
            Idno::site()->config()->save();
        }

        public function tearDown(): void
        {
            Idno::site()->config()->file_path_host = null;
            $this->setPrivate(false);
            foreach ($this->files as $id) {
                if ($file = \Idno\Entities\File::getByID($id)) {
                    $file->delete();
                }
            }
        }

        /**
         * Make the site private or public, for this process and for the web server
         */
        private function setPrivate($private)
        {
            Idno::site()->config()->walled_garden = (bool) $private;
            Idno::site()->config()->save();
        }

        private function store($contents, $name)
        {
            $id = Idno::site()->filesystem()->storeContent($contents, ['filename' => $name, 'mime_type' => 'text/plain']);
            $this->files[] = $id;

            return $id;
        }

        /**
         * The photo of a public post on a private site was served to anybody who had its
         * address: one who was sent the link, or a server the post went to while the site was
         * public.
         */
        public function testAVisitorToAPrivateSiteIsGivenNoFileOfAPost()
        {
            $member = static::member('fileowner');
            $post = $this->post($member);
            $attached = $this->store('part of a post', 'attached.txt');
            Idno::site()->filesystem()->updateMetadata($attached, ['attached_entity' => $post->getUUID()]);
            $loose = $this->store('belongs to no post', 'loose.txt');
            $this->swapUser(null);

            $this->assertSame(200, $this->get('/file/' . $attached . '/attached.txt')['status'], 'Control: a public site serves the file of a public post.');

            $this->setPrivate(true);

            $refused = $this->get('/file/' . $attached . '/attached.txt');
            $this->assertSame(403, $refused['status']);
            $this->assertStringNotContainsString('part of a post', $refused['body']);

            $served = $this->get('/file/' . $attached . '/attached.txt', $member);
            $this->assertSame(200, $served['status'], 'A member who is signed in reads the file as before.');
            $this->assertSame('part of a post', $served['body']);

            $this->assertSame(200, $this->get('/file/' . $loose . '/loose.txt')['status'], 'A file that belongs to no post or member (the site\'s own) is still served.');
        }

    }

}
