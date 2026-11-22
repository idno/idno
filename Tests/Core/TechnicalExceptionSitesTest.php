<?php

namespace Tests\Core {

    use Idno\Core\ExceptionMessage;

    /**
     * Exceptions that core code throws with a message written for whoever maintains the site
     * are thrown as \Idno\Exceptions\TechnicalException, so that the message is not shown to
     * visitors (see \Idno\Core\ExceptionMessage). These are the ones a test can reach without
     * a second site, a queue or another database.
     */
    class TechnicalExceptionSitesTest extends \Tests\IdnoTestCase
    {

        /// The site's debug setting, to restore after each test
        private $debug;

        protected function setUp(): void
        {
            $this->debug = \Idno\Core\Idno::site()->config()->debug;
            \Idno\Core\Idno::site()->config()->debug = false;
        }

        protected function tearDown(): void
        {
            \Idno\Core\Idno::site()->config()->debug = $this->debug;
        }

        /**
         * Run code that is expected to throw, and check that a visitor would not be shown the
         * message of what it throws.
         *
         * @param callable $code
         * @param string   $message Part of the message the exception is expected to have
         */
        private function assertThrowsTechnicalException(callable $code, $message)
        {
            try {
                $code();
            } catch (\Throwable $e) {
                $this->assertStringContainsString($message, $e->getMessage());
                $this->assertInstanceOf(\Idno\Exceptions\Technical::class, $e);
                $this->assertStringNotContainsString($message, ExceptionMessage::forVisitor($e));

                return;
            }

            $this->fail('An exception should have been thrown.');
        }

        public function testTimerThatWasNotStarted()
        {
            $this->assertThrowsTechnicalException(
                function () {
                    \Idno\Stats\Timer::value('technical-exception-sites-test');
                },
                'technical-exception-sites-test'
            );
        }

        public function testTotalTimerThatWasNotStarted()
        {
            $this->assertThrowsTechnicalException(
                function () {
                    \Idno\Stats\TotalTimer::value('technical-exception-sites-test');
                },
                'technical-exception-sites-test'
            );
        }

        public function testServiceCallWithNoEndpoint()
        {
            $this->assertThrowsTechnicalException(
                function () {
                    \Idno\Core\Service::call('');
                },
                'No endpoint given'
            );
        }

        public function testGenericDataItemWithNoDatatype()
        {
            $this->assertThrowsTechnicalException(
                function () {
                    (new \Idno\Entities\GenericDataItem())->save();
                },
                'datatype'
            );
        }

        public function testMutatingAnEntityIntoAnUnrelatedClass()
        {
            $this->assertThrowsTechnicalException(
                function () {
                    (new \Idno\Entities\RemoteUser())->mutate('stdClass');
                },
                'stdClass'
            );
        }

    }

}
