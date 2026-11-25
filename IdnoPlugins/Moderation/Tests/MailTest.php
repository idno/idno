<?php

namespace IdnoPlugins\Moderation\Tests {

    use Idno\Core\Idno;
    use IdnoPlugins\Moderation\Mailer;

    /**
     * Who is mailed what. A reporter never learns which administrator acted or what was
     * done; the member a report is about never learns who reported them; and what a
     * reporter typed is escaped in the HTML part of the mail to administrators.
     */
    class MailTest extends ModerationTestCase
    {

        private static $author;
        private static $reporter;
        private static $second;
        private static $admin;

        public function setUp(): void
        {
            parent::setUp();
            if (!static::$author) {
                static::$author = static::member('mailauthor');
                static::$reporter = static::member('mailreporter');
                static::$second = static::member('mailsecond');
                static::$admin = static::member('mailadmin', true);
            }
            Idno::site()->config()->moderation_notify_admins = 'true';
            Idno::site()->config()->moderation_contact_email = 'appeals@example.com';
        }

        public static function tearDownAfterClass(): void
        {
            Idno::site()->config()->moderation_notify_admins = null;
            Idno::site()->config()->moderation_contact_email = null;
            parent::tearDownAfterClass();
            static::$author = static::$reporter = static::$second = static::$admin = null;
        }

        private function to($mails)
        {
            return array_map(
                function ($mail) {
                    return $mail['to'];
                }, $mails
            );
        }

        private function assertNames($mail, $person, $message)
        {
            foreach (['html', 'text', 'subject'] as $part) {
                foreach ([$person->getTitle(), $person->getHandle(), $person->email, $person->getUUID()] as $identity) {
                    $this->assertStringNotContainsString($identity, $mail[$part], $message);
                }
            }
        }

        public function testReporterIsThankedAndAdministratorsAreTold()
        {
            $entry = $this->post(static::$author);
            $this->report(static::$reporter, 'post', $entry, 'spam', 'Some context');

            $this->assertEquals([static::$reporter->email], $this->to($this->mails('received')));
            $told = $this->to($this->mails('new-report'));
            $this->assertContains(static::$admin->email, $told);
            $this->assertNotContains(static::$author->email, $told);
            $this->assertNotContains(static::$reporter->email, $told);
            $mail = $this->mails('new-report')[0];
            $this->assertStringContainsString('Spam', $mail['subject']);
            $this->assertStringNotContainsString('Urgent', $mail['subject']);
            $this->assertStringContainsString('Some context', $mail['text']);
            $this->assertStringContainsString('/admin/moderation/case/', $mail['text']);
            $this->assertStringContainsString(static::$author->getTitle(), $this->mails('received')[0]['text']);
        }

        public function testUrgentReasonIsMarkedInTheSubject()
        {
            $this->report(static::$reporter, 'post', $this->post(static::$author), 'self-harm');

            $this->assertStringStartsWith('Urgent', $this->mails('new-report')[0]['subject']);
        }

        public function testAdministratorsAreNotMailedWhenTheSettingIsOff()
        {
            Idno::site()->config()->moderation_notify_admins = 'false';

            $this->report(static::$reporter, 'post', $this->post(static::$author));

            $this->assertEmpty($this->mails('new-report'));
            $this->assertCount(1, $this->mails('received'));
        }

        public function testAnAdministratorIsNotToldAboutAReportOnThemself()
        {
            $other = static::member('mailadmintwo', true);
            $this->report(static::$reporter, 'profile', $other, 'harassment');

            $told = $this->to($this->mails('new-report'));
            $this->assertNotContains($other->email, $told, 'An administrator must not be mailed about a report on themself.');
            $this->assertContains(static::$admin->email, $told);
        }

        public function testHostileDescriptionIsEscapedInHtmlAndLiteralInText()
        {
            $hostile = '<script>alert(1)</script><img src=x onerror=alert(2)> "quoted" & more';
            // The stored text has no tags; what reaches the mail must be escaped all the same
            $mail = Mailer::compose('new-report', ['reason' => 'other', 'description' => $hostile]);

            $this->assertStringNotContainsString('<script>', $mail['html']);
            $this->assertStringNotContainsString('<img src=x', $mail['html']);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $mail['html']);
            $this->assertStringContainsString('&quot;quoted&quot; &amp; more', $mail['html']);
            $this->assertStringContainsString($hostile, $mail['text']);
        }

        public function testHostileNameOfTheReportedMemberIsEscaped()
        {
            $villain = static::member('mailvillain');
            $villain->setTitle('Eve <img src=x onerror=alert(3)>');
            $villain->save();
            $this->report(static::$reporter, 'profile', $villain, 'spam');

            foreach (array_merge($this->mails('received'), $this->mails('new-report')) as $mail) {
                $this->assertStringNotContainsString('<img', $mail['html']);
            }
        }

        public function testDismissalTellsEachReporterOnceThatNoActionWasTaken()
        {
            $entry = $this->post(static::$author);
            $case = $this->report(static::$reporter, 'post', $entry)['case'];
            $this->report(static::$second, 'post', $entry, 'harassment');
            \IdnoPlugins\Moderation\Mailer::$capture = [];

            $this->assertTrue($this->act($case, static::$admin, 'dismiss')['ok']);

            $reviewed = $this->mails('reviewed');
            $told = $this->to($reviewed);
            sort($told);
            $expected = [static::$reporter->email, static::$second->email];
            sort($expected);
            $this->assertEquals($expected, $told);
            foreach ($reviewed as $mail) {
                $this->assertStringContainsString('no action was taken', $mail['text']);
                $this->assertNames($mail, static::$admin, 'A reporter must not learn which administrator acted.');
            }
            $this->assertCount(2, $this->mails(), 'A dismissal mails the reporters and nobody else: the reported member is not told.');
        }

