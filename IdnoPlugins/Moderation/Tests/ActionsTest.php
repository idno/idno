<?php

namespace IdnoPlugins\Moderation\Tests {

    use Idno\Common\Entity;
    use Idno\Core\Idno;
    use IdnoPlugins\Moderation\Entities\ModerationCase;
    use IdnoPlugins\Moderation\Moderation;

    /**
     * What each action of an administrator does, over HTTP as the people it affects see it.
     */
    class ActionsTest extends ModerationTestCase
    {

        private static $author;
        private static $admin;
        private static $reporters = [];

        public function setUp(): void
        {
            parent::setUp();
            if (!static::$author) {
                static::$author = static::member('actauthor');
                static::$admin = static::member('actadmin', true);
            }
        }

        public static function tearDownAfterClass(): void
        {
            parent::tearDownAfterClass();
            static::$author = static::$admin = null;
            static::$reporters = [];
        }

        /**
         * A member to report with (a new one every few reports, to stay under the hourly limit)
         */
        private function reporter()
        {
            static $used = 0;
            if ($used++ % 8 === 0) {
                static::$reporters[] = static::member('actreporter');
            }

            return end(static::$reporters);
        }

        private function reportedPost($text = null, $author = null)
        {
            $marker = static::marker();
            $entry = $this->post($author ?? static::$author, $text ?? ('Zebra one two three four five six seven eight nine ten eleven twelve ' . $marker));
            $case = $this->report($this->reporter(), 'post', $entry, 'harassment')['case'];
            $this->assertNotEmpty($case);

            return [$entry, $case, $marker];
        }

        /**
         * Press a button on the case page as an administrator, the way the browser does
         */
        private function press($case, $action, array $fields = [], $as = null)
        {
            $path = '/admin/moderation/case/' . ((string) $case->getID());

            return $this->submit($path . '/action', ['action' => $action, 'revision' => static::freshCase($case)->revision] + $fields, $as ?? static::$admin, $path);
        }

        private function stored($entity)
        {
            return Moderation::unrestricted(
                function () use ($entity) {
                    return Entity::getByUUID($entity->getUUID(), false);
                }
            );
        }

        // -----------------------------------------------------------------
        // Dismiss and reopen
        // -----------------------------------------------------------------

        public function testDismissResolvesAndReopenBringsItBack()
        {
            [$entry, $case] = $this->reportedPost();

            $result = $this->press($case, 'dismiss');

            $this->assertEquals(302, $result['status']);
            $fresh = static::freshCase($case);
            $this->assertEquals(ModerationCase::RESOLVED, $fresh->status);
            $this->assertEquals('dismissed', $fresh->outcome);
            $this->assertEquals(static::$admin->getUUID(), $fresh->resolved_by);
            $this->assertEqualsWithDelta(time(), $fresh->resolved_at, 10);
            $this->assertEquals(200, $this->get($entry->getDisplayURL())['status'], 'A dismissal leaves the post alone.');

            $this->press($case, 'reopen');
            $fresh = static::freshCase($case);
            $this->assertEquals(ModerationCase::OPEN, $fresh->status);
            $this->assertEquals('', $fresh->outcome);

            $actions = array_map(
                function ($entry) {
                    return $entry->action;
                }, Moderation::logEntries(['case_id' => ((string) $case->getID())])
            );
            $this->assertEquals(['reopen', 'dismiss', 'report'], $actions, 'Every action is in the log.');
            $this->assertEquals(static::$admin->getUUID(), Moderation::logEntries(['case_id' => ((string) $case->getID())])[0]->actor_uuid);
        }

        public function testOnlyADismissedCaseCanBeReopened()
        {
            [, $case] = $this->reportedPost();
            $this->press($case, 'remove');

            $this->press($case, 'reopen');

            $this->assertEquals(ModerationCase::RESOLVED, static::freshCase($case)->status);
        }

        // -----------------------------------------------------------------
        // Remove, restore, delete
        // -----------------------------------------------------------------

