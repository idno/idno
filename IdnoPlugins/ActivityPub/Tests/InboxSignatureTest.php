<?php

namespace IdnoPlugins\ActivityPub\Tests {

    use IdnoPlugins\ActivityPub\ActivityHandler;

    /**
     * A delivery as the inbox receives it: the handler reads the key id from the signature,
     * fetches the sender's actor document for its public key, and checks the signature with it.
     */
    class InboxSignatureTest extends \Tests\OutboundHttpTestCase
    {

        const PATH = '/inbox';
        const HOST = 'idno.example';

        private static $privateKey;
        private static $actor;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, self::$privateKey);
            // The local server answers this address with an actor document that carries the key
            self::$actor = 'http://203.0.113.10/actor?key=' . rtrim(strtr(base64_encode(openssl_pkey_get_details($key)['key']), '+/', '-_'), '=');
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
            $this->useLocalServerAsProxy();
            $body = $this->activity();

            $result = ActivityHandler::handle($body, $this->delivery($body, ['(request-target)', 'host', 'date', 'digest']), 'POST', self::PATH);

            $this->assertEquals(200, $result['status']);
        }

        public function testDeliveryWhoseSignatureLeavesOutTheDigestIsRefused()
        {
            $this->useLocalServerAsProxy();
            $body = $this->activity();

            $result = ActivityHandler::handle($body, $this->delivery($body, ['(request-target)', 'host', 'date']), 'POST', self::PATH);

            $this->assertEquals(401, $result['status']);
        }

        public function testDeliveryWithAnotherBodyIsRefused()
        {
            $this->useLocalServerAsProxy();
            $body = $this->activity();
            $headers = $this->delivery($body, ['(request-target)', 'host', 'date', 'digest']);

            $result = ActivityHandler::handle($this->activity('Reject'), $headers, 'POST', self::PATH);

            $this->assertEquals(401, $result['status']);
        }

        public function testDeliveryWithAnOldDateIsRefused()
        {
            $this->useLocalServerAsProxy();
            $body = $this->activity();
            $headers = $this->delivery($body, ['(request-target)', 'host', 'date', 'digest'], 86400);

            $result = ActivityHandler::handle($body, $headers, 'POST', self::PATH);

            $this->assertEquals(401, $result['status']);
        }
    }
}
