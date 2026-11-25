<?php

namespace IdnoPlugins\Moderation\Tests {

    use Idno\Core\Idno;
    use IdnoPlugins\Moderation\Entities\LogEntry;
    use IdnoPlugins\Moderation\Entities\ModerationCase;
    use IdnoPlugins\Moderation\Entities\Report;
    use IdnoPlugins\Moderation\Mailer;
    use IdnoPlugins\Moderation\Moderation;

    /**
     * What the tests of the Moderation plugin share: mails are captured instead of sent, and
     * every moderation record is deleted when a class is done.
     *
     * Most requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT) with the Moderation plugin enabled.
     */
    abstract class ModerationTestCase extends \Tests\BrowserTestCase
    {

        public function setUp(): void
        {
            if (!Idno::site()->plugins()->get('Moderation')) {
                $this->markTestSkipped('The Moderation plugin is not enabled.');
            }
            Mailer::$capture = [];
        }

        public function tearDown(): void
        {
            Mailer::$capture = null;
        }

        public static function tearDownAfterClass(): void
        {
            static::purgeRecords();
            parent::tearDownAfterClass();
        }

        protected static function purgeRecords()
        {
            foreach ([Report::class, LogEntry::class, ModerationCase::class] as $class) {
                foreach ($class::find([], 10000) as $record) {
                    Moderation::unrestricted(
                        function () use ($record) {
                            $old = Idno::site()->session()->currentUser();
                            $record->delete();
                        }
                    );
                }
            }
        }

        /**
         * File a report in this process. Returns the result of Moderation::fileReport().
         */
        protected function report($reporter, $type, $target, $reason = 'spam', $description = '', $comment = '')
        {
            $old = $this->swapUser($reporter);
            $result = Moderation::fileReport($reporter, $type, ((string) $target->getID()), $comment, $reason, $description);
            $this->swapUser($old);

            return $result;
        }

        /**
         * Act on a case in this process, as an administrator
         */
        protected function act($case, $admin, $action, array $options = [])
        {
            $old = $this->swapUser($admin);
            $fresh = ModerationCase::load(((string) $case->getID()));
            $result = Moderation::act($fresh, $admin, $action, $options + ['revision' => $fresh->revision]);
            $this->swapUser($old);

            return $result;
        }

        protected static function freshCase($case)
        {
            return ModerationCase::load(((string) $case->getID()));
        }

        /**
         * The captured mails of one kind
         */
        protected function mails($kind = null)
        {
            return array_values(
                array_filter(
                    Mailer::$capture ?? [], function ($mail) use ($kind) {
                        return $kind === null || $mail['kind'] === $kind;
                    }
                )
            );
        }

        /**
         * The path (with query string) of the report form for a target
         */
        protected function reportPath($type, $target, $comment = '')
        {
            return '/moderation/report?' . http_build_query(array_filter(['type' => $type, 'id' => ((string) $target->getID()), 'comment' => $comment]));
        }
    }

}
