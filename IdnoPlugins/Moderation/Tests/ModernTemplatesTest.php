<?php

namespace IdnoPlugins\Moderation\Tests {

    use IdnoPlugins\Moderation\Entities\ModerationCase;
    use IdnoPlugins\Moderation\Moderation;

    /**
     * Moderation in Twenty26.
     *
     * The plugin's pages are one set of templates for both looks (in templates/default), with
     * the plugin's own stylesheet and script: they use no Bootstrap, Font Awesome or jQuery.
     * Only the admin menu item differs, and has a Twenty26 template. This checks that what
     * Twenty26 draws for each page has no marks of the default look.
     */
    class ModernTemplatesTest extends \Tests\ModernTemplatesTestCase
    {

        protected function plugin(): string
        {
            return 'Moderation';
        }

        protected function modernTemplates(): array
        {
            return ['moderation/admin/menu'];
        }

        public function setUp(): void
        {
            parent::setUp();
            $_SERVER['REQUEST_URI'] = '/admin/moderation/';
        }

        public function tearDown(): void
        {
            foreach ([\IdnoPlugins\Moderation\Entities\Report::class, \IdnoPlugins\Moderation\Entities\LogEntry::class, ModerationCase::class] as $class) {
                foreach ($class::find([], 1000) as $record) {
                    $record->delete();
                }
            }
            parent::tearDown();
        }

        public function testTheAdminMenuItem()
        {
            $html = $this->drawModern('moderation/admin/menu');

            $this->assertIsTwenty26Markup($html, 'The admin menu item');
            $this->assertStringContainsString('idno-admin-nav-item', $html);
        }

        public function testTheMenuItemsTheReportFormAndTheQueue()
        {
            $status = new \IdnoPlugins\Status\Status();
            $status->body = 'A post to report <b>' . md5(rand()) . '</b>';
            $status->setOwner($this->user());
            $post = $this->keep($status);

            $this->assertIsTwenty26Markup($this->drawModern('content/menu', ['object' => $post]), 'The menu of a post');
            $this->assertStringContainsString('data-mod-report', $this->drawModern('content/menu', ['object' => $post]));

            $target = Moderation::resolveTarget('post', $post->getID());
            $this->assertNotEmpty($target);
            $form = $this->drawModern(
                'moderation/report', ['target' => $target, 'heading' => 'Report this post', 'error' => '', 'own' => false,
                'id' => $post->getID(), 'comment' => '', 'reason' => '', 'description' => '']
            );
            $this->assertIsTwenty26Markup($form, 'The report form');
            $this->assertStringContainsString('name="reason" value="spam"', $form);

            $result = Moderation::fileReport($this->admin(), 'post', $post->getID(), '', 'spam', 'Some <i>context</i>');
            $this->assertTrue($result['ok'], $result['message']);
            $case = ModerationCase::load($result['case']->getID());
            $vars = [
                'tab' => 'unresolved', 'case' => $case, 'cases' => [$case], 'open_count' => 1, 'banned_count' => 0, 'offset' => 0,
                'per_page' => 30, 'total' => 1, 'admin' => $this->admin(), 'only_admin' => false, 'reports' => $case->getReports(),
                'history' => [], 'case_log' => Moderation::logEntries(['case_id' => $case->getID()]), 'subject' => $case->getSubject(),
            ];
            $queue = $this->drawModern('moderation/admin/queue', $vars);
            $this->assertIsTwenty26Markup($queue, 'The queue with a case open');
            $this->assertStringContainsString('mod-case', $queue);
            $this->assertStringNotContainsString('<i>context</i>', $queue);

            $member = $this->drawModern(
                'moderation/admin/member', ['member' => $this->user(), 'admin' => $this->admin(), 'cases' => [$case],
                'reporter_record' => ['filed' => 0, 'dismissed' => 0], 'log' => [], 'open_count' => 1, 'banned_count' => 0]
            );
            $this->assertIsTwenty26Markup($member, "A member's record");

            $settings = $this->drawModern('moderation/admin/settings', ['contact' => '', 'effective_contact' => '', 'notify' => true, 'open_count' => 1, 'banned_count' => 0]);
            $this->assertIsTwenty26Markup($settings, 'The settings page');
        }
    }

}