        public function testRemovedPostIsHiddenAndCanBeRestored()
        {
            [$entry, $case, $marker] = $this->reportedPost();
            $other = static::member('actother');
            $url = $entry->getDisplayURL();

            $this->press($case, 'remove');

            $fresh = static::freshCase($case);
            $this->assertEquals('removed', $fresh->outcome);
            $this->assertTrue($fresh->content_removed);
            $this->assertEquals('removed', $this->stored($entry)->getPublishStatus());
            foreach ([null, $other] as $viewer) {
                $this->assertContains($this->get($url, $viewer)['status'], [403, 404, 410], 'A removed post must not be served to visitors or other members.');
                foreach (['/', static::$author->getDisplayURL(), '/?_t=rss', $url . '?_t=json'] as $page) {
                    $this->assertStringNotContainsString($marker, $this->get($page, $viewer)['body'], "A removed post must not be shown on $page.");
                }
            }
            $mine = $this->get($url, static::$author);
            $this->assertEquals(200, $mine['status']);
            $this->assertStringContainsString('Removed by the moderators', $mine['body']);
            $this->assertStringNotContainsString('moderation/report', $mine['body']);

            $this->press($case, 'restore');

            $fresh = static::freshCase($case);
            $this->assertEquals('restored', $fresh->outcome);
            $this->assertEmpty($fresh->content_removed);
            $this->assertEquals('published', $this->stored($entry)->getPublishStatus());
            $restored = $this->get($url);
            $this->assertEquals(200, $restored['status']);
            $this->assertStringContainsString($marker, $restored['body']);
        }

        public function testRemovedPostCanBeDeletedPermanentlyAndTheCopyStays()
        {
            [$entry, $case, $marker] = $this->reportedPost();
            $this->press($case, 'remove');

            $this->press($case, 'delete');

            $this->assertEmpty($this->stored($entry), 'The post should be gone for good.');
            $fresh = static::freshCase($case);
            $this->assertTrue($fresh->content_deleted);
            $this->assertStringContainsString($marker, $fresh->snapshot['text'], 'The case keeps its copy of what was reported.');
            $page = $this->get('/admin/moderation/case/' . ((string) $case->getID()), static::$admin);
            $this->assertEquals(200, $page['status']);
            $this->assertStringContainsString($marker, $page['body']);

            $this->press($case, 'restore');
            $this->assertEmpty($this->stored($entry), 'What was deleted cannot be restored.');
        }

        public function testRemovingAPostThatIsAlreadyGoneResolvesTheCase()
        {
            [$entry, $case] = $this->reportedPost();
            $old = $this->swapUser(static::$author);
            $entry->delete();
            $this->swapUser($old);
            \IdnoPlugins\Moderation\Mailer::$capture = [];

            $result = $this->act($case, static::$admin, 'remove');

            $this->assertTrue($result['ok']);
            $this->assertStringContainsString('already gone', $result['message']);
            $this->assertEquals(ModerationCase::RESOLVED, static::freshCase($case)->status);
            $this->assertEmpty($this->mails('content-removed'));
            $this->assertEquals(200, $this->get('/admin/moderation/case/' . ((string) $case->getID()), static::$admin)['status']);
        }

        public function testRemovedCommentIsGoneAndComesBackWhereItWas()
        {
            $entry = $this->post(static::$author);
            $marker = static::marker();
            $commenter = static::member('actcommenter');
            $local = $this->comment($entry, $commenter, 'Remark ' . $marker);
            $case = $this->report($this->reporter(), 'comment', $entry, 'hate', '', basename($local))['case'];
            $url = $entry->getDisplayURL();
            $this->assertStringContainsString($marker, $this->get($url)['body']);

            $this->press($case, 'remove');

            $this->assertStringNotContainsString($marker, $this->get($url)['body'], 'A removed comment must not be shown.');
            $this->assertStringNotContainsString($marker, $this->get($url . '?_t=json')['body']);
            $this->assertEquals(200, $this->get($url)['status'], 'The post itself stays.');
            $this->assertEmpty($this->stored($entry)->getAnnotation($local));

            $this->press($case, 'restore');

            $this->assertStringContainsString($marker, $this->get($url)['body']);
            $this->assertNotEmpty($this->stored($entry)->getAnnotation($local), 'The comment is back under the address it had.');
        }

        // -----------------------------------------------------------------
        // Ban
        // -----------------------------------------------------------------