        public function testRemovalTellsReportersOnlyThatActionWasTaken()
        {
            $marker = static::marker();
            $entry = $this->post(static::$author, 'Zebra ' . $marker);
            $case = $this->report(static::$reporter, 'post', $entry, 'hate', 'reporterwords' . $marker)['case'];
            \IdnoPlugins\Moderation\Mailer::$capture = [];

            $this->assertTrue($this->act($case, static::$admin, 'remove')['ok']);

            $reviewed = $this->mails('reviewed');
            $this->assertEquals([static::$reporter->email], $this->to($reviewed));
            $this->assertStringContainsString('have taken action', $reviewed[0]['text']);
            $this->assertStringNotContainsString('remov', strtolower($reviewed[0]['text']), 'The reporter is not told what the action was.');
            $this->assertNames($reviewed[0], static::$admin, 'A reporter must not learn which administrator acted.');

            $removed = $this->mails('content-removed');
            $this->assertEquals([static::$author->email], $this->to($removed));
            $this->assertStringContainsString('Hate speech', $removed[0]['text']);
            $this->assertStringContainsString('appeals@example.com', $removed[0]['text']);
            $this->assertStringContainsString($marker, $removed[0]['text'], 'The member is shown which post it was.');
            $this->assertStringNotContainsString('reporterwords', $removed[0]['text'], 'The member must not be sent what the reporter wrote.');
            $this->assertStringNotContainsString('reporterwords', $removed[0]['html']);
            $this->assertNames($removed[0], static::$reporter, 'The member must not learn who reported them.');
            $this->assertNames($removed[0], static::$admin, 'The member must not learn which administrator acted.');
        }

        public function testBanMailsTheMemberOnlyWhenAsked()
        {
            $target = static::member('mailtarget');
            $case = $this->report(static::$reporter, 'profile', $target, 'violence')['case'];
            \IdnoPlugins\Moderation\Mailer::$capture = [];

            $this->assertTrue($this->act($case, static::$admin, 'ban', ['reason' => 'internalreason' . static::marker(), 'notify' => true])['ok']);

            $banned = $this->mails('banned');
            $this->assertEquals([$target->email], $this->to($banned));
            $this->assertStringContainsString('Violence or threats', $banned[0]['text']);
            $this->assertStringContainsString('appeals@example.com', $banned[0]['text']);
            $this->assertStringNotContainsString('internalreason', $banned[0]['text'], "The moderators' own reason is not sent.");
            $this->assertNames($banned[0], static::$reporter, 'The member must not learn who reported them.');
            $this->assertNames($banned[0], static::$admin, 'The member must not learn which administrator acted.');
            $this->assertEquals([static::$reporter->email], $this->to($this->mails('reviewed')));
            $this->assertStringNotContainsString('banned', strtolower($this->mails('reviewed')[0]['text']), 'The reporter is not told what the action was.');

            \IdnoPlugins\Moderation\Mailer::$capture = [];
            $this->assertTrue($this->act($case, static::$admin, 'unban')['ok']);
            $this->assertEquals([$target->email], $this->to($this->mails('ban-lifted')));

            $quiet = static::member('mailquiet');
            $case = $this->report(static::$reporter, 'profile', $quiet)['case'];
            \IdnoPlugins\Moderation\Mailer::$capture = [];
            $this->assertTrue($this->act($case, static::$admin, 'ban', ['notify' => false])['ok']);
            $this->assertEmpty($this->mails('banned'));
        }

        public function testRestoreTellsTheMemberAndReopenTellsNobody()
        {
            $entry = $this->post(static::$author);
            $case = $this->report(static::$reporter, 'post', $entry)['case'];
            $this->act($case, static::$admin, 'remove');
            \IdnoPlugins\Moderation\Mailer::$capture = [];

            $this->assertTrue($this->act($case, static::$admin, 'restore')['ok']);
            $this->assertEquals([static::$author->email], $this->to($this->mails()));
            $this->assertEquals('restored', $this->mails()[0]['kind']);

            $other = $this->report(static::$second, 'post', $this->post(static::$author))['case'];
            $this->act($other, static::$admin, 'dismiss');
            \IdnoPlugins\Moderation\Mailer::$capture = [];
            $this->assertTrue($this->act($other, static::$admin, 'reopen')['ok']);
            $this->assertEmpty($this->mails());

            // Dismissed again: the reporter already knows, and is not told twice
            $this->assertTrue($this->act($other, static::$admin, 'dismiss')['ok']);
            $this->assertEmpty($this->mails('reviewed'));
        }

        public function testAPostWhoseAuthorIsGoneCanBeReportedAndRemoved()
        {
            $ghost = static::member('mailghost');
            $entry = $this->post($ghost);
            $ghost->email = '';
            $entry->owner = 'http://example.invalid/nobody';
            Idno::site()->db()->saveObject($entry);

            $result = $this->report(static::member('mailfresh'), 'post', \Idno\Common\Entity::getByUUID($entry->getUUID(), false));
            $this->assertTrue($result['ok'], $result['message']);
            $this->assertEquals('', $result['case']->subject_uuid);

            $this->assertTrue($this->act($result['case'], static::$admin, 'remove')['ok']);
            $this->assertEmpty($this->mails('content-removed'), 'There is nobody to tell.');
            $this->assertFalse($this->act($result['case'], static::$admin, 'ban')['ok']);
        }
    }

}
