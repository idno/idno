<?php

namespace Tests\Common {

    use Idno\Common\Entity;
    use Idno\Core\Idno;

    /**
     * A post taken down by the moderators is hidden from everyone but its author and the
     * administrators, and its author can no longer change it, bring it back or delete it.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class TakedownTest extends \Tests\BrowserTestCase
    {

        private static $author;
        private static $reader;
        private static $admin;

        public function setUp(): void
        {
            if (!static::$author) {
                static::$author = static::member('tdauthor');
                static::$reader = static::member('tdreader');
                static::$admin = static::member('tdadmin', true);
            }
        }

        public static function tearDownAfterClass(): void
        {
            parent::tearDownAfterClass();
            static::$author = static::$reader = static::$admin = null;
        }

        /**
         * The post as stored now, whoever is signed in
         */
        private function stored($entry)
        {
            Idno::site()->db()->setIgnoreAccess(true);
            $stored = Entity::getByUUID($entry->getUUID(), false);
            Idno::site()->db()->setIgnoreAccess(false);

            return $stored;
        }

        private function takenDown($status = Entity::MODERATION_REMOVED)
        {
            $marker = static::marker();
            $entry = $this->post(static::$author, 'Zebra one two three four five six seven eight nine ten eleven twelve ' . $marker);
            $old = $this->swapUser(static::$admin);
            $entry->moderate($status);
            $this->assertNotEmpty($entry->save(true));
            $this->swapUser($old);

            return [$entry, $marker];
        }

        public function testModerateAndUnmoderate()
        {
            $entry = $this->post(static::$author);
            $this->assertFalse($entry->isModerated());

            $entry->moderate(Entity::MODERATION_REMOVED);
            $this->assertTrue($entry->isModerated());
            $this->assertEquals('removed', $entry->getPublishStatus());
            $this->assertEquals('removed', $entry->getModerationStatus());

            $entry->moderate(Entity::MODERATION_HIDDEN); // A second call keeps the status from before the first
            $entry->unmoderate();
            $this->assertFalse($entry->isModerated());
            $this->assertEquals('published', $entry->getPublishStatus());
        }

        public function testUnmoderateGivesADraftBackAsADraft()
        {
            $old = $this->swapUser(static::$author);
            $entry = new \IdnoPlugins\Status\Status();
            $entry->body = 'Draft ' . static::marker();
            $entry->saveAsDraft();
            static::$cleanup[] = $entry;
            $this->swapUser($old);

            $entry->moderate(Entity::MODERATION_REMOVED);
            $entry->unmoderate();
            $this->assertEquals('draft', $entry->getPublishStatus());
        }

        public function testOnlyTheTwoStatusesAreAccepted()
        {
            $entry = $this->post(static::$author);
            $this->expectException(\InvalidArgumentException::class);
            $entry->moderate('published');
        }

        public function testRemovedPostIsHiddenFromVisitorsAndOtherMembers()
        {
            [$entry, $marker] = $this->takenDown();
            $url = $entry->getDisplayURL();

            foreach ([null, static::$reader] as $viewer) {
                foreach ([$url, $url . '?_t=json', $url . '?_t=rss', '/', '/?_t=json', '/?_t=rss', static::$author->getDisplayURL(), '/search/?q=' . $marker] as $page) {
                    $result = $this->get($page, $viewer);
                    $this->assertStringNotContainsString($marker, $result['body'], "A post that was taken down must not be shown on $page.");
                }
                $this->assertContains($this->get($url, $viewer)['status'], [403, 404, 410]);
            }
        }

        public function testAuthorSeesTheirRemovedPostWithANotice()
        {
            [$entry, $marker] = $this->takenDown();

            $result = $this->get($entry->getDisplayURL(), static::$author);
            $this->assertEquals(200, $result['status']);
            $this->assertStringContainsString($marker, $result['body']);
            $this->assertStringContainsString('Removed by the moderators', $result['body']);
        }

        public function testAdministratorSeesARemovedPost()
        {
            [$entry, $marker] = $this->takenDown();

            $result = $this->get($entry->getDisplayURL(), static::$admin);
            $this->assertEquals(200, $result['status']);
            $this->assertStringContainsString($marker, $result['body']);
        }

        public function testAuthorCannotEditARemovedPostBackIntoView()
        {
            [$entry, $marker] = $this->takenDown();
            $id = $entry->getID();
            $changed = 'Changed ' . static::marker();

            $this->submit('/status/edit/' . $id, ['body' => $changed, 'access' => 'PUBLIC', 'publish_status' => 'published'], static::$author, '/status/edit');
            // And with the API key, which needs no form token
            $this->request('POST', '/status/edit/' . $id, ['body' => $changed, 'access' => 'PUBLIC'], null, $this->signature(static::$author, '/status/edit/' . $id));

            Idno::site()->db()->setIgnoreAccess(true);
            $stored = Entity::getByUUID($entry->getUUID(), false);
            Idno::site()->db()->setIgnoreAccess(false);
            $this->assertNotEmpty($stored);
            $this->assertEquals('removed', $stored->getPublishStatus(), 'The author must not be able to publish a post that was taken down.');
            $this->assertStringContainsString($marker, $stored->body, 'The author must not be able to change a post that was taken down.');
            $this->assertNotEquals(200, $this->get($entry->getDisplayURL())['status']);
        }

