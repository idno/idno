<?php

namespace IdnoPlugins\Moderation\Tests {

    use Idno\Core\Idno;
    use IdnoPlugins\Moderation\Entities\ModerationCase;
    use IdnoPlugins\Moderation\Entities\Report;
    use IdnoPlugins\Moderation\Moderation;

    /**
     * Who is offered "Report", and what filing a report does and refuses.
     */
    class ReportingTest extends ModerationTestCase
    {

        private static $author;
        private static $reader;
        private static $admin;
        private static $entry;
        private static $commentUrl;

        public function setUp(): void
        {
            parent::setUp();
            if (!static::$author) {
                static::$author = static::member('repauthor');
                static::$reader = static::member('repreader');
                static::$admin = static::member('repadmin', true);
                static::$entry = $this->post(static::$author);
                static::$commentUrl = $this->comment(static::$entry, static::$author);
            }
        }

        public static function tearDownAfterClass(): void
        {
            parent::tearDownAfterClass();
            static::$author = static::$reader = static::$admin = static::$entry = static::$commentUrl = null;
        }

        // -----------------------------------------------------------------
        // Who is offered "Report"
        // -----------------------------------------------------------------

        public function testAnotherMemberIsOfferedReportOnPostCommentAndProfile()
        {
            $page = $this->get(static::$entry->getDisplayURL(), static::$reader)['body'];
            $this->assertStringContainsString('type=post&amp;id=' . static::$entry->getID(), $page);
            $this->assertStringContainsString('type=comment&amp;id=' . static::$entry->getID() . '&amp;comment=' . basename(static::$commentUrl), $page);
            $this->assertStringContainsString('type=profile&amp;id=' . static::$author->getID(), $page);

            $profile = $this->get(static::$author->getDisplayURL(), static::$reader)['body'];
            $this->assertStringContainsString('type=profile&amp;id=' . static::$author->getID(), $profile);
        }

        public function testNobodyIsOfferedReportOnTheirOwnThings()
        {
            foreach ([static::$entry->getDisplayURL(), static::$author->getDisplayURL()] as $url) {
                $page = $this->get($url, static::$author)['body'];
                $this->assertStringContainsString('data-idno-more-menu', $page);
                $this->assertStringNotContainsString('moderation/report', $page, "Nobody should be offered a report on their own things ($url).");
            }
        }

        public function testVisitorsAreNotOfferedReport()
        {
            foreach ([static::$entry->getDisplayURL(), static::$author->getDisplayURL()] as $url) {
                $page = $this->get($url)['body'];
                $this->assertStringContainsString('data-idno-copy-link', $page);
                $this->assertStringNotContainsString('moderation/report', $page);
            }
        }

        // -----------------------------------------------------------------
        // The form
        // -----------------------------------------------------------------

        public function testFormShowsTheReasonsAndWhatIsReported()
        {
            $marker = static::marker();
            $entry = $this->post(static::$author, 'Zebra one two three four five six seven eight nine ten eleven twelve ' . $marker . ' <b>bold</b>');

            $result = $this->get($this->reportPath('post', $entry), static::$reader);

            $this->assertEquals(200, $result['status']);
            foreach (array_keys(\IdnoPlugins\Moderation\Reasons::all()) as $slug) {
                $this->assertStringContainsString('name="reason" value="' . $slug . '"', $result['body']);
            }
            $this->assertCount(12, \IdnoPlugins\Moderation\Reasons::all());
            $this->assertStringContainsString($marker, $result['body']);
            $this->assertStringContainsString('be told who reported them', $result['body']);
        }

        public function testFormIsRefusedToVisitorsAndApiRequests()
        {
            $path = $this->reportPath('post', static::$entry);

            $this->assertEquals(403, $this->get($path)['status']);
            $this->assertEquals(403, $this->get($path, null, $this->signature(static::$reader, $path))['status'], 'A request signed with an API key must not reach the report form.');
        }

        public function testFormForAThingThatDoesNotExistIsNotFound()
        {
            $this->assertEquals(404, $this->get('/moderation/report?type=post&id=doesnotexist', static::$reader)['status']);
            $this->assertEquals(404, $this->get('/moderation/report?type=nonsense&id=' . static::$entry->getID(), static::$reader)['status']);
            $this->assertEquals(404, $this->get('/moderation/report?type=comment&id=' . static::$entry->getID() . '&comment=0000', static::$reader)['status']);
        }

        // -----------------------------------------------------------------
        // Filing
        // -----------------------------------------------------------------

