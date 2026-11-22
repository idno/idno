<?php

namespace IdnoPlugins\ActivityPub\Tests {

    use IdnoPlugins\ActivityPub\ActivityHandler;
    use IdnoPlugins\ActivityPub\HTTPSignature;

    /**
     * What the inbox pages do with a POST: an activity is taken on the strength of its HTTP
     * signature, checked against the key its actor publishes, and of nothing else. No form
     * token and no session is involved.
     *
     * The web server cannot be pointed at the local test server, so this calls the handler
     * that both inbox pages hand the request to. InboxRequestTest checks over HTTP that a
     * POST without a form token reaches it.
     */
    class SignedInboxActivityTest extends \Tests\OutboundHttpTestCase
    {

        private static $privateKey;

        private static $actor;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();

            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, static::$privateKey);
            $public = openssl_pkey_get_details($key)['key'];

            // The local test server answers for this actor, with this key
            static::$actor = 'http://203.0.113.10/actor?key=' . rtrim(strtr(base64_encode($public), '+/', '-_'), '=');
        }

        public function testSignedActivityIsAccepted()
        {
            $this->useLocalServerAsProxy();
            [$body, $headers] = $this->signedActivity('/actor/someone/inbox');

            $result = ActivityHandler::handle($body, $headers, 'POST', '/actor/someone/inbox', 'someone');

            $this->assertEquals(200, $result['status'], 'An activity signed by its actor should be accepted.');
            $this->assertSame(['GET ' . static::$actor], static::$server->requests(), "The actor's key should have been fetched.");
        }

        public function testSignedActivityIsAcceptedByTheSharedInbox()
        {
            $this->useLocalServerAsProxy();
            [$body, $headers] = $this->signedActivity('/inbox');

            $result = ActivityHandler::handle($body, $headers, 'POST', '/inbox');

            $this->assertEquals(200, $result['status'], 'An activity signed by its actor should be accepted.');
        }

        public function testActivityWithoutASignatureIsRefused()
        {
            $this->useLocalServerAsProxy();
            [$body, $headers] = $this->signedActivity('/inbox');
            unset($headers['signature']);

            $result = ActivityHandler::handle($body, $headers, 'POST', '/inbox');

            $this->assertEquals(401, $result['status']);
        }

        public function testActivityChangedAfterSigningIsRefused()
        {
            $this->useLocalServerAsProxy();
            [$body, $headers] = $this->signedActivity('/inbox');
            $body = str_replace('"Accept"', '"Reject"', $body);

            $result = ActivityHandler::handle($body, $headers, 'POST', '/inbox');

            $this->assertEquals(401, $result['status']);
        }

        public function testActivitySignedForAnotherAddressIsRefused()
        {
            $this->useLocalServerAsProxy();
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
                'actor' => static::$actor,
                'object' => 'http://203.0.113.10/activity/follow',
                ], JSON_UNESCAPED_SLASHES
            );

            $url = \Idno\Core\Idno::site()->config()->getURL() . ltrim($path, '/');
            $parsed = parse_url($url);
            $headers = ['host' => $parsed['host'] . (empty($parsed['port']) || in_array($parsed['port'], [80, 443]) ? '' : ':' . $parsed['port'])];
            foreach (HTTPSignature::sign(static::$privateKey, static::$actor . '#main-key', $url, $body) as $line) {
                [$name, $value] = explode(': ', $line, 2);
                $headers[strtolower($name)] = $value;
            }

            return [$body, $headers];
        }
    }

}