        public function testControlAuthorCanEditAndDeleteAPostThatIsNotTakenDown()
        {
            $entry = $this->post(static::$author);
            $changed = 'Changed ' . static::marker();

            $this->submit('/status/edit/' . $entry->getID(), ['body' => $changed, 'access' => 'PUBLIC'], static::$author, '/status/edit');
            $this->assertEquals($changed, Entity::getByUUID($entry->getUUID(), false)->body, 'Control: the same request edits a post that is not taken down.');

            $this->submit('/status/delete/' . $entry->getID(), [], static::$author, $entry->getDisplayURL());
            $this->assertEmpty(Entity::getByUUID($entry->getUUID(), false), 'Control: the same request deletes a post that is not taken down.');
        }

        public function testAuthorCannotDeleteARemovedPost()
        {
            [$entry] = $this->takenDown();

            $this->submit('/status/delete/' . $entry->getID(), [], static::$author, $entry->getDisplayURL());

            Idno::site()->db()->setIgnoreAccess(true);
            $stored = Entity::getByUUID($entry->getUUID(), false);
            Idno::site()->db()->setIgnoreAccess(false);
            $this->assertNotEmpty($stored, 'The author must not be able to delete a post that was taken down: it is evidence.');
        }

        public function testAuthorCannotDeleteARemovedPostByAnyOtherRoute()
        {
            // Control: an HTTP DELETE to the permalink, signed with the author's API key, deletes an ordinary post
            $ordinary = $this->post(static::$author);
            $ordinaryPath = (string) parse_url($ordinary->getDisplayURL(), PHP_URL_PATH);
            $this->request('DELETE', $ordinaryPath, null, null, $this->signature(static::$author, $ordinaryPath));
            $this->assertEmpty($this->stored($ordinary), 'Control: the same request deletes a post that is not taken down.');

            // The same request for a post that was taken down
            [$entry] = $this->takenDown();
            $path = (string) parse_url($entry->getDisplayURL(), PHP_URL_PATH);
            $this->request('DELETE', $path, null, null, $this->signature(static::$author, $path));
            $this->assertNotEmpty($this->stored($entry), 'A DELETE request must not delete a post that was taken down.');

            // Micropub, with an access token of the author's
            if (Idno::site()->plugins()->get('IndiePub')) {
                $token = \IdnoPlugins\IndiePub\Pages\IndieAuth\Token::issueToken(static::fresh(static::$author), ['me' => static::$author->getURL(), 'client_id' => 'https://client.example/', 'scope' => 'delete']);
                $this->request('POST', '/micropub/endpoint', ['action' => 'delete', 'url' => $entry->getUUID()], null, ['Authorization: Bearer ' . $token]);
                $this->request('POST', '/micropub/endpoint', ['action' => 'delete', 'url' => $entry->getDisplayURL()], null, ['Authorization: Bearer ' . $token]);
                $this->assertNotEmpty($this->stored($entry), 'Micropub must not delete a post that was taken down.');
            }

            // And in this process, whoever calls delete()
            $old = $this->swapUser(static::$author);
            $stored = Entity::getByUUID($entry->getUUID(), false);
            $this->assertFalse($stored->delete(), 'delete() refuses a moderated entity unless an administrator asks.');
            $this->swapUser(static::$admin);
            $this->assertNotEmpty(Entity::getByUUID($entry->getUUID(), false));
            $this->assertNotFalse(Entity::getByUUID($entry->getUUID(), false)->delete(), 'An administrator can delete it.');
            $this->swapUser($old);
        }

        public function testAnAdministratorCannotEditTheirOwnRemovedPost()
        {
            $marker = static::marker();
            $entry = $this->post(static::$admin, 'Zebra ' . $marker);
            $other = static::member('tdadmintwo', true);
            $old = $this->swapUser($other);
            $entry->moderate(Entity::MODERATION_REMOVED);
            $entry->save(true);

            $this->assertTrue($entry->canEdit(), 'Another administrator can edit it.');
            $this->swapUser(static::$admin);
            $this->assertFalse($entry->canEdit(), 'Its author cannot, administrator or not.');
            $this->swapUser($old);
        }

        public function testCanEdit()
        {
            [$entry] = $this->takenDown();

            $old = $this->swapUser(static::$author);
            $this->assertFalse($entry->canEdit(), 'The author cannot edit a post that was taken down.');
            $this->swapUser(static::$admin);
            $this->assertTrue($entry->canEdit(), 'An administrator can.');
            $this->swapUser($old);
        }

        public function testASaveCannotChangeThePublishStatusOfAModeratedPost()
        {
            [$entry] = $this->takenDown(Entity::MODERATION_HIDDEN);

            $old = $this->swapUser(static::$admin);
            $entry->setPublishStatus('published');
            $entry->save(true);
            $this->swapUser($old);

            $this->assertEquals('hidden', $entry->getPublishStatus());
        }

        public function testRestoreAnnotationPutsACommentBackWhereItWas()
        {
            $entry = $this->post(static::$author);
            $local = $this->comment($entry, static::$reader, 'A comment ' . static::marker());
            $entry = Entity::getByUUID($entry->getUUID(), false);
            $annotation = $entry->getAnnotation($local);
            $this->assertNotEmpty($annotation);

            $this->assertTrue($entry->removeAnnotation($local));
            $entry->save(true);
            $this->assertEmpty(Entity::getByUUID($entry->getUUID(), false)->getAnnotation($local));

            $this->assertTrue($entry->restoreAnnotation('reply', $local, $annotation));
            $entry->save(true);
            $this->assertEquals($annotation, Entity::getByUUID($entry->getUUID(), false)->getAnnotation($local));
            $this->assertFalse($entry->restoreAnnotation('reply', $local, $annotation), 'A comment that is there is not put back twice.');
        }
    }

}