        public function testMemberReportsAPostOverHttp()
        {
            $entry = $this->post(static::$author);
            $path = $this->reportPath('post', $entry);

            $result = $this->submit('/moderation/report', ['type' => 'post', 'id' => $entry->getID(), 'reason' => 'harassment', 'description' => 'Look at this'], static::$reader, $path);

            $this->assertEquals(302, $result['status']);
            $case = Moderation::openCaseFor(ModerationCase::keyFor('post', $entry->getUUID()));
            $this->assertNotEmpty($case, 'A case should be open for the post.');
            $this->assertEquals('post', $case->target_type);
            $this->assertEquals(static::$author->getUUID(), $case->subject_uuid);
            $this->assertEquals(1, $case->report_count);
            $this->assertEquals(['harassment' => 1], $case->reasons);
            $this->assertEmpty($case->urgent);
            $this->assertStringContainsString('Post marker', $case->snapshot['text']);
            $reports = $case->getReports();
            $this->assertCount(1, $reports);
            $this->assertEquals(static::$reader->getUUID(), $reports[0]->reporter_uuid);
            $this->assertEquals('Look at this', $reports[0]->description);
        }

        public function testJsonResponseForTheDialog()
        {
            $entry = $this->post(static::$author);
            $path = $this->reportPath('post', $entry);

            $result = $this->submit('/moderation/report', ['type' => 'post', 'id' => $entry->getID(), 'reason' => 'spam', 'response' => 'json'], static::$reader, $path);

            $this->assertEquals(200, $result['status']);
            $this->assertEquals(true, json_decode($result['body'], true)['ok']);

            $bad = $this->submit('/moderation/report', ['type' => 'post', 'id' => $entry->getID(), 'reason' => 'nonsense', 'response' => 'json'], static::$reader, $path);
            $this->assertEquals(400, $bad['status']);
            $this->assertEquals(false, json_decode($bad['body'], true)['ok']);
        }

        public function testReportingACommentAndAProfile()
        {
            $entry = $this->post(static::$author);
            $local = $this->comment($entry, static::$author, 'A remark ' . static::marker());

            $result = $this->report(static::$reader, 'comment', $entry, 'hate', '', basename($local));
            $this->assertTrue($result['ok'], $result['message']);
            $this->assertEquals('comment', $result['case']->target_type);
            $this->assertEquals($local, $result['case']->target_local);
            $this->assertEquals(static::$author->getUUID(), $result['case']->subject_uuid);
            $this->assertStringContainsString('A remark', $result['case']->snapshot['text']);
            $this->assertStringEndsWith('#annotation-' . basename($local), $result['case']->snapshot['url']);

            $other = static::member('repother');
            $result = $this->report(static::$reader, 'profile', $other, 'impersonation');
            $this->assertTrue($result['ok'], $result['message']);
            $this->assertEquals('profile', $result['case']->target_type);
            $this->assertEquals($other->getUUID(), $result['case']->subject_uuid);
        }

        public function testUrgentReasonMarksTheCase()
        {
            $entry = $this->post(static::$author);
            $result = $this->report(static::$reader, 'post', $entry, 'child-safety');

            $this->assertTrue($result['case']->urgent);
        }

        public function testRefusals()
        {
            $entry = $this->post(static::$author);
            $private = $this->post(static::$author, null, static::$author->getUUID());
            $local = $this->comment($entry, static::$reader);

            $cases = [
                'an unknown reason' => [static::$reader, 'post', $entry, 'nonsense', '', 400],
                'your own post' => [static::$author, 'post', $entry, 'spam', '', 400],
                'your own profile' => [static::$reader, 'profile', static::$reader, 'spam', '', 400],
                'your own comment' => [static::$reader, 'comment', $entry, 'spam', basename($local), 400],
                'a post you cannot read' => [static::$reader, 'post', $private, 'spam', '', 404],
                'a member as a post' => [static::$reader, 'post', static::$author, 'spam', '', 404],
                'a post as a profile' => [static::$reader, 'profile', $entry, 'spam', '', 404],
            ];
            foreach ($cases as $what => [$reporter, $type, $target, $reason, $comment, $code]) {
                $result = $this->report($reporter, $type, $target, $reason, '', $comment);
                $this->assertFalse($result['ok'], "A report about $what should be refused.");
                $this->assertEquals($code, $result['code'], $what);
                $this->assertNotEmpty($result['message']);
            }
            $this->assertEmpty(Moderation::openCaseFor(ModerationCase::keyFor('post', $entry->getUUID())));
            $this->assertEmpty($this->mails());
        }

        public function testReportNeedsAFormTokenAndASignedInBrowser()
        {
            $entry = $this->post(static::$author);
            $fields = ['type' => 'post', 'id' => $entry->getID(), 'reason' => 'spam'];

            $this->request('POST', '/moderation/report', $fields, static::$reader);
            $this->request('POST', '/moderation/report', $fields);
            $this->request('POST', '/moderation/report', $fields, null, $this->signature(static::$reader, '/moderation/report'));

            $this->assertEmpty(Moderation::openCaseFor(ModerationCase::keyFor('post', $entry->getUUID())), 'A report without a form token, from a visitor, or signed with an API key must not be filed.');
        }