        public function testBanEndsTheSessionAndLiftingItRestoresAccess()
        {
            $target = static::member('acttarget');
            [$entry, $case, $marker] = $this->reportedPost(null, $target);
            $this->assertEquals(200, $this->get('/account/settings/', $target)['status'], 'Control: the member is signed in.');

            $this->press($case, 'ban', ['ban_reason' => 'Because ' . $marker, 'notify' => '1']);

            $stored = static::fresh($target);
            $this->assertTrue($stored->isBanned());
            $this->assertEquals(static::$admin->getUUID(), $stored->getBan()['by']);
            $this->assertStringContainsString($marker, $stored->getBan()['reason']);
            $this->assertNotEquals(200, $this->get('/account/settings/', $target)['status'], 'A ban ends the session at once.');
            $fresh = static::freshCase($case);
            $this->assertEquals('banned', $fresh->outcome);
            $this->assertTrue($fresh->banned_here);
            $this->assertEquals(200, $this->get($entry->getDisplayURL())['status'], 'A ban alone leaves the posts up.');
            $this->assertEquals(200, $this->get($target->getDisplayURL())['status']);
            foreach ([$target->getDisplayURL(), $target->getDisplayURL() . '?_t=json'] as $page) {
                $this->assertStringNotContainsString($marker, str_replace($entry->body, '', $this->get($page)['body']), 'The reason for a ban is for administrators only.');
            }

            $banned = $this->get('/admin/moderation/?tab=banned', static::$admin);
            $this->assertStringContainsString($target->getHandle(), $banned['body']);
            $this->assertStringContainsString('Because ' . $marker, $banned['body']);

            $this->press($case, 'unban');

            $this->assertFalse(static::fresh($target)->isBanned());
            $this->assertEquals('ban_lifted', static::freshCase($case)->outcome);
            $this->logIn($target);
            $this->assertEquals(200, $this->get('/account/settings/', $target)['status']);
        }

        public function testBanCanRemoveTheReportedPostToo()
        {
            $target = static::member('acttarget');
            [$entry, $case] = $this->reportedPost(null, $target);

            $this->press($case, 'ban', ['remove_content' => '1']);

            $this->assertTrue(static::fresh($target)->isBanned());
            $this->assertEquals('removed', $this->stored($entry)->getPublishStatus());
            $this->assertNotEquals(200, $this->get($entry->getDisplayURL())['status']);

            // Lifting the ban does not bring back a post that was removed for itself
            $this->press($case, 'unban');
            $this->assertEquals('removed', $this->stored($entry)->getPublishStatus());
        }

        public function testBanWithEverythingHiddenAndItsUndo()
        {
            $target = static::member('acttarget');
            $marker = static::marker();
            $first = $this->post($target, 'Zebra one two three four five six seven eight nine ten eleven twelve first' . $marker);
            $second = $this->post($target, 'Zebra one two three four five six seven eight nine ten eleven twelve second' . $marker);
            $old = $this->swapUser($target);
            $draft = new \IdnoPlugins\Status\Status();
            $draft->body = 'Draft ' . $marker;
            $draft->saveAsDraft();
            static::$cleanup[] = $draft;
            $this->swapUser($old);
            $removed = $this->post($target, 'Zebra removed' . $marker);
            $removedCase = $this->report($this->reporter(), 'post', $removed)['case'];
            $this->act($removedCase, static::$admin, 'remove');
            $case = $this->report($this->reporter(), 'profile', $target, 'spam')['case'];

            $this->press($case, 'ban', ['hide_all' => '1']);

            $this->assertTrue(static::fresh($target)->isHiddenByBan());
            $this->assertTrue(static::freshCase($case)->hid_all);
            foreach ([$first, $second] as $entry) {
                $this->assertEquals('hidden', $this->stored($entry)->getPublishStatus());
                $this->assertNotEquals(200, $this->get($entry->getDisplayURL())['status']);
            }
            $this->assertEquals(404, $this->get($target->getDisplayURL())['status'], 'The profile of a member banned with everything hidden is not served.');
            $this->assertEquals(404, $this->get($target->getDisplayURL() . '?_t=json')['status']);
            foreach (['/', '/?_t=rss', '/?_t=json'] as $page) {
                $this->assertStringNotContainsString($marker, $this->get($page)['body']);
            }
            $this->assertEquals(200, $this->get($target->getDisplayURL(), static::$admin)['status'], 'Administrators still see the profile.');

            $this->press($case, 'unban');

            foreach ([$first, $second] as $entry) {
                $this->assertEquals('published', $this->stored($entry)->getPublishStatus());
                $this->assertEquals(200, $this->get($entry->getDisplayURL())['status']);
            }
            $this->assertEquals('draft', $this->stored($draft)->getPublishStatus(), 'A draft stays a draft.');
            $this->assertEquals('removed', $this->stored($removed)->getPublishStatus(), 'A post removed for itself stays removed.');
            $this->assertEquals(200, $this->get($target->getDisplayURL())['status']);
        }

