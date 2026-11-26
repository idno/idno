<?php

namespace Tests\Core {

    /**
     * Page::forward() does not redirect a POST, PUT or DELETE made by a script
     * (XMLHttpRequest). It must still end the request, as it does for a form's: a handler that
     * refuses with a redirect and then goes on would otherwise do what it had just refused.
     *
     * Tests/Core/fixtures/forward_script_request.php serves such a handler in its own PHP
     * process, because forward() ends the process.
     */
    class ForwardScriptRequestTest extends \Tests\IdnoTestCase
    {

        private function serve($kind, $exit = 'exit')
        {
            $helper = __DIR__ . '/fixtures/forward_script_request.php';
            // 22527 is E_ALL without deprecations, which pedantic mode would turn into errors
            $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=0 '
                . escapeshellarg($helper) . ' ' . escapeshellarg($kind) . ' ' . escapeshellarg($exit) . ' 2>/dev/null';

            return (string) shell_exec($command);
        }

        public function testAScriptsRequestEndsWhereItWouldHaveBeenRedirected()
        {
            $output = $this->serve('script');

            $this->assertStringContainsString('BEFORE', $output, 'The handler should have been reached: ' . $output);
            $this->assertStringNotContainsString('AFTER', $output, "A handler must not go on after forward() for a script's request.");
            $this->assertStringNotContainsString('RETURNED', $output);
            $this->assertStringContainsString('STATUS 403', $output, 'The status the handler set is the answer.');
        }

        public function testAFormsRequestEndsThereAsBefore()
        {
            $output = $this->serve('form');

            $this->assertStringContainsString('BEFORE', $output);
            $this->assertStringNotContainsString('AFTER', $output);
        }

        public function testForwardThatWasAskedNotToEndTheRequestStillReturns()
        {
            foreach (['script', 'form'] as $kind) {
                $output = $this->serve($kind, 'noexit');

                $this->assertStringContainsString('AFTER', $output, "forward() with \$exit false returns to its caller ($kind).");
            }
        }
    }

}
