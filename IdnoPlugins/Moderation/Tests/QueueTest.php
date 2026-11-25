<?php

namespace IdnoPlugins\Moderation\Tests {

    use Idno\Core\Idno;
    use IdnoPlugins\Moderation\Entities\ModerationCase;
    use IdnoPlugins\Moderation\Moderation;

    /**
     * The queue an administrator sees: its tabs, its order and its counts.
     */
    class QueueTest extends ModerationTestCase
    {

        private static $author;
        private static $reporter;
        private static $admin;

        public function setUp(): void
        {
            parent::setUp();
            if (!static::$author) {
                static::purgeRecords();
                static::$author = static::member('queueauthor');
                static::$reporter = static::member('queuereporter');
                static::$admin = static::member('queueadmin', true);
            }
        }

        public static function tearDownAfterClass(): void
        {
            Idno::site()->config()->moderation_contact_email = null;
            Idno::site()->config()->moderation_notify_admins = null;
            Idno::site()->config()->save();
            parent::tearDownAfterClass();
            static::$author = static::$reporter = static::$admin = null;
        }

        public function testUnresolvedAndResolvedTabs()
        {
            $openMarker = static::marker();
            $doneMarker = static::marker();
            $open = $this->report(static::$reporter, 'post', $this->post(static::$author, 'Zebra open ' . $openMarker), 'spam', 'Why ' . $openMarker)['case'];
            $done = $this->report(static::$reporter, 'post', $this->post(static::$author, 'Zebra done ' . $doneMarker))['case'];
            $this->act($done, static::$admin, 'dismiss');

            $unresolved = $this->get('/admin/moderation/', static::$admin);
            $this->assertEquals(200, $unresolved['status']);
            $this->assertStringContainsString($openMarker, $unresolved['body']);
            $this->assertStringNotContainsString($doneMarker, $unresolved['body']);
            $this->assertStringContainsString('/admin/moderation/case/' . $open->getID(), $unresolved['body']);

            $resolved = $this->get('/admin/moderation/?tab=resolved', static::$admin);
            $this->assertStringContainsString($doneMarker, $resolved['body']);
            $this->assertStringNotContainsString($openMarker, $resolved['body']);
            $this->assertStringContainsString('Dismissed', $resolved['body']);

            $case = $this->get('/admin/moderation/case/' . $open->getID(), static::$admin);
            $this->assertEquals(200, $case['status']);
            foreach (['Why ' . $openMarker, static::$reporter->getTitle(), static::$author->getTitle(), 'Dismiss', 'Remove post', 'has filed', 'Moderator notes', 'Saved copy'] as $expected) {
                $this->assertStringContainsString($expected, $case['body']);
            }
            $this->assertStringContainsString('name="revision" value="' . static::freshCase($open)->revision . '"', $case['body']);
        }

        public function testUrgentCasesComeFirstThenTheOldest()
        {
            $old = $this->report(static::$reporter, 'post', $this->post(static::$author), 'spam')['case'];
            $newer = $this->report(static::$reporter, 'post', $this->post(static::$author), 'spam')['case'];
            $urgent = $this->report(static::$reporter, 'post', $this->post(static::$author), 'violence')['case'];
            // Make the order of arrival certain
            foreach ([[$old, 300], [$newer, 200], [$urgent, 100]] as [$case, $ago]) {
                $case = static::freshCase($case);
                $case->first_reported = time() - $ago;
                $case->save();
            }

            $ids = array_map(
                function ($case) {
                    return $case->getID();
                }, Moderation::cases(ModerationCase::OPEN, static::fresh(static::$admin))
            );

            $this->assertLessThan(array_search($old->getID(), $ids), array_search($urgent->getID(), $ids), 'An urgent case comes before older ones.');
            $this->assertLessThan(array_search($newer->getID(), $ids), array_search($old->getID(), $ids), 'Then the oldest first.');

            $page = $this->get('/admin/moderation/', static::$admin)['body'];
            $this->assertLessThan(strpos($page, 'case/' . $old->getID()), strpos($page, 'case/' . $urgent->getID()));
        }

        public function testBadgeCountsUnresolvedCases()
        {
            $count = Moderation::countOpen(static::fresh(static::$admin));
            $this->assertGreaterThan(0, $count);

            $page = $this->get('/admin/users/', static::$admin)['body'];

            $this->assertMatchesRegularExpression('#class="mod-badge"[^>]*>' . $count . '<#', $page, 'The admin menu shows how many reports are unresolved.');
        }

        public function testReportsOnOneThingAreOneItem()
        {
            $entry = $this->post(static::$author);
            $case = $this->report(static::$reporter, 'post', $entry)['case'];
            $this->report(static::member('queuesecond'), 'post', $entry, 'hate');

            $page = $this->get('/admin/moderation/', static::$admin)['body'];

            $this->assertEquals(1, substr_count($page, 'href="' . htmlspecialchars($case->getAdminURL()) . '"'));
            $this->assertStringContainsString('2 reports', $page);
        }

        public function testLogTabAndEmptyBannedTab()
        {
            $log = $this->get('/admin/moderation/?tab=log', static::$admin);
            $this->assertEquals(200, $log['status']);
            $this->assertStringContainsString('reported this', $log['body']);

            $banned = $this->get('/admin/moderation/?tab=banned', static::$admin);
            $this->assertEquals(200, $banned['status']);
        }

        public function testUsersPageLinksToTheMemberRecord()
        {
            $page = $this->get('/search/users/?query=' . static::$author->getHandle(), static::$admin);

            $this->assertStringContainsString('admin/moderation/member/' . static::$author->getID(), str_replace('\\/', '/', $page['body']));
        }

        public function testSettingsAreSaved()
        {
            $this->submit('/admin/moderation/settings', ['moderation_contact_email' => 'help@example.org'], static::$admin);

            $page = $this->get('/admin/moderation/settings', static::$admin);
            $this->assertStringContainsString('value="help@example.org"', $page['body']);
            $this->assertStringNotContainsString('name="moderation_notify_admins" value="1" checked', $page['body'], 'An unticked box turns the mails off.');
            $this->assertStringContainsString('legal duties', $page['body']);

            $this->submit('/admin/moderation/settings', ['moderation_contact_email' => 'not an address', 'moderation_notify_admins' => '1'], static::$admin);
            $this->assertStringContainsString('value="help@example.org"', $this->get('/admin/moderation/settings', static::$admin)['body'], 'A bad address is not saved.');
        }
    }

}
