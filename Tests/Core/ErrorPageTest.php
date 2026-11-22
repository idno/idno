<?php

namespace Tests\Core {

    use Idno\Core\DefaultTemplate;

    /**
     * The error page (templates/default/pages/500.tpl.php) is what Page::exception() draws
     * when an exception is thrown while a page is being served. The exception's message can
     * contain text taken from the request, so the page must print it, and in debug mode the
     * stack trace, as text and never as HTML. A technical message (see
     * \Idno\Core\ExceptionMessage) is shown in debug mode only.
     */
    class ErrorPageTest extends \Tests\IdnoTestCase
    {

        /// The exception message drawn by the tests
        const MESSAGE = 'Could not open <script>alert(1)</script> for \'dbuser\'@"dbhost"';

        /// The same message as it should appear on the page
        const ESCAPED = 'Could not open &lt;script&gt;alert(1)&lt;/script&gt; for &#039;dbuser&#039;@&quot;dbhost&quot;';

        /// The site's debug setting, to restore after each test
        private $debug;

        protected function setUp(): void
        {
            $this->debug = \Idno\Core\Idno::site()->config()->debug;
        }

        protected function tearDown(): void
        {
            \Idno\Core\Idno::site()->config()->debug = $this->debug;
        }

        /**
         * Draw the error page for an exception, as Page::exception() does.
         *
         * @param  boolean         $debug     Whether the site is in debug mode
         * @param  \Throwable|null $exception The exception; by default one with a friendly message
         * @return string The page body
         */
        private function errorPage($debug, $exception = null)
        {
            \Idno\Core\Idno::site()->config()->debug = $debug;

            if ($exception === null) {
                $exception = new \RuntimeException(self::MESSAGE);
            }

            $t = new DefaultTemplate();

            return $t->__(['exception' => $exception])->draw('pages/500');
        }

        /**
         * Whatever the debug setting, nothing from the exception may reach the page as HTML.
         */
        private function assertNothingIsPrintedAsHTML($page)
        {
            $this->assertStringContainsString('Something went wrong.', $page);

            $this->assertStringNotContainsString('<script', $page, 'The script tag in the message should be escaped.');
            $this->assertStringNotContainsString('</script', $page, 'The script tag in the message should be escaped.');
            $this->assertStringNotContainsString('\'dbuser\'', $page, 'The single quotes in the message should be escaped.');
            $this->assertStringNotContainsString('"dbhost"', $page, 'The double quotes in the message should be escaped.');
        }

        public function testMessageIsEscapedWhenNotInDebugMode()
        {
            $page = $this->errorPage(false);

            $this->assertNothingIsPrintedAsHTML($page);
            $this->assertSame(1, substr_count($page, self::ESCAPED), 'The message should be shown once, as text.');
            $this->assertStringContainsString('<p class="p-summary">' . self::ESCAPED . '</p>', $page);
        }

        public function testTraceIsNotShownWhenNotInDebugMode()
        {
            $page = $this->errorPage(false);

            $this->assertStringNotContainsString('id="details"', $page, 'The technical details should not be on the page.');
            $this->assertStringNotContainsString('RuntimeException', $page, 'The exception class should not be shown.');
            $this->assertStringNotContainsString('Stack trace', $page, 'The stack trace should not be shown.');
            $this->assertStringNotContainsString('#0 ', $page, 'The stack trace should not be shown.');
            $this->assertStringNotContainsString(__FILE__, $page, 'File paths should not be shown.');
        }

        public function testMessageAndTraceAreEscapedInDebugMode()
        {
            $page = $this->errorPage(true);

            $this->assertNothingIsPrintedAsHTML($page);
            $this->assertStringContainsString('<p class="p-summary">' . self::ESCAPED . '</p>', $page);
            $this->assertSame(2, substr_count($page, self::ESCAPED), 'The message should be shown twice, as text: in the summary and in the technical details.');
        }

        public function testTraceIsShownInDebugMode()
        {
            $page = $this->errorPage(true);

            $this->assertStringContainsString('id="details"', $page, 'The technical details should be on the page.');
            $this->assertStringContainsString('RuntimeException: ' . self::ESCAPED, $page);
            $this->assertStringContainsString('Stack trace', $page);
            $this->assertStringContainsString('#0 ', $page);
            $this->assertStringContainsString(__FILE__, $page);
        }

        public function testTechnicalMessageIsNotShownWhenNotInDebugMode()
        {
            $page = $this->errorPage(false, new \Idno\Exceptions\TechnicalException(self::MESSAGE));

            $this->assertStringContainsString(
                '<p class="p-summary">' . \Idno\Core\Idno::site()->language()->_('Oh no! Something went wrong.') . '</p>',
                $page,
                'The visitor should be told only that something went wrong.'
            );
            $this->assertStringNotContainsString('Could not open', $page, 'The technical message should not be shown.');
            $this->assertStringNotContainsString('alert(1)', $page, 'The technical message should not be shown.');
            $this->assertStringNotContainsString('dbuser', $page, 'The technical message should not be shown.');
            $this->assertStringNotContainsString('TechnicalException', $page, 'The exception class should not be shown.');
        }

        public function testTechnicalMessageIsShownEscapedInDebugMode()
        {
            $page = $this->errorPage(true, new \Idno\Exceptions\TechnicalException(self::MESSAGE));

            $this->assertNothingIsPrintedAsHTML($page);
            $this->assertStringContainsString('<p class="p-summary">' . self::ESCAPED . '</p>', $page);
            $this->assertStringContainsString('TechnicalException: ' . self::ESCAPED, $page);
        }

    }

}
