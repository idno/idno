<?php

namespace Tests {

    use Idno\Core\Idno;

    /**
     * Base class for tests of code that fetches URLs. It runs a LocalHttpServer for the test
     * class, and puts the proxy_string and disable_ssl_verify settings back after each test.
     *
     * Nothing leaves the machine: a test that needs a "public" server calls
     * useLocalServerAsProxy() and uses addresses in the documentation range 203.0.113.0/24,
     * which the local server then answers.
     */
    abstract class OutboundHttpTestCase extends IdnoTestCase
    {

        /**
         * @var LocalHttpServer
         */
        protected static $server;

        private $settings = [];

        public static function setUpBeforeClass(): void
        {
            static::$server = LocalHttpServer::start();
        }

        public static function tearDownAfterClass(): void
        {
            static::$server->stop();
        }

        protected function setUp(): void
        {
            static::$server->reset();
            foreach (['proxy_string', 'disable_ssl_verify'] as $name) {
                $this->settings[$name] = Idno::site()->config()->$name ?? null;
                Idno::site()->config()->$name = null;
            }
        }

        protected function tearDown(): void
        {
            foreach ($this->settings as $name => $value) {
                Idno::site()->config()->$name = $value;
            }
        }

        /**
         * Send every request Idno makes to the local server, whatever host it names
         */
        protected function useLocalServerAsProxy(): void
        {
            Idno::site()->config()->proxy_string = static::$server->proxy();
        }
    }

}