        public function testReportingAgainAddsToTheSameReport()
        {
            $entry = $this->post(static::$author);

            $first = $this->report(static::$reader, 'post', $entry, 'spam', 'First');
            $second = $this->report(static::$reader, 'post', $entry, 'harassment', 'Second');

            $this->assertTrue($first['new']);
            $this->assertTrue($second['ok']);
            $this->assertFalse($second['new']);
            $this->assertEquals($first['case']->getID(), $second['case']->getID());
            $case = static::freshCase($first['case']);
            $this->assertEquals(1, $case->report_count, 'The same member reporting again is still one report.');
            $reports = $case->getReports();
            $this->assertCount(1, $reports);
            $this->assertEquals('harassment', $reports[0]->reason);
            $this->assertCount(2, $reports[0]->entries);
            $this->assertCount(1, $this->mails('received'), 'The reporter is thanked once.');
        }

        public function testSeveralMembersReportingMakeOneCase()
        {
            $entry = $this->post(static::$author);
            $third = static::member('repthird');

            $first = $this->report(static::$reader, 'post', $entry, 'spam');
            $second = $this->report($third, 'post', $entry, 'violence');

            $this->assertEquals($first['case']->getID(), $second['case']->getID());
            $case = static::freshCase($first['case']);
            $this->assertEquals(2, $case->report_count);
            $this->assertEquals(['spam' => 1, 'violence' => 1], $case->reasons);
            $this->assertTrue($case->urgent);
        }

        public function testRateLimit()
        {
            $busy = static::member('repbusy');
            for ($i = 0; $i < Moderation::RATE_LIMIT; $i++) {
                $this->assertTrue($this->report($busy, 'post', $this->post(static::$author))['ok']);
            }
            $extra = $this->post(static::$author);

            $result = $this->report($busy, 'post', $extra);

            $this->assertFalse($result['ok'], 'The eleventh report in an hour should be refused.');
            $this->assertEquals(429, $result['code']);
            $this->assertEmpty(Moderation::openCaseFor(ModerationCase::keyFor('post', $extra->getUUID())));
        }

        public function testANewReportAfterACaseIsResolvedOpensANewCase()
        {
            $entry = $this->post(static::$author);
            $first = $this->report(static::$reader, 'post', $entry);
            $this->assertTrue($this->act($first['case'], static::$admin, 'dismiss')['ok']);

            $second = $this->report(static::$reader, 'post', $entry);

            $this->assertTrue($second['ok']);
            $this->assertTrue($second['new']);
            $this->assertNotEquals($first['case']->getID(), $second['case']->getID());
        }

        // -----------------------------------------------------------------
        // The reporter's words
        // -----------------------------------------------------------------

        public function testDescriptionIsStoredAsPlainTextOfLimitedLength()
        {
            $this->assertEquals('', Moderation::cleanDescription("  \n\t  "));
            $this->assertEquals('', Moderation::cleanDescription(['not', 'a string']));
            $this->assertEquals('alert(1) bold', Moderation::cleanDescription("<script>alert(1)</script> <b>bold</b>\x00\x07"));
            $this->assertEquals("one\n\ntwo", Moderation::cleanDescription("one\r\n\r\n\r\n\r\ntwo"));

            $long = Moderation::cleanDescription(str_repeat('é😀', 5000));
            $this->assertEquals(Moderation::DESCRIPTION_LENGTH, mb_strlen($long));
            $this->assertTrue(mb_check_encoding($long, 'UTF-8'), 'The text must be cut between characters.');
            $this->assertEquals(1000, Moderation::DESCRIPTION_LENGTH);
        }

        public function testHostileDescriptionIsNotMarkupOnTheAdminPage()
        {
            $entry = $this->post(static::$author);
            $marker = static::marker();
            $result = $this->report(static::$reader, 'post', $entry, 'other', '"><img src=x onerror=alert(1)> &lt;b&gt; ' . $marker);

            $page = $this->get('/admin/moderation/case/' . $result['case']->getID(), static::$admin);

            $this->assertEquals(200, $page['status']);
            $this->assertStringContainsString($marker, $page['body']);
            $this->assertStringNotContainsString('onerror=alert(1)>', $page['body']);
            $this->assertStringNotContainsString('<img src=x', $page['body']);
        }

        public function testAReportIsNotWrittenToTheLog()
        {
            $entry = $this->post(static::$author);
            $marker = static::marker();
            $log = ini_get('error_log');
            $file = tempnam(sys_get_temp_dir(), 'idno-moderation-log-');
            ini_set('error_log', $file);
            try {
                $this->report(static::$reader, 'post', $entry, 'other', 'Secret words ' . $marker);
            } finally {
                ini_set('error_log', (string) $log);
            }
            $this->assertStringNotContainsString($marker, (string) file_get_contents($file));
            unlink($file);
        }
    }

}
