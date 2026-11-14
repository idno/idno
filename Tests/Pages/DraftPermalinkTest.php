<?php

namespace Tests\Pages {

    use Idno\Core\Idno;
    use Idno\Core\Webservice;

    /**
     * A draft must not be served at its permalink to anyone but its owner and admins.
     */
    class DraftPermalinkTest extends \Tests\IdnoTestCase
    {

        private $toDelete = [];

        /// Whether a test changed the saved site configuration and needs it restored
        private $pinnedFilePathHost = false;

        public function tearDown(): void
        {
            $old = $this->swapUser($this->admin());
            foreach ($this->toDelete as $entity) {
                $entity->delete();
            }
            $this->toDelete = [];
            $this->swapUser($old);

            if ($this->pinnedFilePathHost) {
                Idno::site()->config()->file_path_host = null;
                Idno::site()->config()->save();
                $this->pinnedFilePathHost = false;
            }
        }

        public function testAnonymousRequestForPublicDraftIsGone()
        {
            $old = $this->swapUser($this->user());

            // The slug is built from the first words of the body, and the error page echoes the
            // requested URL, so the marker goes after enough filler to stay out of the slug.
            $marker = 'draftmarker' . md5(rand() . microtime(true));
            $entity = new \IdnoPlugins\Status\Status();
            $entity->body = 'Zebra ' . substr(md5(rand()), 0, 8) . ' one two three four five six seven eight nine ten eleven twelve ' . $marker;
            $entity->setAccess('PUBLIC');
            $this->assertTrue($entity->saveAsDraft(), 'The draft should have been saved.');
            $this->toDelete[] = $entity;

            $url = $entity->getURL();
            $this->assertStringNotContainsString($marker, $url, 'The marker should not be part of the permalink.');

            // Requests made here carry no session, so they are anonymous
            foreach ([$url, $url . '?_t=json', $url . '?_t=rss'] as $request) {
                $result = Webservice::get($request);
                $this->assertEquals(410, $result['response'], "An anonymous request for a public draft ($request) should say it is gone, as it does for a private post. If this is failing, you may need to set IDNO_DOMAIN.");
                $this->assertStringNotContainsString($marker, (string) $result['content'], "An anonymous request for a public draft ($request) should not reveal its text.");
            }

            // Control: once published, the same permalink serves the same text
            $entity->setPublishStatus('published');
            $this->assertNotEmpty($entity->save(), 'The draft should have been published.');
            $this->assertEquals($url, $entity->getURL(), 'Publishing should not change the permalink.');

            $result = Webservice::get($url);
            $this->assertEquals(200, $result['response'], 'Once published, the permalink should load anonymously.');
            $this->assertStringContainsString($marker, (string) $result['content'], 'Once published, the permalink should show the text.');

            $this->swapUser($old);
        }

        public function testAnonymousRequestForAttachmentOfHiddenEntityIsDenied()
        {
            $old = $this->swapUser($this->user());
            $filesystem = Idno::site()->filesystem();

            // Local file storage lives in a directory named after the site's host, and the web
            // server can know the site by a different host name than this process does. Pin the
            // directory name in the saved configuration so both look in the same place.
            Idno::site()->config()->file_path_host = Idno::site()->config()->pathHost();
            Idno::site()->config()->save();
            $this->pinnedFilePathHost = true;

            $marker = 'attachmentmarker' . md5(rand() . microtime(true));
            $urls = [];
            foreach (['draft' => 'PUBLIC', 'private' => $this->user()->getUUID(), 'public' => 'PUBLIC'] as $kind => $access) {
                $id = $filesystem->storeContent(
                    "$kind $marker", [
                    'filename' => "$kind-$marker.txt",
                    'mime_type' => 'text/plain'
                    ]
                );
                $this->assertNotEmpty($id, 'The file should have been stored.');

                $entity = new \IdnoPlugins\Status\Status();
                $entity->body = "Attachment test $kind " . md5(rand());
                $entity->setAccess($access);
                $entity->attachFile($filesystem->findOne($id));
                if ($kind == 'draft') {
                    $this->assertTrue($entity->saveAsDraft(), 'The draft should have been saved.');
                } else {
                    $this->assertNotEmpty($entity->save(), 'The entity should have been saved.');
                }
                $this->toDelete[] = $entity;

                $urls[$kind] = Idno::site()->config()->getDisplayURL() . 'file/' . $id;
            }

            // Control: the attachment of a public, published entity is served to anyone
            $result = Webservice::get($urls['public']);
            $this->assertEquals(200, $result['response'], 'The attachment of a public post should load anonymously. If this is failing, you may need to set IDNO_DOMAIN.');
            $this->assertStringContainsString($marker, (string) $result['content']);

            foreach (['draft', 'private'] as $kind) {
                $result = Webservice::get($urls[$kind]);
                $this->assertEquals(403, $result['response'], "An anonymous request for the attachment of a $kind post should be denied.");
                $this->assertStringNotContainsString($marker, (string) $result['content'], "An anonymous request for the attachment of a $kind post should not reveal it.");
            }

            $this->swapUser($old);
        }

    }

}
