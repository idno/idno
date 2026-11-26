<?php

namespace Tests\Pages\WebSub {

    use Idno\Core\Idno;
    use Idno\Core\Webservice;

    /**
     * A WebSub hub verifies a subscription by asking the callback to repeat
     * the hub.challenge it sends. The challenge comes from the request, so the answer must be
     * plain text that a browser will not treat as a web page.
     */
    class CallbackTest extends \Tests\IdnoTestCase
    {

        /// A challenge that would run a script if it were served as HTML
        const CHALLENGE = '<script>alert(document.domain)</script>';

        /// The topic the site subscribed to, which the hub repeats
        const TOPIC = 'http://example.com/feed';

        /**
         * Record whether the test user is waiting for a hub to verify a subscription to the
         * test admin's feed.
         */
        private function setPendingSubscription($isPending)
        {
            $pending = new \stdClass();
            $pending->subscribe = $isPending ? [$this->admin()->getUUID()] : [];

            $user = $this->user();
            $user->pubsub_pending = serialize($pending);
            $this->assertNotEmpty($user->save(true), 'The subscriber should have been saved.');

            // The topic the site subscribed to: a hub that names another is not verified
            $admin = $this->admin();
            $admin->pubsub_self = $isPending ? self::TOPIC : '';
            $this->assertNotEmpty($admin->save(true), 'The followed user should have been saved.');
        }

        /**
         * Ask the callback to verify the subscription, as a hub (or a link in a browser) would.
         */
        private function requestVerification()
        {
            $url = Idno::site()->config()->getDisplayURL()
                . 'pubsub/callback/' . $this->user()->getID() . '/' . $this->admin()->getID()
                . '?' . http_build_query(['hub.mode' => 'subscribe', 'hub.topic' => self::TOPIC, 'hub.challenge' => self::CHALLENGE]);

            return Webservice::get($url);
        }

        /**
         * The values of one header in a response.
         */
        private function headerValues($result, $name)
        {
            preg_match_all('/^' . preg_quote($name, '/') . ':\s*(.*?)\s*$/mi', (string) $result['header'], $matches);

            return $matches[1];
        }

        protected function tearDown(): void
        {
            $this->setPendingSubscription(false);
        }

        public function testChallengeIsRepeatedAsPlainText()
        {
            $this->setPendingSubscription(true);

            $result = $this->requestVerification();

            $this->assertEquals(200, $result['response'], 'A pending subscription should be verified. If this is failing, you may need to set IDNO_DOMAIN.');
            $this->assertSame(self::CHALLENGE, $result['content'], 'The hub expects the challenge back, unchanged and with nothing around it.');

            $contentTypes = $this->headerValues($result, 'Content-Type');
            $this->assertCount(1, $contentTypes, 'The response should have one content type.');
            $this->assertMatchesRegularExpression('#^text/plain\s*(;|$)#i', $contentTypes[0], 'The challenge should be served as plain text, not as HTML.');

            $this->assertSame(['nosniff'], array_map('strtolower', $this->headerValues($result, 'X-Content-Type-Options')), 'Browsers should be told not to guess another content type.');
        }

        public function testChallengeIsNotRepeatedWhenNothingIsPending()
        {
            $this->setPendingSubscription(false);

            $result = $this->requestVerification();

            $this->assertEquals(403, $result['response'], 'A subscription that was not asked for should be refused.');
            $this->assertStringNotContainsString('<script>alert', (string) $result['content'], 'The page that refuses the request should not repeat the challenge.');
        }

    }

}