        public function testBanWithRemoveStillRemovesWhenTheMemberIsAlreadyBanned()
        {
            $target = static::member('acttarget');
            [, $first] = $this->reportedPost(null, $target);
            [$second, $secondCase] = $this->reportedPost(null, $target);
            $this->assertTrue($this->act($first, static::$admin, 'ban')['ok']);

            $this->assertTrue($this->act($secondCase, static::$admin, 'ban', ['remove_content' => true])['ok']);

            $this->assertEquals('removed', $this->stored($second)->getPublishStatus(), 'The post must be removed even though its author was banned already.');
            $this->assertTrue(static::freshCase($secondCase)->content_removed);
        }

        public function testRestoringAPostOfAMemberWhoseContentIsHiddenKeepsItHidden()
        {
            $target = static::member('acttarget');
            [$entry, $case] = $this->reportedPost(null, $target);
            $this->assertTrue($this->act($case, static::$admin, 'ban', ['remove_content' => true, 'hide_all' => true])['ok']);

            $this->assertTrue($this->act($case, static::$admin, 'restore')['ok']);

            $this->assertEquals('hidden', $this->stored($entry)->getPublishStatus(), 'The post goes back to being hidden with the rest, not public.');
            $this->assertNotEquals(200, $this->get($entry->getDisplayURL())['status']);

            $this->assertTrue($this->act($case, static::$admin, 'unban')['ok']);
            $this->assertEquals('published', $this->stored($entry)->getPublishStatus(), 'Lifting the ban brings it back.');
        }

        public function testThereIsNobodyToBanForACommentFromAnotherSite()
        {
            $entry = $this->post(static::$author);
            $stored = $this->stored($entry);
            $stored->addAnnotation('reply', 'Remote Person', 'https://elsewhere.example/', '', 'From far away ' . static::marker(), 'https://elsewhere.example/post/1', null, '', [], false);
            $stored = $this->stored($entry);
            $local = array_key_last($stored->getAnnotations('reply'));
            $case = $this->report($this->reporter(), 'comment', $entry, 'spam', '', basename($local))['case'];
            $this->assertNotEmpty($case);
            $this->assertEquals('', $case->subject_uuid);
            $this->assertEquals('Remote Person', $case->subject_name);

            $this->assertFalse($this->act($case, static::$admin, 'ban')['ok']);
            $this->assertTrue($this->act($case, static::$admin, 'remove')['ok']);
            $this->assertEmpty($this->stored($entry)->getAnnotation($local));
        }

        public function testMemberRecordPageBansAndLiftsWithoutAReport()
        {
            $target = static::member('acttarget');
            $path = '/admin/moderation/member/' . ((string) $target->getID());

            $this->assertEquals(200, $this->get($path, static::$admin)['status']);
            $this->submit($path, ['action' => 'ban', 'ban_reason' => 'Direct'], static::$admin);
            $this->assertTrue(static::fresh($target)->isBanned());

            $this->submit($path, ['action' => 'unban'], static::$admin);
            $this->assertFalse(static::fresh($target)->isBanned());
        }

        public function testAnAdministratorCannotBanThemselfFromTheirRecordPage()
        {
            $path = '/admin/moderation/member/' . ((string) static::$admin->getID());

            $this->request('POST', $path, ['action' => 'ban'] + $this->formFields($this->get('/admin/moderation/settings', static::$admin)['body']), static::$admin);

            $this->assertFalse(static::fresh(static::$admin)->isBanned());
            $this->assertNotEmpty(static::fresh(static::$admin)->admin);
        }

