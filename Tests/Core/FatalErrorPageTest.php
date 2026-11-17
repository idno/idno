<?php

namespace Tests\Core {

    /**
     * The fatal-error page (the shutdown function in Idno/start.php) is what a visitor gets
     * when a request dies of an uncaught error. It must not tell visitors what went wrong:
     * the message, the file, the line and the stack trace go to the error log. In debug mode
     * the page may show them, but only as text.
     */
    class FatalErrorPageTest extends \Tests\IdnoTestCase
    {

        /// The error message and page address used by the helper script
        const MESSAGE = 'Could not open <script>alert(1)</script> for \'dbuser\'@"dbhost"';
        const URI = '/probe?q=<script>alert(2)</script>';

        /**
         * Run Tests/Core/fixtures/fatal_error.php in its own PHP process.
         *
         * @param  string $mode "production", "debug" or "boot"
         * @return array The page that was printed, and what was written to the error log
         */
        private function fatalErrorPage($mode)
        {
            $helper = __DIR__ . '/fixtures/fatal_error.php';
            $log = tempnam(sys_get_temp_dir(), 'idno-test-fatal-');
            // 22527 is E_ALL without deprecations, which pedantic mode would turn into errors
            $command = escapeshellarg(PHP_BINARY)
                . ' -d error_reporting=22527 -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log) . ' '
                . escapeshellarg($helper) . ' ' . escapeshellarg($mode) . ' 2>&1';
            $page = (string) shell_exec($command);
            $logged = (string) file_get_contents($log);
            unlink($log);

            return [$page, $logged];
        }

        /**
         * The page a visitor may see when the details are withheld: nothing about the error.
         */
        private function assertPageHasNoDetails($page)
        {
            $this->assertStringContainsString('Oh no! Idno experienced a problem!', $page);
            $this->assertMatchesRegularExpression('#<h1 class="error-code">\s*500\s*</h1>#', $page, 'The page should report a 500 status.');

            $this->assertStringNotContainsString('<script', $page);
            $this->assertStringNotContainsString('alert(1)', $page, 'The error message should not be shown.');
            $this->assertStringNotContainsString('dbuser', $page, 'The error message should not be shown.');
            $this->assertStringNotContainsString('alert(2)', $page, 'The page address should not be shown.');
            $this->assertStringNotContainsString('probe', $page, 'The page address should not be shown.');
            $this->assertStringNotContainsString(dirname(__DIR__, 2), $page, 'File paths should not be shown.');
            $this->assertStringNotContainsString('.php', $page, 'File names should not be shown.');
            $this->assertStringNotContainsString('Stack trace', $page, 'The stack trace should not be shown.');
            $this->assertStringNotContainsString('#0 ', $page, 'The stack trace should not be shown.');
            $this->assertStringNotContainsString('Exception', $page, 'Class names should not be shown.');
            $this->assertStringNotContainsString('Uncaught', $page);
            $this->assertStringNotContainsString('Fatal Error', $page);
        }

        /**
         * The details of the error, written to the error log by the page, once.
         */
        private function assertLogHasDetails($logged)
        {
            $helper = __DIR__ . '/fixtures/fatal_error.php';

            $this->assertSame(1, substr_count($logged, 'Fatal Error: '), 'The page should log the error once.');
            $this->assertStringContainsString(self::MESSAGE, $logged);
            $this->assertStringContainsString('RuntimeException', $logged);
            $this->assertStringContainsString('Stack trace', $logged);
            $this->assertStringContainsString($helper, $logged);
            $this->assertStringContainsString('on page probe.test' . self::URI, $logged);
        }

        public function testProductionPageShowsNoDetails()
        {
            list($page, $logged) = $this->fatalErrorPage('production');

            $this->assertPageHasNoDetails($page);
        }

        public function testProductionErrorIsLogged()
        {
            list($page, $logged) = $this->fatalErrorPage('production');

            $this->assertLogHasDetails($logged);
        }

        public function testDebugPageShowsDetailsAsText()
        {
            list($page, $logged) = $this->fatalErrorPage('debug');

            $this->assertStringContainsString('Oh no! Idno experienced a problem!', $page);
            $this->assertMatchesRegularExpression('#<h1 class="error-code">\s*500\s*</h1>#', $page, 'The page should report a 500 status.');

            // The message, twice: on its own, and in the technical details
            $escaped = 'Could not open &lt;script&gt;alert(1)&lt;/script&gt; for &#039;dbuser&#039;@&quot;dbhost&quot;';
            $this->assertSame(2, substr_count($page, $escaped), 'The error message should be shown as text.');
            $this->assertStringContainsString('on page probe.test/probe?q=&lt;script&gt;alert(2)&lt;/script&gt;', $page, 'The page address should be shown as text.');
            $this->assertStringContainsString('Stack trace', $page);

            $this->assertStringNotContainsString('<script', $page);
            $this->assertStringNotContainsString('\'dbuser\'', $page);
            $this->assertStringNotContainsString('"dbhost"', $page);
        }

        public function testDebugErrorIsLogged()
        {
            list($page, $logged) = $this->fatalErrorPage('debug');

            $this->assertLogHasDetails($logged);
        }

        /**
         * Before the configuration is loaded, nobody can say whether the site is in debug
         * mode, so the details are withheld.
         */
        public function testErrorBeforeConfigurationIsLoadedShowsNoDetails()
        {
            list($page, $logged) = $this->fatalErrorPage('boot');

            $this->assertPageHasNoDetails($page);
        }

        public function testErrorBeforeConfigurationIsLoadedIsLogged()
        {
            list($page, $logged) = $this->fatalErrorPage('boot');

            $this->assertLogHasDetails($logged);
        }
    }

}
