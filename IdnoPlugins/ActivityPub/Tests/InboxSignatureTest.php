<?php

namespace IdnoPlugins\ActivityPub\Tests {

    use IdnoPlugins\ActivityPub\ActivityHandler;

    /**
     * A delivery as the inbox receives it: the handler reads the key id from the signature,
     * fetches the sender's actor document for its public key, and checks the signature with it.
     */
    class InboxSignatureTest extends \Tests\IdnoTestCase
    {
        use ServesTestActor;

        const PATH = '/inbox';
        const HOST = 'idno.example';

        private static $publicKey;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            self::$publicKey = self::makeActorKey();
        }

        protected function setUp(): void
        {
            parent::setUp();
            $this->serveActor(self::$publicKey);
        }

        protected function tearDown(): void
        {
            $this->stopServingActor();
            parent::tearDown();
        }

        private function activity($type = 'Accept')
        {
            return json_encode(['type' => $type, 'id' => 'http://203.0.113.10/activity/1', 'actor' => self::$actor, 'object' => '']);
        }

        /**
         * The request headers of a delivery, signed over the named headers.
         */
        private function delivery($body, array $names, $age = 0)
        {
            $headers = [
                'host'         => self::HOST,
                'date'         => gmdate('D, d M Y H:i:s', time() - $age) . ' GMT',
                'digest'       => 'SHA-256=' . base64_encode(hash('sha256', $body, true)),
                'content-type' => 'application/activity+json',
            ];
            $lines = [];
            foreach ($names as $name) {
                $lines[] = $name === '(request-target)' ? '(request-target): post ' . self::PATH : $name . ': ' . $headers[$name];
            }
            openssl_sign(implode("\n", $lines), $signature, self::$privateKey, OPENSSL_ALGO_SHA256);
            $headers['signature'] = sprintf(
                'keyId="%s#main-key",algorithm="rsa-sha256",headers="%s",signature="%s"',
                self::$actor,
                implode(' ', $names),
                base64_encode($signature)
            );

            return $headers;
        }

        public function testDeliverySignedOverTheFourHeadersIsAccepted()
        {
            $body = $this->activity();

            $result = ActivityHandler::handle($body, $this->delivery($body, ['(request-target)', 'host', 'date', 'digest']), 'POST', self::PATH);

            $this->assertEquals(200, $result['status']);
        }

        public function testDeliveryWhoseSignatureLeavesOutTheDigestIsRefused()
        {
            $body = $this->activity();

            $result = ActivityHandler::handle($body, $this->delivery($body, ['(request-target)', 'host', 'date']), 'POST', self::PATH);

            $this->assertEquals(401, $result['status']);
        }

        public function testDeliveryWithAnotherBodyIsRefused()
        {
            $body = $this->activity();
            $headers = $this->delivery($body, ['(request-target)', 'host', 'date', 'digest']);

            $result = ActivityHandler::handle($this->activity('Reject'), $headers, 'POST', self::PATH);

            $this->assertEquals(401, $result['status']);
        }

        public function testDeliveryWithAnOldDateIsRefused()
        {
            $body = $this->activity();
            $headers = $this->delivery($body, ['(request-target)', 'host', 'date', 'digest'], 86400);

            $result = ActivityHandler::handle($body, $headers, 'POST', self::PATH);

            $this->assertEquals(401, $result['status']);
        }
    }
}
