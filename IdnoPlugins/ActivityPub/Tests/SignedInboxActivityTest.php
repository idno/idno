<?php

namespace IdnoPlugins\ActivityPub\Tests {

    use IdnoPlugins\ActivityPub\ActivityHandler;
    use IdnoPlugins\ActivityPub\HTTPSignature;

    /**
     * What the inbox pages do with a POST: an activity is taken on the strength of its HTTP
     * signature, checked against the key its actor publishes, and of nothing else. No form
     * token and no session is involved.
     *
     * The web server cannot be pointed at a remote actor, so this calls the handler
     * that both inbox pages hand the request to, with the remote client answering for the actor
     * (ServesTestActor). InboxRequestTest checks over HTTP that a POST without a form token
     * reaches it.
     */
    class SignedInboxActivityTest extends \Tests\IdnoTestCase
    {
        use ServesTestActor;

        private static $publicKey;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            static::$publicKey = self::makeActorKey();
        }

        protected function setUp(): void
        {
            parent::setUp();
            $this->serveActor(static::$publicKey);
        }

        protected function tearDown(): void
        {
            $this->stopServingActor();
            parent::tearDown();
        }

        public function testSignedActivityIsAccepted()
        {
            [$body, $headers] = $this->signedActivity('/actor/someone/inbox');

            $result = ActivityHandler::handle($body, $headers, 'POST', '/actor/someone/inbox', 'someone');

            $this->assertEquals(200, $result['status'], 'An activity signed by its actor should be accepted.');
            $this->assertSame(['GET ' . self::$actor], $this->remoteRequests, "The actor's key should have been fetched.");
        }

        public function testSignedActivityIsAcceptedByTheSharedInbox()
        {
            [$body, $headers] = $this->signedActivity('/inbox');

            $result = ActivityHandler::handle($body, $headers, 'POST', '/inbox');

            $this->assertEquals(200, $result['status'], 'An activity signed by its actor should be accepted.');
        }

        public function testActivityWithoutASignatureIsRefused()
        {
            [$body, $headers] = $this->signedActivity('/inbox');
            unset($headers['signature']);

            $result = ActivityHandler::handle($body, $headers, 'POST', '/inbox');

            $this->assertEquals(401, $result['status']);
        }

        public function testActivityChangedAfterSigningIsRefused()
        {
            [$body, $headers] = $this->signedActivity('/inbox');
            $body = str_replace('"Accept"', '"Reject"', $body);

            $result = ActivityHandler::handle($body, $headers, 'POST', '/inbox');

            $this->assertEquals(401, $result['status']);
        }

        public function testActivitySignedForAnotherAddressIsRefused()
        {
            [$body, $headers] = $this->signedActivity('/inbox');

            $result = ActivityHandler::handle($body, $headers, 'POST', '/status/edit?x=/inbox');

            $this->assertEquals(401, $result['status']);
        }

        /**
         * An activity from the test actor, and the headers of a signed POST of it to $path
         * as the inbox pages read them (names in lower case)
         *
         * @return array body, headers
         */
        private function signedActivity($path)
        {
            $body = json_encode(
                [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => 'http://203.0.113.10/activity/' . md5(rand() . microtime(true)),
                'type' => 'Accept',
                'actor' => self::$actor,
                'object' => 'http://203.0.113.10/activity/follow',
                ], JSON_UNESCAPED_SLASHES
            );

            $url = \Idno\Core\Idno::site()->config()->getURL() . ltrim($path, '/');
            $parsed = parse_url($url);
            $headers = ['host' => $parsed['host'] . (empty($parsed['port']) || in_array($parsed['port'], [80, 443]) ? '' : ':' . $parsed['port'])];
            foreach (HTTPSignature::sign(self::$privateKey, self::$actor . '#main-key', $url, $body) as $line) {
                [$name, $value] = explode(': ', $line, 2);
                $headers[strtolower($name)] = $value;
            }

            return [$body, $headers];
        }
    }

}
