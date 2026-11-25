<?php

namespace IdnoPlugins\Atmosphere\Tests {

    use Idno\Core\ExceptionMessage;
    use IdnoPlugins\Atmosphere\ATProtoClient;

    /**
     * The AT Protocol client's exceptions carry technical messages: identifiers, and what
     * other servers answered. They are thrown as \Idno\Exceptions\TechnicalException, so that
     * one that nothing catches is not shown to visitors (see \Idno\Core\ExceptionMessage).
     */
    class ATProtoClientExceptionTest extends \Tests\IdnoTestCase
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

        public function testUnsupportedDidMethodIsATechnicalException()
        {
            try {
                // Fails before any request is made
                ATProtoClient::resolvePDS('did:example:atproto-client-exception-test');
            } catch (\Throwable $e) {
                $this->assertStringContainsString('did:example:atproto-client-exception-test', $e->getMessage());
                $this->assertInstanceOf(\Idno\Exceptions\Technical::class, $e);
                $this->assertStringNotContainsString('did:example', ExceptionMessage::forVisitor($e));

                return;
            }

            $this->fail('An exception should have been thrown.');
        }

        public function testTechnicalExceptionIsStillCaughtAsARuntimeException()
        {
            // The plugin's pages catch \Exception and \RuntimeException, and show the message
            // themselves to the user who is connecting an account
            $this->expectException(\RuntimeException::class);

            ATProtoClient::resolvePDS('did:example:atproto-client-exception-test');
        }

    }

}
