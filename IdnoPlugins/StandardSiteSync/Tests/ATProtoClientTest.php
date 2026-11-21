<?php

namespace IdnoPlugins\StandardSiteSync\Tests {

    use IdnoPlugins\StandardSiteSync\ATProtoClient;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\HttpClient\MockHttpClient;
    use Symfony\Component\HttpClient\Response\MockResponse;

    /**
     * The AT Protocol client's requests, on a mock HTTP client: the method, URL, headers and body of
     * each request, and what each public method returns or throws for a 200, an error document, a
     * DPoP nonce challenge and a transport failure. No test makes a network request. Header values that
     * are secrets (tokens, proofs) are never compared, only their shape.
     */
    class ATProtoClientTest extends TestCase
    {
        /** @var array<int, array{method: string, url: string, options: array}> */
        private $requests = [];

        protected function tearDown(): void
        {
            ATProtoClient::useClient(null);
            ATProtoClient::useTxtResolver(null);
        }

        /**
         * Use a mock client that answers with the given responses, in order, and records the requests.
         *
         * @param MockResponse[] $responses
         */
        private function respondWith(array $responses): void
        {
            $this->requests = [];
            $queue = $responses;
            ATProtoClient::useClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$queue) {
                $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
                $next = array_shift($queue);
                if ($next === null) {
                    throw new \LogicException('An unexpected request: ' . $method . ' ' . $url);
                }
                return $next;
            }));
        }

        private static function json($data, int $status = 200, array $headers = []): MockResponse
        {
            return new MockResponse(json_encode($data), ['http_code' => $status, 'response_headers' => $headers]);
        }

        private static function failure(): MockResponse
        {
            return new MockResponse('', ['error' => 'Connection refused']);
        }

        /** The first value of a request's header, by lower-case name, or null. */
        private function header(int $request, string $name): ?string
        {
            $line = $this->requests[$request]['options']['normalized_headers'][$name][0] ?? null;
            return $line === null ? null : trim(substr($line, strlen($name) + 1));
        }

        /** The claims of a JWT. */
        private static function claims(string $jwt): array
        {
            $parts = explode('.', $jwt);
            self::assertCount(3, $parts);
            return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        }

        private function body(int $request): string
        {
            $body = $this->requests[$request]['options']['body'] ?? '';
            return is_string($body) ? $body : '';
        }

        private static function session(array $extra = []): array
        {
            return array_merge([
                'did'             => 'did:plc:alice',
                'pds'             => 'https://pds.example',
                'access_token'    => 'ACCESS-TOKEN',
                'refresh_token'   => 'REFRESH-TOKEN',
                'token_type'      => 'DPoP',
                'expires_at'      => time() + 3600,
                'dpop_key'        => ATProtoClient::generateDPoPKeyPair(),
                'dpop_nonce_auth' => null,
                'dpop_nonce_pds'  => null,
                'auth_server'     => [
                    'authorization_endpoint' => 'https://auth.example/authorize',
                    'token_endpoint'         => 'https://auth.example/token',
                ],
            ], $extra);
        }

        private static function entity(): \Idno\Common\Entity
        {
            $entity = new class extends \Idno\Common\Entity {
                public function getID()
                {
                    return 'abc123';
                }
                public function getTitle()
                {
                    return 'A title';
                }
                public function getBody()
                {
                    return '<p>Hello <b>world</b></p>';
                }
                public function getDescription()
                {
                    return 'A description';
                }
                public function getURL()
                {
                    return \Idno\Core\Idno::site()->config()->getDisplayURL() . 'entry/abc123/a-title';
                }
            };
            $entity->created = 1700000000;
            return $entity;
        }

        // -----------------------------------------------------------------
        // resolveIdentity()
        // -----------------------------------------------------------------

        public function testResolveIdentityUsesTheDnsRecordThenTheDidDocument()
        {
            ATProtoClient::useTxtResolver(function (string $name) {
                $this->assertSame('_atproto.alice.example', $name);
                return [['txt' => 'did=did:plc:alice']];
            });
            $this->respondWith([self::json(['service' => [
                ['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example/'],
            ]])]);

            $this->assertSame(['did' => 'did:plc:alice', 'pds' => 'https://pds.example'], ATProtoClient::resolveIdentity('alice.example'));

            $this->assertCount(1, $this->requests);
            $this->assertSame('GET', $this->requests[0]['method']);
            $this->assertSame('https://plc.directory/did:plc:alice', $this->requests[0]['url']);
            $this->assertSame('Known-StandardSiteSync/0.1', $this->header(0, 'user-agent'));
        }

        public function testResolveIdentityFallsBackToTheWellKnownFile()
        {
            ATProtoClient::useTxtResolver(fn () => []);
            $this->respondWith([
                new MockResponse("did:plc:alice\n"),
                self::json(['service' => [['id' => '#atproto_pds', 'serviceEndpoint' => 'https://pds.example']]]),
            ]);

            $this->assertSame(['did' => 'did:plc:alice', 'pds' => 'https://pds.example'], ATProtoClient::resolveIdentity('alice.example'));

            $this->assertSame('GET', $this->requests[0]['method']);
            $this->assertSame('https://alice.example/.well-known/atproto-did', $this->requests[0]['url']);
            $this->assertSame('https://plc.directory/did:plc:alice', $this->requests[1]['url']);
        }

        public function testResolveIdentityFailsWhenTheWellKnownFileIsAnError()
        {
            ATProtoClient::useTxtResolver(fn () => []);
            $this->respondWith([new MockResponse('nope', ['http_code' => 404])]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not resolve handle to DID: alice.example');
            ATProtoClient::resolveIdentity('alice.example');
        }

        public function testResolveIdentityFailsOnATransportFailure()
        {
            ATProtoClient::useTxtResolver(fn () => []);
            $this->respondWith([self::failure()]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Could not resolve handle to DID: alice.example');
            ATProtoClient::resolveIdentity('alice.example');
        }

        // -----------------------------------------------------------------
        // resolvePDS()
        // -----------------------------------------------------------------

        public function testResolvePdsReadsAPlcDidDocument()
        {
            $this->respondWith([self::json(['service' => [
                ['id' => '#other', 'type' => 'Other', 'serviceEndpoint' => 'https://other.example'],
                ['id' => '#x', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example//'],
            ]])]);

            $this->assertSame('https://pds.example', ATProtoClient::resolvePDS('did:plc:alice'));
            $this->assertSame('https://plc.directory/did:plc:alice', $this->requests[0]['url']);
        }

        public function testResolvePdsReadsAWebDidDocument()
        {
            $this->respondWith([self::json(['service' => [['id' => '#atproto_pds', 'serviceEndpoint' => 'https://pds.example']]])]);

            ATProtoClient::resolvePDS('did:web:example.com:users:alice');
            $this->assertSame('https://example.com/users/alice/.well-known/did.json', $this->requests[0]['url']);
        }

        public function testResolvePdsFailsForAnUnsupportedMethod()
        {
            $this->respondWith([]);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unsupported DID method');
            ATProtoClient::resolvePDS('did:key:abc');
        }

        public function testResolvePdsFailsForAnErrorStatusAndForATransportFailure()
        {
            $this->respondWith([new MockResponse('', ['http_code' => 404]), self::failure()]);
            foreach ([1, 2] as $ignored) {
                try {
                    ATProtoClient::resolvePDS('did:plc:alice');
                    $this->fail('An exception was expected.');
                } catch (\RuntimeException $e) {
                    $this->assertSame('Failed to resolve DID document for: did:plc:alice', $e->getMessage());
                }
            }
        }

        public function testResolvePdsFailsWithoutAPdsService()
        {
            $this->respondWith([self::json(['service' => [['id' => '#other', 'type' => 'Other', 'serviceEndpoint' => 'https://x.example']]]), self::json([])]);

            try {
                ATProtoClient::resolvePDS('did:plc:alice');
                $this->fail('An exception was expected.');
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith('No PDS service endpoint found', $e->getMessage());
            }
            try {
                ATProtoClient::resolvePDS('did:plc:alice');
                $this->fail('An exception was expected.');
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith('No service entries in DID document', $e->getMessage());
            }
        }

        public function testAGetFollowsFiveRedirectsAndATimeLimitOfThirtySecondsApplies()
        {
            $this->respondWith([self::json(['service' => [['id' => '#atproto_pds', 'serviceEndpoint' => 'https://pds.example']]])]);

            ATProtoClient::resolvePDS('did:plc:alice');
            $this->assertSame(5, $this->requests[0]['options']['max_redirects']);
            $this->assertEquals(30, $this->requests[0]['options']['max_duration']);
        }

        // -----------------------------------------------------------------
        // discoverAuthServer()
        // -----------------------------------------------------------------

        public function testDiscoverAuthServerFollowsTheProtectedResourceMetadata()
        {
            $metadata = ['authorization_endpoint' => 'https://auth.example/authorize', 'token_endpoint' => 'https://auth.example/token'];
            $this->respondWith([
                self::json(['authorization_servers' => ['https://auth.example']]),
                self::json($metadata),
            ]);

            $this->assertSame($metadata, ATProtoClient::discoverAuthServer('https://pds.example'));
            $this->assertSame('https://pds.example/.well-known/oauth-protected-resource', $this->requests[0]['url']);
            $this->assertSame('https://auth.example/.well-known/oauth-authorization-server', $this->requests[1]['url']);
            $this->assertSame('GET', $this->requests[1]['method']);
        }

        public function testDiscoverAuthServerUsesThePdsItselfWithoutProtectedResourceMetadata()
        {
            $metadata = ['authorization_endpoint' => 'https://pds.example/authorize', 'token_endpoint' => 'https://pds.example/token'];
            $this->respondWith([self::failure(), self::json($metadata)]);

            $this->assertSame($metadata, ATProtoClient::discoverAuthServer('https://pds.example'));
            $this->assertSame('https://pds.example/.well-known/oauth-authorization-server', $this->requests[1]['url']);
        }

        public function testDiscoverAuthServerFailsForAnErrorTransportFailureOrIncompleteMetadata()
        {
            $this->respondWith([
                new MockResponse('', ['http_code' => 404]), new MockResponse('', ['http_code' => 500]),
                new MockResponse('', ['http_code' => 404]), self::failure(),
                new MockResponse('', ['http_code' => 404]), self::json(['token_endpoint' => 'https://pds.example/token']),
            ]);

            foreach (['Failed to fetch authorization server metadata from: https://pds.example', 'Failed to fetch authorization server metadata from: https://pds.example', 'Invalid authorization server metadata'] as $message) {
                try {
                    ATProtoClient::discoverAuthServer('https://pds.example');
                    $this->fail('An exception was expected.');
                } catch (\RuntimeException $e) {
                    $this->assertSame($message, $e->getMessage());
                }
            }
        }

        // -----------------------------------------------------------------
        // startOAuthFlow()
        // -----------------------------------------------------------------

        /** The responses of the discovery requests, then those given. */
        private function discoveryThen(array $responses, bool $par = true): array
        {
            $metadata = [
                'authorization_endpoint' => 'https://auth.example/authorize',
                'token_endpoint'         => 'https://auth.example/token',
            ];
            if ($par) {
                $metadata['pushed_authorization_request_endpoint'] = 'https://auth.example/par';
            }
            return array_merge([
                self::json(['service' => [['id' => '#atproto_pds', 'serviceEndpoint' => 'https://pds.example']]]),
                self::json(['authorization_servers' => ['https://auth.example']]),
                self::json($metadata),
            ], $responses);
        }

        public function testStartOAuthFlowPushesTheAuthorizationRequestAndRetriesWithTheNonce()
        {
            ATProtoClient::useTxtResolver(fn () => [['txt' => 'did=did:plc:alice']]);
            $this->respondWith($this->discoveryThen([
                self::json(['error' => 'use_dpop_nonce'], 400, ['DPoP-Nonce: nonce-1']),
                self::json(['request_uri' => 'urn:par:abc'], 201, ['DPoP-Nonce: nonce-2']),
            ]));

            $result = ATProtoClient::startOAuthFlow('alice.example');

            $this->assertCount(5, $this->requests);
            foreach ([3, 4] as $i) {
                $this->assertSame('POST', $this->requests[$i]['method']);
                $this->assertSame('https://auth.example/par', $this->requests[$i]['url']);
                $this->assertSame('application/x-www-form-urlencoded', $this->header($i, 'content-type'));
                $this->assertSame(0, $this->requests[$i]['options']['max_redirects']);
                $this->assertEquals(30, $this->requests[$i]['options']['max_duration']);
                $this->assertNull($this->header($i, 'authorization'));
                parse_str($this->body($i), $params);
                $this->assertSame('code', $params['response_type']);
                $this->assertSame('S256', $params['code_challenge_method']);
                $this->assertSame('alice.example', $params['login_hint']);
                $this->assertSame('atproto transition:generic', $params['scope']);
                $this->assertSame($result['state'], $params['state']);
            }
            $first = self::claims($this->header(3, 'dpop'));
            $second = self::claims($this->header(4, 'dpop'));
            $this->assertSame('POST', $first['htm']);
            $this->assertSame('https://auth.example/par', $first['htu']);
            $this->assertArrayNotHasKey('nonce', $first);
            $this->assertSame('nonce-1', $second['nonce']);

            $this->assertStringStartsWith('https://auth.example/authorize?', $result['url']);
            parse_str(parse_url($result['url'], PHP_URL_QUERY), $query);
            $this->assertSame('urn:par:abc', $query['request_uri']);
            $this->assertSame('nonce-2', $result['dpop_nonce']);
            $this->assertSame('did:plc:alice', $result['did']);
            $this->assertSame('https://pds.example', $result['pds']);
            $this->assertSame($result['dpop_key']['jwk']['kty'], 'EC');
        }

        public function testStartOAuthFlowThrowsTheErrorDocumentsMessage()
        {
            ATProtoClient::useTxtResolver(fn () => [['txt' => 'did=did:plc:alice']]);
            $this->respondWith($this->discoveryThen([self::json(['error' => 'invalid_request', 'error_description' => 'Bad scope'], 400)]));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('PAR request failed: Bad scope');
            ATProtoClient::startOAuthFlow('alice.example');
        }

        public function testStartOAuthFlowFailsWhenTheServerHasNoParEndpoint()
        {
            ATProtoClient::useTxtResolver(fn () => [['txt' => 'did=did:plc:alice']]);
            $this->respondWith($this->discoveryThen([], false));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Authorization server does not support PAR');
            ATProtoClient::startOAuthFlow('alice.example');
        }

        public function testStartOAuthFlowThrowsOnATransportFailure()
        {
            ATProtoClient::useTxtResolver(fn () => [['txt' => 'did=did:plc:alice']]);
            $this->respondWith($this->discoveryThen([self::failure()]));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('HTTP POST failed: ');
            ATProtoClient::startOAuthFlow('alice.example');
        }

        public function testStartOAuthFlowFailsWithoutARequestUri()
        {
            ATProtoClient::useTxtResolver(fn () => [['txt' => 'did=did:plc:alice']]);
            $this->respondWith($this->discoveryThen([self::json([], 201)]));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('PAR response missing request_uri');
            ATProtoClient::startOAuthFlow('alice.example');
        }

        public function testAPostThatIsRedirectedIsNotFollowed()
        {
            ATProtoClient::useTxtResolver(fn () => [['txt' => 'did=did:plc:alice']]);
            $this->respondWith($this->discoveryThen([new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://elsewhere.example/par']])]));

            try {
                ATProtoClient::startOAuthFlow('alice.example');
                $this->fail('An exception was expected.');
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith('PAR request failed: ', $e->getMessage());
            }
            $this->assertCount(4, $this->requests, 'The redirect was not followed.');
            $this->assertSame(0, $this->requests[3]['options']['max_redirects']);
        }

        // -----------------------------------------------------------------
        // completeOAuthFlow()
        // -----------------------------------------------------------------

        private static function pending(): array
        {
            return [
                'auth_server'   => ['token_endpoint' => 'https://auth.example/token'],
                'dpop_key'      => ATProtoClient::generateDPoPKeyPair(),
                'dpop_nonce'    => 'nonce-0',
                'code_verifier' => 'VERIFIER',
                'did'           => 'did:plc:alice',
                'pds'           => 'https://pds.example',
            ];
        }

        public function testCompleteOAuthFlowExchangesTheCode()
        {
            $this->respondWith([self::json(['access_token' => 'AT', 'refresh_token' => 'RT', 'sub' => 'did:plc:alice', 'expires_in' => 600, 'token_type' => 'DPoP'])]);

            $session = ATProtoClient::completeOAuthFlow('CODE', self::pending());

            $this->assertSame('POST', $this->requests[0]['method']);
            $this->assertSame('https://auth.example/token', $this->requests[0]['url']);
            $this->assertSame('application/x-www-form-urlencoded', $this->header(0, 'content-type'));
            $this->assertSame(0, $this->requests[0]['options']['max_redirects']);
            parse_str($this->body(0), $params);
            $this->assertSame('authorization_code', $params['grant_type']);
            $this->assertSame('CODE', $params['code']);
            $this->assertSame('VERIFIER', $params['code_verifier']);
            $this->assertSame('nonce-0', self::claims($this->header(0, 'dpop'))['nonce']);

            $this->assertSame('AT', $session['access_token']);
            $this->assertSame('RT', $session['refresh_token']);
            $this->assertSame('did:plc:alice', $session['did']);
            $this->assertGreaterThan(time() + 500, $session['expires_at']);
            $this->assertSame('nonce-0', $session['dpop_nonce_auth']);
            $this->assertNull($session['dpop_nonce_pds']);
        }

        public function testCompleteOAuthFlowRetriesWithTheServersNonce()
        {
            $this->respondWith([
                self::json(['error' => 'use_dpop_nonce'], 400, ['DPoP-Nonce: nonce-1']),
                self::json(['access_token' => 'AT', 'sub' => 'did:plc:alice'], 200, ['DPoP-Nonce: nonce-2']),
            ]);

            $session = ATProtoClient::completeOAuthFlow('CODE', self::pending());

            $this->assertCount(2, $this->requests);
            $this->assertSame('nonce-1', self::claims($this->header(1, 'dpop'))['nonce']);
            $this->assertSame('nonce-2', $session['dpop_nonce_auth']);
            $this->assertNull($session['refresh_token']);
        }

        public function testCompleteOAuthFlowRetriesOnA401WithANonceToo()
        {
            $this->respondWith([
                self::json(['error' => 'use_dpop_nonce'], 401, ['dpop-nonce: nonce-1']),
                self::json(['access_token' => 'AT', 'sub' => 'did:plc:alice']),
            ]);

            ATProtoClient::completeOAuthFlow('CODE', self::pending());
            $this->assertCount(2, $this->requests);
        }

        public function testCompleteOAuthFlowDoesNotRetryAnErrorWithoutANonce()
        {
            $this->respondWith([self::json(['error' => 'invalid_grant', 'error_description' => 'Code expired'], 400)]);

            try {
                ATProtoClient::completeOAuthFlow('CODE', self::pending());
                $this->fail('An exception was expected.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Token exchange failed: Code expired', $e->getMessage());
            }
            $this->assertCount(1, $this->requests);
        }

        public function testCompleteOAuthFlowRefusesAnotherAccount()
        {
            $this->respondWith([self::json(['access_token' => 'AT', 'sub' => 'did:plc:mallory'])]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Token DID mismatch: expected did:plc:alice, got did:plc:mallory');
            ATProtoClient::completeOAuthFlow('CODE', self::pending());
        }

        public function testCompleteOAuthFlowThrowsOnATransportFailure()
        {
            $this->respondWith([self::failure()]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('HTTP POST failed: ');
            ATProtoClient::completeOAuthFlow('CODE', self::pending());
        }

        // -----------------------------------------------------------------
        // refreshTokens()
        // -----------------------------------------------------------------

        public function testRefreshTokensExchangesTheRefreshToken()
        {
            $this->respondWith([self::json(['access_token' => 'AT2', 'refresh_token' => 'RT2', 'expires_in' => 600], 200, ['DPoP-Nonce: nonce-9'])]);
            $client = new ATProtoClient(self::session(['dpop_nonce_auth' => 'nonce-0']));

            $client->refreshTokens();

            $this->assertSame('POST', $this->requests[0]['method']);
            $this->assertSame('https://auth.example/token', $this->requests[0]['url']);
            $this->assertSame(0, $this->requests[0]['options']['max_redirects']);
            parse_str($this->body(0), $params);
            $this->assertSame('refresh_token', $params['grant_type']);
            $this->assertSame('REFRESH-TOKEN', $params['refresh_token']);
            $this->assertSame('nonce-0', self::claims($this->header(0, 'dpop'))['nonce']);
            $session = $client->getSession();
            $this->assertSame('AT2', $session['access_token']);
            $this->assertSame('RT2', $session['refresh_token']);
            $this->assertSame('nonce-0', $session['dpop_nonce_auth']);
        }

        public function testRefreshTokensRetriesWithTheServersNonce()
        {
            $this->respondWith([
                self::json(['error' => 'use_dpop_nonce'], 400, ['DPoP-Nonce: nonce-1']),
                self::json(['access_token' => 'AT2'], 200, ['DPoP-Nonce: nonce-2']),
            ]);
            $client = new ATProtoClient(self::session());

            $client->refreshTokens();

            $this->assertCount(2, $this->requests);
            $this->assertSame('nonce-1', self::claims($this->header(1, 'dpop'))['nonce']);
            $session = $client->getSession();
            $this->assertSame('nonce-2', $session['dpop_nonce_auth']);
            $this->assertSame('REFRESH-TOKEN', $session['refresh_token'], 'The old refresh token stays when none is returned.');
        }

        public function testRefreshTokensThrowsTheErrorDocumentsMessage()
        {
            $this->respondWith([self::json(['error' => 'invalid_grant', 'error_description' => 'Revoked'], 400)]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Token refresh failed: Revoked');
            (new ATProtoClient(self::session()))->refreshTokens();
        }

        public function testRefreshTokensThrowsOnATransportFailure()
        {
            $this->respondWith([self::failure()]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('HTTP POST failed: ');
            (new ATProtoClient(self::session()))->refreshTokens();
        }

        public function testRefreshTokensNeedsARefreshToken()
        {
            $this->respondWith([]);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('No refresh token available');
            (new ATProtoClient(self::session(['refresh_token' => null])))->refreshTokens();
        }

        // -----------------------------------------------------------------
        // xrpcPost() and xrpcGet()
        // -----------------------------------------------------------------

        public function testXrpcPostSendsAJsonBodyWithAnAccessTokenAndAProof()
        {
            $this->respondWith([self::json(['uri' => 'at://x', 'cid' => 'c'])]);
            $client = new ATProtoClient(self::session());

            $result = $client->xrpcPost('com.atproto.repo.putRecord', ['repo' => 'did:plc:alice', 'text' => 'a/b é']);

            $this->assertSame(['uri' => 'at://x', 'cid' => 'c'], $result);
            $this->assertSame('POST', $this->requests[0]['method']);
            $this->assertSame('https://pds.example/xrpc/com.atproto.repo.putRecord', $this->requests[0]['url']);
            $this->assertSame('{"repo":"did:plc:alice","text":"a/b é"}', $this->body(0));
            $this->assertSame('application/json', $this->header(0, 'content-type'));
            $this->assertStringStartsWith('DPoP ', $this->header(0, 'authorization'));
            $this->assertSame('Known-StandardSiteSync/0.1', $this->header(0, 'user-agent'));
            $claims = self::claims($this->header(0, 'dpop'));
            $this->assertSame('POST', $claims['htm']);
            $this->assertSame('https://pds.example/xrpc/com.atproto.repo.putRecord', $claims['htu']);
            $this->assertArrayHasKey('ath', $claims);
            $this->assertSame(0, $this->requests[0]['options']['max_redirects']);
            $this->assertEquals(30, $this->requests[0]['options']['max_duration']);
        }

        public function testXrpcPostRetriesAfterANonceChallenge()
        {
            $this->respondWith([
                self::json(['error' => 'use_dpop_nonce'], 401, ['DPoP-Nonce: pds-nonce']),
                self::json(['ok' => true], 200, ['DPoP-Nonce: pds-nonce-2']),
            ]);
            $client = new ATProtoClient(self::session());

            $this->assertSame(['ok' => true], $client->xrpcPost('com.atproto.repo.putRecord', ['a' => 1]));

            $this->assertCount(2, $this->requests);
            $this->assertArrayNotHasKey('nonce', self::claims($this->header(0, 'dpop')));
            $this->assertSame('pds-nonce', self::claims($this->header(1, 'dpop'))['nonce']);
            $this->assertSame('{"a":1}', $this->body(1));
            $this->assertSame('pds-nonce-2', $client->getSession()['dpop_nonce_pds']);
        }

        public function testXrpcPostThrowsTheErrorDocumentsMessage()
        {
            $this->respondWith([self::json(['error' => 'InvalidRequest', 'message' => 'Bad record'], 400)]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('XRPC error (400): Bad record');
            (new ATProtoClient(self::session()))->xrpcPost('com.atproto.repo.putRecord', []);
        }

        public function testXrpcPostThrowsOnATransportFailure()
        {
            $this->respondWith([self::failure()]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('HTTP POST failed: ');
            (new ATProtoClient(self::session()))->xrpcPost('com.atproto.repo.putRecord', []);
        }

        public function testAnExpiredTokenIsRefreshedFirst()
        {
            $this->respondWith([
                self::json(['access_token' => 'AT2', 'expires_in' => 600]),
                self::json(['ok' => true]),
            ]);
            $client = new ATProtoClient(self::session(['expires_at' => time() + 10]));

            $client->xrpcPost('com.atproto.repo.putRecord', []);

            $this->assertSame('https://auth.example/token', $this->requests[0]['url']);
            $this->assertSame('https://pds.example/xrpc/com.atproto.repo.putRecord', $this->requests[1]['url']);
        }

        public function testXrpcGetSendsTheQueryInTheUrlAndNoBody()
        {
            $this->respondWith([self::json(['value' => ['a' => 1]])]);
            $client = new ATProtoClient(self::session());

            $result = $client->xrpcGet('com.atproto.repo.getRecord', ['repo' => 'did:plc:alice', 'rkey' => 'a b']);

            $this->assertSame(['value' => ['a' => 1]], $result);
            $this->assertSame('GET', $this->requests[0]['method']);
            $this->assertSame('https://pds.example/xrpc/com.atproto.repo.getRecord?repo=did%3Aplc%3Aalice&rkey=a+b', $this->requests[0]['url']);
            $this->assertSame('', $this->body(0));
            $this->assertNull($this->header(0, 'content-type'));
            $this->assertStringStartsWith('DPoP ', $this->header(0, 'authorization'));
            $claims = self::claims($this->header(0, 'dpop'));
            $this->assertSame('GET', $claims['htm']);
            $this->assertSame($this->requests[0]['url'], $claims['htu']);
            $this->assertSame(5, $this->requests[0]['options']['max_redirects']);
        }

        public function testXrpcGetWithoutParametersHasNoQueryString()
        {
            $this->respondWith([self::json([])]);

            $this->assertSame([], (new ATProtoClient(self::session()))->xrpcGet('com.atproto.server.getSession'));
            $this->assertSame('https://pds.example/xrpc/com.atproto.server.getSession', $this->requests[0]['url']);
        }

        public function testXrpcGetRetriesAfterANonceChallenge()
        {
            $this->respondWith([
                self::json(['error' => 'use_dpop_nonce'], 401, ['DPoP-Nonce: pds-nonce']),
                self::json(['ok' => 1]),
            ]);

            $this->assertSame(['ok' => 1], (new ATProtoClient(self::session()))->xrpcGet('com.atproto.server.getSession'));
            $this->assertSame('pds-nonce', self::claims($this->header(1, 'dpop'))['nonce']);
        }

        public function testXrpcGetOnATransportFailureIsATypeErrorAsItAlwaysWas()
        {
            // The GET helper returns null when the request fails, and authenticatedRequest() passes
            // that to a function that takes an array: documentExists() does not catch it either.
            $this->respondWith([self::failure(), self::failure()]);
            $client = new ATProtoClient(self::session());

            try {
                $client->xrpcGet('com.atproto.server.getSession');
                $this->fail('An exception was expected.');
            } catch (\TypeError $e) {
                $this->assertTrue(true);
            }
            $this->expectException(\TypeError::class);
            $client->documentExists(self::entity());
        }

        public function testXrpcGetThrowsTheErrorDocumentsMessage()
        {
            $this->respondWith([self::json(['error' => 'RecordNotFound', 'message' => 'Could not locate record'], 400)]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('XRPC error (400): Could not locate record');
            (new ATProtoClient(self::session()))->xrpcGet('com.atproto.repo.getRecord');
        }

        // -----------------------------------------------------------------
        // Limits, and what a message may hold
        // -----------------------------------------------------------------

        public function testAResponseLongerThanOneMebibyteIsCutAndSoDoesNotParse()
        {
            $long = json_encode(['pad' => str_repeat('a', 2 * 1048576), 'ok' => true]);
            $this->respondWith([new MockResponse($long), new MockResponse($long)]);

            $this->assertSame([], (new ATProtoClient(self::session()))->xrpcPost('com.atproto.repo.putRecord', []));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('No service entries in DID document');
            ATProtoClient::resolvePDS('did:plc:alice');
        }

        public function testAnErrorWithoutAMessageNamesOnlyTheStatusNeverTheBody()
        {
            $this->respondWith([
                new MockResponse('<html>token=ACCESS-TOKEN secret page</html>', ['http_code' => 502]),
                new MockResponse('ACCESS-TOKEN', ['http_code' => 400]),
                new MockResponse('REFRESH-TOKEN', ['http_code' => 400]),
                new MockResponse('AUTHORIZATION-CODE', ['http_code' => 400]),
            ]);
            $client = new ATProtoClient(self::session());

            try {
                $client->xrpcPost('com.atproto.repo.putRecord', []);
                $this->fail('An exception was expected.');
            } catch (\RuntimeException $e) {
                $this->assertSame('XRPC error (502): HTTP 502', $e->getMessage());
            }
            try {
                $client->refreshTokens();
                $this->fail('An exception was expected.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Token refresh failed: HTTP 400', $e->getMessage());
            }
            try {
                ATProtoClient::completeOAuthFlow('AUTHORIZATION-CODE', self::pending());
                $this->fail('An exception was expected.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Token exchange failed: HTTP 400', $e->getMessage());
            }
        }

        public function testAnErrorDocumentsTextIsBoundedAndHasNoControlCharacters()
        {
            $this->respondWith([self::json(['message' => "line\nbreak" . str_repeat('x', 500)], 400)]);

            try {
                (new ATProtoClient(self::session()))->xrpcPost('com.atproto.repo.putRecord', []);
                $this->fail('An exception was expected.');
            } catch (\RuntimeException $e) {
                $this->assertStringNotContainsString("\n", $e->getMessage());
                $this->assertLessThan(260, strlen($e->getMessage()));
            }
        }

        public function testATransportFailureMessageHasNoQueryString()
        {
            $this->respondWith([new MockResponse('', ['error' => 'Could not connect to "https://pds.example/xrpc/x?token=SECRET#frag"'])]);

            try {
                (new ATProtoClient(self::session()))->xrpcPost('com.atproto.repo.putRecord', []);
                $this->fail('An exception was expected.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('https://pds.example/xrpc/x', $e->getMessage());
                $this->assertStringNotContainsString('SECRET', $e->getMessage());
            }
        }

        public function testAMetadataIssuerInAMessageHasNoQueryString()
        {
            $this->respondWith([
                self::json(['authorization_servers' => ['https://auth.example/base?secret=1']]),
                new MockResponse('', ['http_code' => 404]),
            ]);

            try {
                ATProtoClient::discoverAuthServer('https://pds.example');
                $this->fail('An exception was expected.');
            } catch (\RuntimeException $e) {
                $this->assertStringNotContainsString('secret=1', $e->getMessage());
            }
        }

        // -----------------------------------------------------------------
        // The record operations
        // -----------------------------------------------------------------

        public function testPutPublicationWritesThePublicationRecord()
        {
            $this->respondWith([self::json(['uri' => 'at://did:plc:alice/site.standard.publication/self'])]);

            $result = (new ATProtoClient(self::session()))->putPublication();

            $this->assertSame('at://did:plc:alice/site.standard.publication/self', $result['uri']);
            $this->assertSame('POST', $this->requests[0]['method']);
            $this->assertSame('https://pds.example/xrpc/com.atproto.repo.putRecord', $this->requests[0]['url']);
            $body = json_decode($this->body(0), true);
            $this->assertSame('did:plc:alice', $body['repo']);
            $this->assertSame('site.standard.publication', $body['collection']);
            $this->assertSame('self', $body['rkey']);
            $this->assertSame('site.standard.publication', $body['record']['$type']);
            $this->assertSame(\Idno\Core\Idno::site()->config()->getDisplayURL(), $body['record']['url']);
        }

        public function testPutDocumentWritesTheDocumentRecord()
        {
            $this->respondWith([self::json(['uri' => 'at://x'])]);

            $result = (new ATProtoClient(self::session()))->putDocument(self::entity());

            $this->assertSame(['uri' => 'at://x'], $result);
            $this->assertSame('https://pds.example/xrpc/com.atproto.repo.putRecord', $this->requests[0]['url']);
            $body = json_decode($this->body(0), true);
            $this->assertSame('site.standard.document', $body['collection']);
            $this->assertSame('abc123', $body['rkey']);
            $this->assertSame('site.standard.document', $body['record']['$type']);
            $this->assertSame('A title', $body['record']['title']);
            $this->assertSame('at://did:plc:alice/site.standard.publication/self', $body['record']['site']);
            $this->assertSame('/entry/abc123/a-title', $body['record']['path']);
            $this->assertSame('<p>Hello <b>world</b></p>', $body['record']['content']['html']);
        }

        public function testPutDocumentThrowsWhenTheServerRefuses()
        {
            $this->respondWith([self::json(['error' => 'X', 'message' => 'Refused'], 400)]);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('XRPC error (400): Refused');
            (new ATProtoClient(self::session()))->putDocument(self::entity());
        }

        public function testDeleteDocumentDeletesTheRecord()
        {
            $this->respondWith([self::json([])]);

            $this->assertSame([], (new ATProtoClient(self::session()))->deleteDocument(self::entity()));

            $this->assertSame('POST', $this->requests[0]['method']);
            $this->assertSame('https://pds.example/xrpc/com.atproto.repo.deleteRecord', $this->requests[0]['url']);
            $this->assertSame(
                ['repo' => 'did:plc:alice', 'collection' => 'site.standard.document', 'rkey' => 'abc123'],
                json_decode($this->body(0), true)
            );
        }

        public function testDeleteDocumentThrowsOnATransportFailure()
        {
            $this->respondWith([self::failure()]);

            $this->expectException(\RuntimeException::class);
            (new ATProtoClient(self::session()))->deleteDocument(self::entity());
        }

        public function testDocumentExistsIsTrueForARecordAndFalseForAnError()
        {
            $this->respondWith([
                self::json(['uri' => 'at://x']),
                self::json(['error' => 'RecordNotFound', 'message' => 'Not found'], 400),
            ]);
            $client = new ATProtoClient(self::session());

            $this->assertTrue($client->documentExists(self::entity()));
            $this->assertSame('GET', $this->requests[0]['method']);
            $this->assertSame(
                'https://pds.example/xrpc/com.atproto.repo.getRecord?repo=did%3Aplc%3Aalice&collection=site.standard.document&rkey=abc123',
                $this->requests[0]['url']
            );
            $this->assertFalse($client->documentExists(self::entity()));
        }

        public function testEntityToRkeyIsTheEntityId()
        {
            $this->assertSame('abc123', (new ATProtoClient(self::session()))->entityToRkey(self::entity()));
        }
    }
}
