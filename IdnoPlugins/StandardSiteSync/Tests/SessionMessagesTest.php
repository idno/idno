<?php

namespace IdnoPlugins\StandardSiteSync\Tests {

    use IdnoPlugins\StandardSiteSync\Main;

    /**
     * A session message is printed as markup, so what a remote server, the request or an exception put in one is escaped first.
     */
    class SessionMessagesTest extends \Tests\IdnoTestCase
    {
        public function testWhatIsNotFixedTextIsEscaped(): void
        {
            $hostile = "\"><x-canary a='1";

            $message = Main::sessionMessage('Connected (%s).', $hostile);

            $this->assertSame('Connected (&quot;&gt;&lt;x-canary a=&#039;1).', $message);
            $this->assertStringNotContainsString('<x-canary', $message);
        }

        public function testTheTextAroundItIsLeftAlone(): void
        {
            $this->assertSame('Failed: <b>one</b> &amp; two', Main::sessionMessage('Failed: <b>one</b> &amp; %s', 'two'));
        }

        /**
         * Every message the plugin's pages add is fixed text, a number, or goes through Main::sessionMessage().
         */
        public function testEveryMessageThePagesAddIsFixedTextOrEscaped(): void
        {
            $found = 0;
            foreach (glob(dirname(__DIR__) . '/Pages/*.php') ?: [] as $file) {
                $source = (string) file_get_contents($file);
                preg_match_all('/session\(\)->add(?:Error)?Message\(\s*(.*?)\s*\);/s', $source, $calls);
                foreach ($calls[1] as $argument) {
                    ++$found;
                    $this->assertTrue(
                        preg_match("/^'[^'\\\$]*'\$/", $argument) === 1 || preg_match('/^(?:\\\\IdnoPlugins\\\\StandardSiteSync\\\\)?Main::sessionMessage\\(/', $argument) === 1 || preg_match("/^'[^']*' \\. \\\$count \\. '[^']*'\$/", $argument) === 1,
                        basename($file) . ': ' . $argument
                    );
                }
            }
            $this->assertGreaterThan(5, $found, 'the pages were found');
        }
    }
}