        // -----------------------------------------------------------------
        // Two administrators, and administrators as subjects
        // -----------------------------------------------------------------

        public function testAnActionOnAStaleRevisionIsRefused()
        {
            [$entry, $case] = $this->reportedPost();
            $seen = static::freshCase($case)->revision;
            $second = static::member('actadmintwo', true);

            // The first administrator dismisses; the second, still looking at the old page, removes
            $this->assertTrue($this->act($case, static::$admin, 'dismiss')['ok']);
            $old = $this->swapUser($second);
            $result = Moderation::act(static::freshCase($case), $second, 'remove', ['revision' => $seen]);
            $this->swapUser($old);

            $this->assertFalse($result['ok']);
            $this->assertEquals(409, $result['code']);
            $this->assertEquals('dismissed', static::freshCase($case)->outcome);
            $this->assertEquals('published', $this->stored($entry)->getPublishStatus(), 'The second action must not have happened.');

            // And over HTTP, with the revision the page showed before
            $path = '/admin/moderation/case/' . ((string) $case->getID());
            $this->submit($path . '/action', ['action' => 'remove', 'revision' => $seen], $second, $path);
            $this->assertEquals('published', $this->stored($entry)->getPublishStatus());
        }

        public function testAnAdministratorCannotSeeOrActOnACaseAboutThemself()
        {
            $second = static::member('actadmintwo', true);
            [$entry, $case] = $this->reportedPost(null, $second);

            $this->assertEquals(404, $this->get('/admin/moderation/case/' . ((string) $case->getID()), $second)['status']);
            $this->assertStringNotContainsString(((string) $case->getID()), $this->get('/admin/moderation/', $second)['body']);
            $this->assertStringNotContainsString(((string) $case->getID()), $this->get('/admin/moderation/?tab=log', $second)['body']);
            $this->assertEquals(404, $this->get('/admin/moderation/member/' . ((string) $second->getID()), $second)['status']);
            $path = '/admin/moderation/case/' . ((string) $case->getID());
            $this->request('POST', $path . '/action', ['action' => 'dismiss', 'revision' => static::freshCase($case)->revision] + $this->formFields($this->get('/admin/moderation/settings', $second)['body']), $second);
            $this->assertEquals(ModerationCase::OPEN, static::freshCase($case)->status, 'An administrator must not dismiss a report about themself.');
            $this->assertFalse($this->act($case, $second, 'dismiss')['ok']);

            $this->assertEquals(200, $this->get($path, static::$admin)['status'], 'Another administrator handles it.');
            $this->assertStringContainsString(((string) $case->getID()), $this->get('/admin/moderation/', static::$admin)['body']);
        }

        public function testActionsNeedAFormToken()
        {
            [$entry, $case] = $this->reportedPost();
            $path = '/admin/moderation/case/' . ((string) $case->getID()) . '/action';

            $this->request('POST', $path, ['action' => 'remove', 'revision' => static::freshCase($case)->revision], static::$admin);

            $this->assertEquals(ModerationCase::OPEN, static::freshCase($case)->status);
            $this->assertEquals('published', $this->stored($entry)->getPublishStatus());
        }

        public function testNotesAreKeptAndDoNotResolve()
        {
            [, $case] = $this->reportedPost();
            $marker = static::marker();

            $this->press($case, 'note', ['note' => 'Seen this before <b>' . $marker . '</b>']);

            $fresh = static::freshCase($case);
            $this->assertEquals(ModerationCase::OPEN, $fresh->status);
            $this->assertCount(1, $fresh->notes);
            $this->assertEquals('Seen this before ' . $marker, $fresh->notes[0]['text']);
            $this->assertStringContainsString($marker, $this->get('/admin/moderation/case/' . ((string) $case->getID()), static::$admin)['body']);
            // A note does not get in the way of the next action
            $this->press($case, 'dismiss');
            $this->assertEquals('dismissed', static::freshCase($case)->outcome);
        }
    }

}
