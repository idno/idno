<?php

namespace IdnoPlugins\ActivityPub\Tests {

    use Idno\Core\Webservice;
    use Symfony\Component\HttpClient\MockHttpClient;
    use Symfony\Component\HttpClient\Response\MockResponse;

    /**
     * For tests of the inbox, which fetches the sender's actor document for the public key to check a
     * signature with: a remote server that answers for one actor, with one key. The site's HTTP clients are
     * replaced by mocks (Webservice::useClient()), so nothing is sent anywhere, and every request the
     * inbox makes is recorded as "METHOD url".
     */
    trait ServesTestActor
    {

        /// The address of the actor, a documentation address that no real server answers for
        private static $actor = 'http://203.0.113.10/actor';

        /// PEM of the key that signs the actor's deliveries
        private static $privateKey;

        /// @var string[] "METHOD url" of each request the remote client was sent
        private $remoteRequests = [];

        private static function makeActorKey()
        {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, self::$privateKey);

            return openssl_pkey_get_details($key)['key'];
        }

        /**
         * Make the remote client answer for the actor with the given public key.
         */
        private function serveActor($publicKeyPem)
        {
            $this->remoteRequests = [];
            $remote = new MockHttpClient(
                function (string $method, string $url) use ($publicKeyPem): MockResponse {
                    $this->remoteRequests[] = $method . ' ' . $url;
                    $actor = [
                        'id'                => self::$actor,
                        'type'              => 'Person',
                        'preferredUsername' => 'fixture',
                        'inbox'             => 'http://203.0.113.10/inbox',
                        'publicKey'         => [
                            'id'           => self::$actor . '#main-key',
                            'owner'        => self::$actor,
                            'publicKeyPem' => $publicKeyPem,
                        ],
                    ];

                    return new MockResponse(json_encode($actor), ['http_code' => 200, 'response_headers' => ['Content-Type: application/activity+json']]);
                }
            );
            Webservice::useClient(new MockHttpClient(new MockResponse('', ['http_code' => 404])), $remote);
        }

        private function stopServingActor()
        {
            Webservice::useClient(null);
        }
    }

}
