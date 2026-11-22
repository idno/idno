<?php

namespace Tests\Core {

    /**
     * Pages that catch an exception and put its message in a session message (which the
     * visitor is shown) go through \Idno\Core\ExceptionMessage::forVisitor(), so that a
     * technical message (see ExceptionMessageTest) is shown only in debug mode. The
     * StandardSiteSync pages cannot be driven over HTTP on the rig (the plugin is not
     * enabled there), so these call sites are pinned by their source.
     */
    class ExceptionMessageCallSitesTest extends \Tests\IdnoTestCase
    {

        public static function pages()
        {
            return [
                ['Idno/Pages/Admin/EmailTest.php'],
                ['IdnoPlugins/StandardSiteSync/Pages/UserSettings.php'],
                ['IdnoPlugins/StandardSiteSync/Pages/Backfill.php'],
                ['IdnoPlugins/StandardSiteSync/Pages/OAuthCallback.php'],
            ];
        }

        /**
         * @dataProvider pages
         */
        public function testASessionErrorMessageNeverHoldsARawExceptionMessage($file)
        {
            $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
            $this->assertNotFalse($source, $file);

            $calls = preg_match_all('/addErrorMessage\((?:[^;]|;(?!\s*\n))*?\);/s', $source, $matches);
            $this->assertGreaterThan(0, $calls);
            $shown = 0;
            foreach ($matches[0] as $call) {
                $this->assertStringNotContainsString('->getMessage()', $call, $file . ': ' . $call);
                if (strpos($call, 'ExceptionMessage::forVisitor($e)') !== false) {
                    $shown++;
                }
            }
            $this->assertGreaterThan(0, $shown, $file . ' shows an exception through ExceptionMessage::forVisitor()');
        }

        public function testATechnicalMessageOfTheAtProtocolClientIsNotShownOutsideDebugMode()
        {
            $debug = \Idno\Core\Idno::site()->config()->debug;
            \Idno\Core\Idno::site()->config()->debug = false;
            try {
                $e = new \Idno\Exceptions\TechnicalException('Token exchange failed: server-detail');
                $this->assertStringNotContainsString('server-detail', \Idno\Core\ExceptionMessage::forVisitor($e));
                \Idno\Core\Idno::site()->config()->debug = true;
                $this->assertStringContainsString('server-detail', \Idno\Core\ExceptionMessage::forVisitor($e));
            } finally {
                \Idno\Core\Idno::site()->config()->debug = $debug;
            }
        }

    }

}
