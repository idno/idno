<?php

namespace Tests\Core {

    /**
     * When an exception is thrown while a page is being served in the json or jsonp template
     * type, Page::exception() answers with that type's page shell, which describes the
     * exception. Visitors get its message only: the class, the file and the line describe the
     * server, so they are logged, and added to the response only in debug mode. A technical
     * message (see \Idno\Core\ExceptionMessage) is likewise logged, and put in the response
     * only in debug mode.
     */
    class ExceptionResponseTest extends \Tests\IdnoTestCase
    {

        /// The exception message used by the helper script
        const MESSAGE = 'Could not open <script>alert(1)</script> for \'dbuser\'@"dbhost"';

        /**
         * Run Tests/Core/fixtures/exception_response.php in its own PHP process.
         *
         * @param  string $mode     "production" or "debug"
         * @param  string $template "json" or "jsonp"
         * @param  string $message  "friendly" or "technical"
         * @return array The response body, and what was written to the error log
         */
        private function exceptionResponse($mode, $template, $message = 'friendly')
        {
            $helper = __DIR__ . '/fixtures/exception_response.php';
            $log = tempnam(sys_get_temp_dir(), 'idno-test-exception-');
            // 22527 is E_ALL without deprecations, which pedantic mode would turn into errors
            $command = escapeshellarg(PHP_BINARY)
                . ' -d error_reporting=22527 -d display_errors=0 -d log_errors=1 -d error_log=' . escapeshellarg($log) . ' '
                . escapeshellarg($helper) . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($template) . ' ' . escapeshellarg($message) . ' 2>&1';
            $body = (string) shell_exec($command);
            $logged = (string) file_get_contents($log);
            unlink($log);

            return [$body, $logged];
        }

        /**
         * The response of the json template type, decoded.
         */
        private function decodeJson($body)
        {
            $response = json_decode($body, true);
            $this->assertIsArray($response, 'The response should be JSON: ' . $body);

            return $response;
        }

        /**
         * The response of the jsonp template type, decoded: JSON inside a call to response().
         */
        private function decodeJsonp($body)
        {
            $this->assertMatchesRegularExpression('/^response\((.*)\)$/s', $body, 'The response should be a call to response().');

            return $this->decodeJson(substr($body, strlen('response('), -1));
        }

        /**
         * The response a visitor may see: the exception's message, and nothing about the server.
         */
        private function assertResponseHasMessageOnly($response, $body)
        {
            $this->assertSame(['message' => self::MESSAGE], $response['exception']);

            $this->assertStringNotContainsString('RuntimeException', $body, 'The exception class should not be shown.');
            $this->assertStringNotContainsString('fixtures', $body, 'File paths should not be shown.');
            $this->assertStringNotContainsString('.php', $body, 'File names should not be shown.');
        }

        /**
         * The response a visitor may see when the message is a technical one: nothing of it.
         */
        private function assertResponseHasFixedTextOnly($response, $body)
        {
            $this->assertSame(['message' => 'Oh no! Something went wrong.'], $response['exception']);

            $this->assertStringNotContainsString('Could not open', $body, 'The technical message should not be shown.');
            $this->assertStringNotContainsString('alert(1)', $body, 'The technical message should not be shown.');
            $this->assertStringNotContainsString('dbuser', $body, 'The technical message should not be shown.');
            $this->assertStringNotContainsString('TechnicalException', $body, 'The exception class should not be shown.');
            $this->assertStringNotContainsString('.php', $body, 'File names should not be shown.');
        }

        /**
         * The response in debug mode: the class, the file and the line as well.
         */
        private function assertResponseHasDetails($response)
        {
            $this->assertSame(['class', 'message', 'file', 'line'], array_keys($response['exception']));
            $this->assertSame('RuntimeException', $response['exception']['class']);
            $this->assertSame(self::MESSAGE, $response['exception']['message']);
            $this->assertSame(__DIR__ . '/fixtures/exception_response.php', $response['exception']['file']);
            $this->assertIsInt($response['exception']['line']);
        }

        public function testJsonResponseHasMessageOnlyWhenNotInDebugMode()
        {
            list($body, $logged) = $this->exceptionResponse('production', 'json');

            $this->assertResponseHasMessageOnly($this->decodeJson($body), $body);
        }

        public function testJsonpResponseHasMessageOnlyWhenNotInDebugMode()
        {
            list($body, $logged) = $this->exceptionResponse('production', 'jsonp');

            $this->assertResponseHasMessageOnly($this->decodeJsonp($body), $body);
        }

        public function testJsonResponseHasDetailsInDebugMode()
        {
            list($body, $logged) = $this->exceptionResponse('debug', 'json');

            $this->assertResponseHasDetails($this->decodeJson($body));
        }

        public function testJsonpResponseHasDetailsInDebugMode()
        {
            list($body, $logged) = $this->exceptionResponse('debug', 'jsonp');

            $this->assertResponseHasDetails($this->decodeJsonp($body));
        }

        public function testJsonResponseHasNoTechnicalMessageWhenNotInDebugMode()
        {
            list($body, $logged) = $this->exceptionResponse('production', 'json', 'technical');

            $this->assertResponseHasFixedTextOnly($this->decodeJson($body), $body);
        }

        public function testJsonpResponseHasNoTechnicalMessageWhenNotInDebugMode()
        {
            list($body, $logged) = $this->exceptionResponse('production', 'jsonp', 'technical');

            $this->assertResponseHasFixedTextOnly($this->decodeJsonp($body), $body);
        }

        public function testJsonResponseHasTechnicalMessageInDebugMode()
        {
            list($body, $logged) = $this->exceptionResponse('debug', 'json', 'technical');

            $response = $this->decodeJson($body);
            $this->assertSame('Idno\\Exceptions\\TechnicalException', $response['exception']['class']);
            $this->assertSame(self::MESSAGE, $response['exception']['message']);
        }

        public function testJsonpResponseHasTechnicalMessageInDebugMode()
        {
            list($body, $logged) = $this->exceptionResponse('debug', 'jsonp', 'technical');

            $response = $this->decodeJsonp($body);
            $this->assertSame('Idno\\Exceptions\\TechnicalException', $response['exception']['class']);
            $this->assertSame(self::MESSAGE, $response['exception']['message']);
        }

        /**
         * A technical message that the response withholds is still written to the error log.
         */
        public function testTechnicalMessageIsLoggedWhenNotInDebugMode()
        {
            list($body, $logged) = $this->exceptionResponse('production', 'json', 'technical');

            $this->assertMatchesRegularExpression(
                '#' . preg_quote(self::MESSAGE . ' [' . __DIR__ . '/fixtures/exception_response.php:', '#') . '\d+\]#',
                $logged,
                'The message, the file and the line should be logged.'
            );
        }

        /**
         * What the response withholds is still written to the error log.
         */
        public function testExceptionIsLoggedWhenNotInDebugMode()
        {
            list($body, $logged) = $this->exceptionResponse('production', 'json');

            $this->assertMatchesRegularExpression(
                '#' . preg_quote(self::MESSAGE . ' [' . __DIR__ . '/fixtures/exception_response.php:', '#') . '\d+\]#',
                $logged,
                'The message, the file and the line should be logged.'
            );
        }
    }

}
