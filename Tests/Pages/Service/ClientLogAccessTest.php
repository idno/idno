<?php

namespace Tests\Pages\Service {

    use Idno\Pages\Service\System\Log;

    /**
     * The page that lets the site's own scripts report a problem writes what it is sent to the
     * site's log. Only a logged-in member's script may, and what it sends is logged as one line
     * of bounded length.
     *
     * The requests are made over HTTP, so these tests need the site to be running (see
     * IDNO_DOMAIN / IDNO_PORT).
     */
    class ClientLogAccessTest extends \Tests\BrowserTestCase
    {

        private function report($jar, array $headers = ['X-Requested-With: XMLHttpRequest'])
        {
            $fields = $this->tokenFields($jar, '/service/system/log/') + ['level' => 'ERROR', 'message' => 'Test report ' . static::marker()];

            return $this->requestWithJar($jar, 'POST', '/service/system/log/', $fields, $headers);
        }

        public function testAVisitorCannotWriteToTheLog()
        {
            $answer = $this->report($this->visitorJar());

            $this->assertEquals(403, $answer['status'], 'A visitor with a valid form token should be refused.');
        }

        public function testAMembersScriptCanReportAProblem()
        {
            $member = static::member('logger');

            $answer = $this->report($this->jar($member));
            $this->assertEquals(200, $answer['status'], 'The script of a logged-in member should be able to report a problem.');

            $answer = $this->report($this->jar($member), []);
            $this->assertEquals(403, $answer['status'], 'The page answers scripts only, as before.');
        }

        public function testAMessageIsLoggedAsOneBoundedLine()
        {
            $this->assertSame('one?two??three?', Log::clean("one\ntwo\r\nthree\x00"), 'A line break or another control character must not reach the log.');
            $this->assertSame('', Log::clean(['an' => 'array']), 'Anything that is not text is logged as nothing.');
            $this->assertSame(Log::MAX_LENGTH, mb_strlen(Log::clean(str_repeat('é', 5000))), 'A long message is cut.');
            $this->assertSame('plain words', Log::clean('plain words'));
        }

        /**
         * The text is the client's, so it must never be what mails the administrators.
         */
        public function testAReportDoesNotMailTheAdministrators()
        {
            $source = file_get_contents((new \ReflectionClass(Log::class))->getFileName());

            $this->assertStringNotContainsString('oopsAlert', $source, 'A report from a client must not send an error mail.');
        }
    }

}
