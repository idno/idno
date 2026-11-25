<?php

namespace IdnoPlugins\Moderation\Tests {

    use Idno\Common\Entity;
    use Idno\Core\Idno;
    use IdnoPlugins\Moderation\Entities\LogEntry;
    use IdnoPlugins\Moderation\Entities\ModerationCase;

    /**
     * Cases, reports and log entries are for administrators only. Nothing a visitor, a
     * member, the reporter or the reported member can fetch gives them out, or says who
     * reported whom.
     */
    class RecordPrivacyTest extends ModerationTestCase
    {

        private static $author;
        private static $reporter;
        private static $admin;
        private static $entry;
        private static $case;
        private static $report;
        private static $marker;

        public function setUp(): void
        {
            parent::setUp();
            if (!static::$author) {
                static::$author = static::member('privauthor');
                static::$reporter = static::member('privreporter');
                static::$admin = static::member('privadmin', true);
                static::$marker = static::marker();
                static::$entry = $this->post(static::$author, 'Zebra one two three four five six seven eight nine ten eleven twelve');
                static::$case = $this->report(static::$reporter, 'post', static::$entry, 'harassment', 'reporterwords ' . static::$marker)['case'];
                static::$report = static::$case->getReports()[0];
            }
        }

        public static function tearDownAfterClass(): void
        {
            parent::tearDownAfterClass();
            static::$author = static::$reporter = static::$admin = static::$entry = static::$case = static::$report = static::$marker = null;
        }

        public static function viewers()
        {
            return ['a visitor' => [null], 'the reported member' => ['author'], 'the reporter' => ['reporter']];
        }

        private function secrets()
        {
            return [static::$marker, 'reporterwords'];
        }

        /**
         * @dataProvider viewers
         */
        public function testRecordsCannotBeFetchedByTheirAddress($who)
        {
            $as = $who ? static::$$who : null;
            $log = LogEntry::find(['case_id' => ((string) static::$case->getID())])[0];

            foreach ([static::$case, static::$report, $log] as $record) {
                foreach (['/view/' . ((string) $record->getID()), '/view/' . ((string) $record->getID()) . '?_t=json', '/view/' . ((string) $record->getID()) . '?_t=jsonp', '/view/' . ((string) $record->getID()) . '?_t=rss', '/' . date('Y') . '/' . $record->getSlug()] as $path) {
                    $result = $this->get($path, $as);
                    $this->assertNotEquals(200, $result['status'], "$path must not be served.");
                    foreach ($this->secrets() as $secret) {
                        $this->assertStringNotContainsString($secret, $result['body'], "$path must not give out a report.");
                    }
                    if ($who !== 'reporter') {
                        $this->assertStringNotContainsString(static::$reporter->getHandle(), $result['body'], "$path must not say who reported.");
                    }
                }
            }
        }

        /**
         * @dataProvider viewers
         */
        public function testNothingTheReportedMemberCanFetchNamesTheReporter($who)
        {
            $as = $who ? static::$$who : null;
            $pages = [
                static::$entry->getDisplayURL(), static::$entry->getDisplayURL() . '?_t=json', static::$entry->getDisplayURL() . '?_t=rss',
                static::$author->getDisplayURL(), static::$author->getDisplayURL() . '?_t=json',
                '/', '/?_t=json', '/?_t=rss', '/content/all/', '/content/all/?_t=json',
                '/search/?q=reporterwords', '/search/?q=' . static::$marker, '/search/?q=reporterwords&_t=json',
                '/account/settings/', '/account/settings/notifications/',
            ];
            foreach ($pages as $page) {
                $result = $this->get($page, $as);
                foreach ($this->secrets() as $secret) {
                    $this->assertStringNotContainsString($secret, $result['body'], "$page must not give out what a reporter wrote.");
                }
                if ($who !== 'reporter') {
                    $this->assertStringNotContainsString(static::$reporter->getHandle(), $result['body'], "$page must not say who reported.");
                    $this->assertStringNotContainsString(static::$reporter->getTitle(), $result['body'], "$page must not say who reported.");
                }
                $this->assertStringNotContainsString('admin/moderation/case', $result['body']);
            }
        }

        public function testRecordsAreNotFoundByMembersInThisProcess()
        {
            foreach ([null, static::$author, static::$reporter] as $viewer) {
                $old = $this->swapUser($viewer);
                try {
                    $this->assertEmpty(ModerationCase::get(['target_key' => static::$case->target_key]), 'A lookup made as a member must find no case.');
                    $found = Entity::getByUUID(static::$case->getUUID(), false);
                    $this->assertTrue(empty($found) || !$found->canRead(), 'A case must not be readable by a member.');
                    foreach (Entity::getFromAll([], [], 200) ?: [] as $entity) {
                        $this->assertNotInstanceOf(\IdnoPlugins\Moderation\Entities\Record::class, $entity);
                    }
                    $this->assertFalse(static::$case->canRead());
                    $this->assertFalse(static::$report->canEdit());
                } finally {
                    $this->swapUser($old);
                }
            }
        }

        public function testRecordsSerialiseToNothing()
        {
            $this->assertEquals('[]', json_encode(static::$case));
            $this->assertEquals('[]', json_encode(static::$report));
            $this->assertFalse(static::$case->getActivityStreamsObjectType());
            $this->assertStringNotContainsString('reporterwords', static::$report->getSlug());
        }

        public function testDeletingTheReportersAccountKeepsTheCase()
        {
            $leaver = static::member('privleaver');
            $case = $this->report($leaver, 'post', $this->post(static::$author), 'spam')['case'];

            $old = $this->swapUser(static::$admin);
            static::fresh($leaver)->delete();
            $this->swapUser($old);

            $this->assertNotEmpty(static::freshCase($case), 'A case must outlive the account of the member who reported.');
            $this->assertCount(1, static::freshCase($case)->getReports());
        }

        public function testAnAdministratorCanReadTheCase()
        {
            $result = $this->get('/admin/moderation/case/' . ((string) static::$case->getID()), static::$admin);

            $this->assertEquals(200, $result['status']);
            $this->assertStringContainsString(static::$marker, $result['body']);
            $this->assertStringContainsString(static::$reporter->getTitle(), $result['body']);
        }
    }

}
