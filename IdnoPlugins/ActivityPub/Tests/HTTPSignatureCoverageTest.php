<?php

namespace IdnoPlugins\ActivityPub\Tests {

    use IdnoPlugins\ActivityPub\HTTPSignature;

    /**
     * A signature proves only what it covers. For a POST to an inbox it has to cover the
     * method and path, the host, the date and the digest of the body, and the date and the
     * digest have to be right. Otherwise a signature made for one request can be reused for
     * another: a different body, a different server, or the same delivery again much later.
     */
    class HTTPSignatureCoverageTest extends \Tests\IdnoTestCase
    {

        const PATH = '/inbox';
        const HOST = 'idno.example';

        private static $privateKey;
        private static $publicKey;

        public static function setUpBeforeClass(): void
        {
            parent::setUpBeforeClass();
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, self::$privateKey);
            self::$publicKey = openssl_pkey_get_details($key)['key'];
        }

        private function body()
        {
            return json_encode(['type' => 'Follow', 'actor' => 'https://remote.example/users/alice']);
        }

        /**
         * The headers of a delivery as a remote server would send them.
         */
        private function requestHeaders($body, array $changes = [])
        {
            return array_merge([
                'host'           => self::HOST,
                'date'           => gmdate('D, d M Y H:i:s') . ' GMT',
                'digest'         => 'SHA-256=' . base64_encode(hash('sha256', $body, true)),
                'content-type'   => 'application/activity+json',
                'content-length' => (string) strlen($body),
            ], $changes);
        }

        /**
         * Sign the named headers the way draft-cavage describes, and add the Signature header.
         */
        private function signed(array $headers, array $names, $method = 'post', $path = self::PATH)
        {
            $lines = [];
            foreach ($names as $name) {
                $lines[] = $name === '(request-target)'
                    ? '(request-target): ' . $method . ' ' . $path
                    : $name . ': ' . ($headers[$name] ?? '');
            }
            openssl_sign(implode("\n", $lines), $signature, self::$privateKey, OPENSSL_ALGO_SHA256);
            $headers['signature'] = sprintf(
                'keyId="https://remote.example/users/alice#main-key",algorithm="rsa-sha256",headers="%s",signature="%s"',
                implode(' ', $names),
                base64_encode($signature)
            );

            return $headers;
        }

        private function verifyPost(array $headers, $body)
        {
            return HTTPSignature::verify(self::$publicKey, $headers, $body, 'POST', self::PATH);
        }

        /**
         * The header lists that the common servers sign when they deliver to an inbox.
         */
        public function deliveryHeaderLists()
        {
            return [
                'Mastodon, GoToSocial' => [['(request-target)', 'host', 'date', 'digest']],
                'Mastodon with content-type' => [['(request-target)', 'host', 'date', 'digest', 'content-type']],
                'Pleroma, Akkoma' => [['(request-target)', 'content-length', 'date', 'digest', 'host']],
                'Misskey' => [['(request-target)', 'date', 'host', 'digest']],
            ];
        }

        /**
         * @dataProvider deliveryHeaderLists
         */
        public function testDeliveryAsTheCommonServersSignItIsAccepted(array $names)
        {
            $body = $this->body();

            $this->assertTrue($this->verifyPost($this->signed($this->requestHeaders($body), $names), $body));
        }

        public function testDeliverySignedByIdnoIsAccepted()
        {
            $body = $this->body();
            $headers = ['host' => self::HOST];
            foreach (HTTPSignature::sign(self::$privateKey, 'https://remote.example/users/alice#main-key', 'https://' . self::HOST . self::PATH, $body) as $header) {
                list($name, $value) = explode(': ', $header, 2);
                $headers[strtolower($name)] = $value;
            }

            $this->assertTrue($this->verifyPost($headers, $body));
        }

        public function testHeaderNamesInTheSignatureMayBeInAnyCase()
        {
            $body = $this->body();
            $headers = $this->signed($this->requestHeaders($body), ['(request-target)', 'host', 'date', 'digest']);
            $headers['signature'] = str_replace('host date digest', 'Host Date Digest', $headers['signature']);

            $this->assertTrue($this->verifyPost($headers, $body));
        }

        /**
         * Each of the four must be covered, however valid the signature over the others is.
         */
        public function incompleteHeaderLists()
        {
            return [
                'no digest' => [['(request-target)', 'host', 'date']],
                'no date' => [['(request-target)', 'host', 'digest']],
                'no host' => [['(request-target)', 'date', 'digest']],
                'no request target' => [['host', 'date', 'digest']],
                'date only' => [['date']],
            ];
        }

        /**
         * @dataProvider incompleteHeaderLists
         */
        public function testPostIsRefusedWhenTheSignatureDoesNotCoverARequiredHeader(array $names)
        {
            $body = $this->body();

            $this->assertFalse($this->verifyPost($this->signed($this->requestHeaders($body), $names), $body));
        }

        /**
         * The case that mattered most: without the digest in the signature, the body is not signed at all.
         */
        public function testBodyCannotBeReplacedUnderASignatureThatLeavesOutTheDigest()
        {
            $body = $this->body();
            $headers = $this->signed($this->requestHeaders($body), ['(request-target)', 'host', 'date']);

            $forged = json_encode(['type' => 'Delete', 'actor' => 'https://remote.example/users/alice']);
            $headers['digest'] = 'SHA-256=' . base64_encode(hash('sha256', $forged, true));

            $this->assertFalse($this->verifyPost($headers, $forged));

            unset($headers['digest']);

            $this->assertFalse($this->verifyPost($headers, $forged));
        }

        public function testPostIsRefusedWhenTheDigestHeaderIsMissing()
        {
            $body = $this->body();
            $headers = $this->requestHeaders($body);
            unset($headers['digest']);

            $this->assertFalse($this->verifyPost($this->signed($headers, ['(request-target)', 'host', 'date', 'digest']), $body));
        }

        public function testPostIsRefusedWhenTheDigestIsNotTheBodys()
        {
            $body = $this->body();
            $headers = $this->signed($this->requestHeaders($body), ['(request-target)', 'host', 'date', 'digest']);

            $this->assertFalse($this->verifyPost($headers, $body . ' '));
        }

        public function testPostIsRefusedWhenTheDigestUsesAnUnknownAlgorithm()
        {
            $body = $this->body();
            $headers = $this->requestHeaders($body, ['digest' => 'MD5=' . base64_encode(md5($body, true))]);

            $this->assertFalse($this->verifyPost($this->signed($headers, ['(request-target)', 'host', 'date', 'digest']), $body));
        }

        public function acceptableDigests()
        {
            $body = $this->body();
            $sha256 = base64_encode(hash('sha256', $body, true));
            $sha512 = base64_encode(hash('sha512', $body, true));

            return [
                'lower case name' => ['sha-256=' . $sha256],
                'SHA-512' => ['SHA-512=' . $sha512],
                'two digests' => ['SHA-512=' . $sha512 . ', SHA-256=' . $sha256],
                'with an unknown one' => ['unixsum=12345,SHA-256=' . $sha256],
            ];
        }

        /**
         * @dataProvider acceptableDigests
         */
        public function testDigestIsReadAsRfc3230WritesIt($digest)
        {
            $body = $this->body();
            $headers = $this->requestHeaders($body, ['digest' => $digest]);

            $this->assertTrue($this->verifyPost($this->signed($headers, ['(request-target)', 'host', 'date', 'digest']), $body));
        }

        public function testPostIsRefusedWhenOneOfTwoDigestsIsWrong()
        {
            $body = $this->body();
            $digest = 'SHA-512=' . base64_encode(hash('sha512', 'something else', true))
                . ',SHA-256=' . base64_encode(hash('sha256', $body, true));
            $headers = $this->requestHeaders($body, ['digest' => $digest]);

            $this->assertFalse($this->verifyPost($this->signed($headers, ['(request-target)', 'host', 'date', 'digest']), $body));
        }

        public function dates()
        {
            return [
                'now' => [0, true],
                'eleven hours ago' => [-11 * 3600, true],
                'thirteen hours ago' => [-13 * 3600, false],
                'half an hour ahead' => [1800, true],
                'two hours ahead' => [2 * 3600, false],
            ];
        }

        /**
         * @dataProvider dates
         */
        public function testDateMustBeRecent($offset, $accepted)
        {
            $body = $this->body();
            $headers = $this->requestHeaders($body, ['date' => gmdate('D, d M Y H:i:s', time() + $offset) . ' GMT']);

            $this->assertSame($accepted, $this->verifyPost($this->signed($headers, ['(request-target)', 'host', 'date', 'digest']), $body));
        }

        public function testPostIsRefusedWhenTheDateHeaderIsMissingOrNotADate()
        {
            $body = $this->body();
            $names = ['(request-target)', 'host', 'date', 'digest'];

            $missing = $this->requestHeaders($body);
            unset($missing['date']);
            $this->assertFalse($this->verifyPost($this->signed($missing, $names), $body));

            $nonsense = $this->requestHeaders($body, ['date' => 'the day after tomorrow, roughly']);
            $this->assertFalse($this->verifyPost($this->signed($nonsense, $names), $body));
        }

        public function testPostIsRefusedWhenSignedForAnotherPathOrHost()
        {
            $body = $this->body();
            $names = ['(request-target)', 'host', 'date', 'digest'];

            $otherPath = $this->signed($this->requestHeaders($body), $names, 'post', '/actor/someone/inbox');
            $this->assertFalse($this->verifyPost($otherPath, $body));

            $otherHost = $this->signed($this->requestHeaders($body, ['host' => 'other.example']), $names);
            $otherHost['host'] = self::HOST;
            $this->assertFalse($this->verifyPost($otherHost, $body));
        }

        public function testGetNeedsNoDigestButStillNeedsTargetHostAndDate()
        {
            $headers = ['host' => self::HOST, 'date' => gmdate('D, d M Y H:i:s') . ' GMT'];
            $path = '/actor/someone';

            $complete = $this->signed($headers, ['(request-target)', 'host', 'date'], 'get', $path);
            $this->assertTrue(HTTPSignature::verify(self::$publicKey, $complete, '', 'GET', $path));

            $noDate = $this->signed($headers, ['(request-target)', 'host'], 'get', $path);
            $this->assertFalse(HTTPSignature::verify(self::$publicKey, $noDate, '', 'GET', $path));
        }
    }
}
