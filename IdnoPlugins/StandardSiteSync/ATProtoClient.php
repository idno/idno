<?php

namespace IdnoPlugins\StandardSiteSync;

/**
 * AT Protocol client handling OAuth (DPoP, PAR) and XRPC API calls.
 *
 * Implements the full atproto OAuth profile:
 *  - Authorization server discovery via /.well-known/oauth-authorization-server
 *  - Pushed Authorization Requests (PAR)
 *  - DPoP proof generation with ES256 and server-issued nonces
 *  - Token exchange and refresh
 *  - XRPC procedure calls (createRecord, putRecord, getRecord, deleteRecord)
 */
class ATProtoClient
{
    /** @var array Session data (tokens, DID, PDS URL, DPoP keys, nonces) */
    private $session;

    /**
     * Replaces the HTTP transport, for tests: a callable that takes the method, the
     * address, the body (or null) and the list of header lines, and returns what
     * httpGet() and httpPost() do: ['status' => int, 'body' => string, 'headers' => string[]].
     *
     * @var callable|null
     */
    public static $transport = null;

    /**
     * @param array $session Stored session data from the user entity
     */
    public function __construct(array $session = [])
    {
        $this->session = $session;
    }

    /**
     * Get the current session data for persistence.
     */
    public function getSession(): array
    {
        return $this->session;
    }

    // ------------------------------------------------------------------
    // OAuth: Discovery
    // ------------------------------------------------------------------

    /**
     * Resolve a handle to a DID and PDS endpoint.
     *
     * @param string $handle User handle (e.g. user.bsky.social)
     * @return array{did: string, pds: string}
     */
    public static function resolveIdentity(string $handle): array
    {
        // Try DNS TXT _atproto.<handle>
        $records = @dns_get_record('_atproto.' . $handle, DNS_TXT);
        $did = null;
        if ($records) {
            foreach ($records as $r) {
                if (!empty($r['txt']) && strpos($r['txt'], 'did=') === 0) {
                    $did = substr($r['txt'], 4);
                    break;
                }
            }
        }

        // Fallback: resolve via /.well-known/atproto-did
        if (empty($did)) {
            $resp = self::httpGet('https://' . $handle . '/.well-known/atproto-did');
            if ($resp && $resp['status'] >= 200 && $resp['status'] < 300) {
                $did = trim($resp['body']);
            }
        }

        if (empty($did)) {
            throw new \Idno\Exceptions\TechnicalException('Could not resolve handle to DID: ' . $handle);
        }

        // Resolve DID document to find PDS
        $pds = self::resolvePDS($did);

        return ['did' => $did, 'pds' => $pds];
    }

    /**
     * Resolve a DID to find the PDS service endpoint.
     */
    public static function resolvePDS(string $did): string
    {
        if (strpos($did, 'did:plc:') === 0) {
            $resp = self::httpGet('https://plc.directory/' . $did);
        } elseif (strpos($did, 'did:web:') === 0) {
            $domain = substr($did, 8);
            $domain = str_replace(':', '/', $domain);
            $resp = self::httpGet('https://' . $domain . '/.well-known/did.json');
        } else {
            throw new \Idno\Exceptions\TechnicalException('Unsupported DID method: ' . $did);
        }

        if (!$resp || $resp['status'] !== 200) {
            throw new \Idno\Exceptions\TechnicalException('Failed to resolve DID document for: ' . $did);
        }

        $doc = json_decode($resp['body'], true);
        if (empty($doc['service'])) {
            throw new \Idno\Exceptions\TechnicalException('No service entries in DID document for: ' . $did);
        }

        foreach ($doc['service'] as $svc) {
            if (
                ($svc['id'] ?? '') === '#atproto_pds' ||
                ($svc['type'] ?? '') === 'AtprotoPersonalDataServer'
            ) {
                return rtrim($svc['serviceEndpoint'], '/');
            }
        }

        throw new \Idno\Exceptions\TechnicalException('No PDS service endpoint found in DID document for: ' . $did);
    }

    /**
     * Discover the authorization server metadata from a PDS.
     */
    public static function discoverAuthServer(string $pdsUrl): array
    {
        // First get the PDS's protected resource metadata
        $resp = self::httpGet($pdsUrl . '/.well-known/oauth-protected-resource');
        $authServerIssuer = null;

        if ($resp && $resp['status'] === 200) {
            $meta = json_decode($resp['body'], true);
            if (!empty($meta['authorization_servers'][0])) {
                $authServerIssuer = $meta['authorization_servers'][0];
            }
        }

        // If no separate auth server, the PDS itself is the auth server
        if (empty($authServerIssuer)) {
            $authServerIssuer = $pdsUrl;
        }

        $resp = self::httpGet($authServerIssuer . '/.well-known/oauth-authorization-server');
        if (!$resp || $resp['status'] !== 200) {
            throw new \Idno\Exceptions\TechnicalException('Failed to fetch authorization server metadata from: ' . $authServerIssuer);
        }

        $metadata = json_decode($resp['body'], true);
        if (empty($metadata['authorization_endpoint']) || empty($metadata['token_endpoint'])) {
            throw new \Idno\Exceptions\TechnicalException('Invalid authorization server metadata');
        }

        return $metadata;
    }

    // ------------------------------------------------------------------
    // OAuth: DPoP
    // ------------------------------------------------------------------

    /**
     * Generate an ES256 keypair for DPoP proofs.
     *
     * @return array{public: string, private: string, jwk: array}
     */
    public static function generateDPoPKeyPair(): array
    {
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if (!$key) {
            throw new \Idno\Exceptions\TechnicalException('Failed to generate ES256 key: ' . openssl_error_string());
        }

        openssl_pkey_export($key, $privatePem);
        $details = openssl_pkey_get_details($key);

        $jwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x'   => self::base64url($details['ec']['x']),
            'y'   => self::base64url($details['ec']['y']),
        ];

        return [
            'public'  => $details['key'],
            'private' => $privatePem,
            'jwk'     => $jwk,
        ];
    }

    /**
     * Create a DPoP proof JWT.
     *
     * @param string      $privateKeyPem  ES256 private key (PEM)
     * @param array       $publicJwk      The public JWK (for the header)
     * @param string      $httpMethod     HTTP method (GET, POST, etc.)
     * @param string      $httpUri        The target URL
     * @param string|null $nonce          Server-issued DPoP nonce
     * @param string|null $accessToken    If making a resource request, the access token (for ath claim)
     * @return string Signed JWT
     */
    public static function createDPoPProof(
        string $privateKeyPem,
        array $publicJwk,
        string $httpMethod,
        string $httpUri,
        ?string $nonce = null,
        ?string $accessToken = null
    ): string
    {
        $header = [
            'typ' => 'dpop+jwt',
            'alg' => 'ES256',
            'jwk' => $publicJwk,
        ];

        $payload = [
            'jti' => bin2hex(random_bytes(16)),
            'htm' => strtoupper($httpMethod),
            'htu' => $httpUri,
            'iat' => time(),
        ];

        if ($nonce !== null) {
            $payload['nonce'] = $nonce;
        }

        if ($accessToken !== null) {
            $payload['ath'] = self::base64url(hash('sha256', $accessToken, true));
        }

        return self::signJwt($header, $payload, $privateKeyPem);
    }

    // ------------------------------------------------------------------
    // OAuth: Flow
    // ------------------------------------------------------------------

    /**
     * Build the client metadata URL for this Known installation.
     */
    public static function getClientMetadataUrl(): string
    {
        return \Idno\Core\Idno::site()->config()->getDisplayURL() . 'standardsitesync/client-metadata.json';
    }

    /**
     * Build the OAuth callback URL.
     */
    public static function getCallbackUrl(): string
    {
        return \Idno\Core\Idno::site()->config()->getDisplayURL() . 'account/settings/standardsitesync/callback';
    }

    /**
     * Initiate the OAuth authorization flow.
     * Returns the URL to redirect the user to.
     *
     * @param string $handle The AT Protocol handle to authenticate with
     * @return array{url: string, state: string, code_verifier: string, dpop_key: array, auth_server: array, did: string, pds: string}
     */
    public static function startOAuthFlow(string $handle): array
    {
        // 1. Resolve identity
        $identity = self::resolveIdentity($handle);

        // 2. Discover auth server
        $authServer = self::discoverAuthServer($identity['pds']);

        // 3. Generate PKCE
        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = self::base64url(hash('sha256', $codeVerifier, true));

        // 4. Generate DPoP keypair
        $dpopKey = self::generateDPoPKeyPair();

        // 5. Generate state
        $state = bin2hex(random_bytes(16));

        // 6. PAR request
        $parEndpoint = $authServer['pushed_authorization_request_endpoint'] ?? null;
        if (empty($parEndpoint)) {
            throw new \Idno\Exceptions\TechnicalException('Authorization server does not support PAR');
        }

        $clientId = self::getClientMetadataUrl();
        $redirectUri = self::getCallbackUrl();

        $parParams = [
            'response_type'         => 'code',
            'client_id'             => $clientId,
            'redirect_uri'          => $redirectUri,
            'state'                 => $state,
            'scope'                 => 'atproto transition:generic',
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
            'login_hint'            => $handle,
        ];

        // First PAR attempt (will likely fail with DPoP nonce requirement)
        $dpopProof = self::createDPoPProof(
            $dpopKey['private'],
            $dpopKey['jwk'],
            'POST',
            $parEndpoint
        );

        $parResponse = self::httpPost($parEndpoint, http_build_query($parParams), [
            'Content-Type: application/x-www-form-urlencoded',
            'DPoP: ' . $dpopProof,
        ]);

        // Handle DPoP nonce requirement (expected on first attempt)
        $dpopNonce = self::extractDPoPNonce($parResponse);
        if ($dpopNonce && $parResponse['status'] === 400) {
            $dpopProof = self::createDPoPProof(
                $dpopKey['private'],
                $dpopKey['jwk'],
                'POST',
                $parEndpoint,
                $dpopNonce
            );

            $parResponse = self::httpPost($parEndpoint, http_build_query($parParams), [
                'Content-Type: application/x-www-form-urlencoded',
                'DPoP: ' . $dpopProof,
            ]);

            // Update nonce from response
            $newNonce = self::extractDPoPNonce($parResponse);
            if ($newNonce) {
                $dpopNonce = $newNonce;
            }
        }

        if ($parResponse['status'] !== 201 && $parResponse['status'] !== 200) {
            $err = json_decode($parResponse['body'], true);
            throw new \Idno\Exceptions\TechnicalException('PAR request failed: ' . ($err['error_description'] ?? $err['error'] ?? $parResponse['body']));
        }

        $parData = json_decode($parResponse['body'], true);
        $requestUri = $parData['request_uri'] ?? null;
        if (empty($requestUri)) {
            throw new \Idno\Exceptions\TechnicalException('PAR response missing request_uri');
        }

        // 7. Build authorization URL
        $authUrl = $authServer['authorization_endpoint']
            . '?' . http_build_query([
                'client_id'   => $clientId,
                'request_uri' => $requestUri,
            ]);

        return [
            'url'           => $authUrl,
            'state'         => $state,
            'code_verifier' => $codeVerifier,
            'dpop_key'      => $dpopKey,
            'dpop_nonce'    => $dpopNonce,
            'auth_server'   => $authServer,
            'did'           => $identity['did'],
            'pds'           => $identity['pds'],
        ];
    }

    /**
     * Complete the OAuth flow by exchanging the authorization code for tokens.
     *
     * @param string $code            The authorization code
     * @param array  $pendingAuth     The pending auth data from startOAuthFlow
     * @return array Session data to persist
     */
    public static function completeOAuthFlow(string $code, array $pendingAuth): array
    {
        $tokenEndpoint = $pendingAuth['auth_server']['token_endpoint'];
        $dpopKey = $pendingAuth['dpop_key'];
        $dpopNonce = $pendingAuth['dpop_nonce'] ?? null;

        $tokenParams = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => self::getCallbackUrl(),
            'client_id'     => self::getClientMetadataUrl(),
            'code_verifier' => $pendingAuth['code_verifier'],
        ];

        // First token request (may need DPoP nonce)
        $dpopProof = self::createDPoPProof(
            $dpopKey['private'],
            $dpopKey['jwk'],
            'POST',
            $tokenEndpoint,
            $dpopNonce
        );

        $response = self::httpPost($tokenEndpoint, http_build_query($tokenParams), [
            'Content-Type: application/x-www-form-urlencoded',
            'DPoP: ' . $dpopProof,
        ]);

        // Handle DPoP nonce challenge
        $newNonce = self::extractDPoPNonce($response);
        if ($newNonce && ($response['status'] === 400 || $response['status'] === 401)) {
            $dpopNonce = $newNonce;

            $dpopProof = self::createDPoPProof(
                $dpopKey['private'],
                $dpopKey['jwk'],
                'POST',
                $tokenEndpoint,
                $dpopNonce
            );

            $response = self::httpPost($tokenEndpoint, http_build_query($tokenParams), [
                'Content-Type: application/x-www-form-urlencoded',
                'DPoP: ' . $dpopProof,
            ]);

            $newNonce = self::extractDPoPNonce($response);
            if ($newNonce) {
                $dpopNonce = $newNonce;
            }
        }

        if ($response['status'] !== 200) {
            $err = json_decode($response['body'], true);
            throw new \Idno\Exceptions\TechnicalException('Token exchange failed: ' . ($err['error_description'] ?? $err['error'] ?? $response['body']));
        }

        $tokenData = json_decode($response['body'], true);

        // Verify the sub matches the expected DID
        if (($tokenData['sub'] ?? '') !== $pendingAuth['did']) {
            throw new \Idno\Exceptions\TechnicalException('Token DID mismatch: expected ' . $pendingAuth['did'] . ', got ' . ($tokenData['sub'] ?? 'none'));
        }

        return [
            'did'             => $pendingAuth['did'],
            'pds'             => $pendingAuth['pds'],
            'access_token'    => $tokenData['access_token'],
            'refresh_token'   => $tokenData['refresh_token'] ?? null,
            'token_type'      => $tokenData['token_type'] ?? 'DPoP',
            'expires_at'      => time() + ($tokenData['expires_in'] ?? 300),
            'dpop_key'        => $dpopKey,
            'dpop_nonce_auth' => $dpopNonce,
            'dpop_nonce_pds'  => null,
            'auth_server'     => $pendingAuth['auth_server'],
        ];
    }

    /**
     * Refresh the access token using the refresh token.
     */
    public function refreshTokens(): void
    {
        if (empty($this->session['refresh_token'])) {
            throw new \Idno\Exceptions\TechnicalException('No refresh token available');
        }

        $tokenEndpoint = $this->session['auth_server']['token_endpoint'];
        $dpopKey = $this->session['dpop_key'];
        $dpopNonce = $this->session['dpop_nonce_auth'] ?? null;

        $params = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->session['refresh_token'],
            'client_id'     => self::getClientMetadataUrl(),
        ];

        $dpopProof = self::createDPoPProof(
            $dpopKey['private'],
            $dpopKey['jwk'],
            'POST',
            $tokenEndpoint,
            $dpopNonce
        );

        $response = self::httpPost($tokenEndpoint, http_build_query($params), [
            'Content-Type: application/x-www-form-urlencoded',
            'DPoP: ' . $dpopProof,
        ]);

        $newNonce = self::extractDPoPNonce($response);
        if ($newNonce && ($response['status'] === 400 || $response['status'] === 401)) {
            $dpopNonce = $newNonce;

            $dpopProof = self::createDPoPProof(
                $dpopKey['private'],
                $dpopKey['jwk'],
                'POST',
                $tokenEndpoint,
                $dpopNonce
            );

            $response = self::httpPost($tokenEndpoint, http_build_query($params), [
                'Content-Type: application/x-www-form-urlencoded',
                'DPoP: ' . $dpopProof,
            ]);

            $newNonce = self::extractDPoPNonce($response);
            if ($newNonce) {
                $dpopNonce = $newNonce;
            }
        }

        if ($response['status'] !== 200) {
            // The answer of the server is not repeated: only its status and the name of the error
            $err = json_decode($response['body'], true);
            throw new XrpcException('Token refresh failed', (int) $response['status'], is_array($err) ? ($err['error'] ?? null) : null);
        }

        $tokenData = json_decode($response['body'], true);

        $this->session['access_token']    = $tokenData['access_token'];
        $this->session['refresh_token']   = $tokenData['refresh_token'] ?? $this->session['refresh_token'];
        $this->session['expires_at']      = time() + ($tokenData['expires_in'] ?? 300);
        $this->session['dpop_nonce_auth'] = $dpopNonce;
    }

    /**
     * Ensure we have a valid access token, refreshing if necessary.
     */
    private function ensureValidToken(): void
    {
        if (empty($this->session['access_token'])) {
            throw new \Idno\Exceptions\TechnicalException('Not authenticated with AT Protocol PDS');
        }

        // Refresh if token expires within 60 seconds
        if (time() >= ($this->session['expires_at'] - 60)) {
            $this->refreshTokens();
        }
    }

    // ------------------------------------------------------------------
    // XRPC API calls
    // ------------------------------------------------------------------

    /**
     * Make an authenticated XRPC procedure call (POST).
     *
     * @param string $nsid   The XRPC method name (e.g. com.atproto.repo.createRecord)
     * @param array  $body   JSON body
     * @return array Response data
     */
    public function xrpcPost(string $nsid, array $body): array
    {
        $this->ensureValidToken();

        $url = $this->session['pds'] . '/xrpc/' . $nsid;
        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->authenticatedRequest('POST', $url, $jsonBody);
    }

    /**
     * Make an authenticated XRPC query (GET).
     *
     * @param string $nsid   The XRPC method name
     * @param array  $params Query parameters
     * @return array Response data
     */
    public function xrpcGet(string $nsid, array $params = []): array
    {
        $this->ensureValidToken();

        $url = $this->session['pds'] . '/xrpc/' . $nsid;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        return $this->authenticatedRequest('GET', $url);
    }

    /**
     * Make an authenticated request with DPoP proof and automatic nonce retry.
     */
    private function authenticatedRequest(string $method, string $url, ?string $body = null, string $contentType = 'application/json'): array
    {
        $dpopKey = $this->session['dpop_key'];
        $dpopNonce = $this->session['dpop_nonce_pds'] ?? null;

        $dpopProof = self::createDPoPProof(
            $dpopKey['private'],
            $dpopKey['jwk'],
            $method,
            $url,
            $dpopNonce,
            $this->session['access_token']
        );

        $headers = [
            'Authorization: DPoP ' . $this->session['access_token'],
            'DPoP: ' . $dpopProof,
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        if ($method === 'POST') {
            $response = self::httpPost($url, $body, $headers);
        } else {
            $response = self::httpGet($url, $headers) ?? ['status' => 0, 'body' => '', 'headers' => []];
        }

        // Handle DPoP nonce challenge
        $newNonce = self::extractDPoPNonce($response);
        if ($newNonce) {
            $this->session['dpop_nonce_pds'] = $newNonce;
        }

        if ($response['status'] === 401 && $newNonce) {
            // Retry with new nonce
            $dpopProof = self::createDPoPProof(
                $dpopKey['private'],
                $dpopKey['jwk'],
                $method,
                $url,
                $newNonce,
                $this->session['access_token']
            );

            $headers = [
                'Authorization: DPoP ' . $this->session['access_token'],
                'DPoP: ' . $dpopProof,
            ];

            if ($body !== null) {
                $headers[] = 'Content-Type: ' . $contentType;
            }

            if ($method === 'POST') {
                $response = self::httpPost($url, $body, $headers);
            } else {
                $response = self::httpGet($url, $headers) ?? ['status' => 0, 'body' => '', 'headers' => []];
            }

            $newNonce = self::extractDPoPNonce($response);
            if ($newNonce) {
                $this->session['dpop_nonce_pds'] = $newNonce;
            }
        }

        if ($response['status'] >= 400 || $response['status'] < 200) {
            // The answer of the server is not repeated: only its status and the name of the error
            $err = json_decode($response['body'], true);
            throw new XrpcException('XRPC error', (int) $response['status'], is_array($err) ? ($err['error'] ?? null) : null);
        }

        return json_decode($response['body'], true) ?: [];
    }

    // ------------------------------------------------------------------
    // Records of any collection
    // ------------------------------------------------------------------

    /**
     * The DID of the account this client writes to.
     */
    public function getDid(): string
    {
        return (string) ($this->session['did'] ?? '');
    }

    /**
     * Write a record under a key: it is created, or the record that has the key is
     * replaced (com.atproto.repo.putRecord). Writing the same record twice leaves one.
     *
     * @param  string      $collection The NSID of the collection
     * @param  string      $rkey       The record key
     * @param  array       $record     The record
     * @param  string|null $swapRecord When given, the write is refused (error "InvalidSwap") unless the
     *                                 record now under the key has this CID
     * @return array 'uri' and 'cid' of the record
     */
    public function putRecord(string $collection, string $rkey, array $record, ?string $swapRecord = null): array
    {
        $body = [
            'repo'       => $this->session['did'],
            'collection' => $collection,
            'rkey'       => $rkey,
            'record'     => $record,
        ];
        if ($swapRecord !== null) {
            $body['swapRecord'] = $swapRecord;
        }

        return $this->xrpcPost('com.atproto.repo.putRecord', $body);
    }

    /**
     * Delete a record (com.atproto.repo.deleteRecord). Deleting a record that is not
     * there is not an error.
     */
    public function deleteRecord(string $collection, string $rkey): array
    {
        return $this->xrpcPost('com.atproto.repo.deleteRecord', [
            'repo'       => $this->session['did'],
            'collection' => $collection,
            'rkey'       => $rkey,
        ]);
    }

    /**
     * Read a record of the account's own repository.
     *
     * @return array|null 'uri', 'cid' and 'value'; null when there is no such record
     */
    public function getRecord(string $collection, string $rkey): ?array
    {
        try {
            return $this->xrpcGet('com.atproto.repo.getRecord', [
                'repo'       => $this->session['did'],
                'collection' => $collection,
                'rkey'       => $rkey,
            ]);
        } catch (XrpcException $e) {
            if ($e->getErrorName() === 'RecordNotFound' || $e->getStatus() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Upload a file to be referenced from a record (com.atproto.repo.uploadBlob).
     *
     * @param  string $bytes    The file
     * @param  string $mimeType Its type
     * @return array|null The blob, to be put into a record as it is; null when the answer had none
     */
    public function uploadBlob(string $bytes, string $mimeType): ?array
    {
        $this->ensureValidToken();

        $answer = $this->authenticatedRequest('POST', $this->session['pds'] . '/xrpc/com.atproto.repo.uploadBlob', $bytes, $mimeType);

        return is_array($answer['blob'] ?? null) ? $answer['blob'] : null;
    }

    // ------------------------------------------------------------------
    // Standard.site record operations
    // ------------------------------------------------------------------

    /**
     * Create or update the publication record.
     *
     * @return array The created/updated record (with uri and cid)
     */
    public function putPublication(): array
    {
        $siteConfig = \Idno\Core\Idno::site()->config();

        $record = [
            '$type'       => 'site.standard.publication',
            'url'         => $siteConfig->getDisplayURL(),
            'name'        => $siteConfig->getTitle(),
            'description' => $siteConfig->getDescription(),
            'createdAt'   => date(\DateTime::RFC3339),
        ];

        return $this->xrpcPost('com.atproto.repo.putRecord', [
            'repo'       => $this->session['did'],
            'collection' => 'site.standard.publication',
            'rkey'       => 'self',
            'record'     => $record,
        ]);
    }

    /**
     * Create or update a document record for an Idno entity.
     *
     * @param \Idno\Common\Entity $entity The content entity
     * @return array The created/updated record (with uri and cid)
     */
    public function putDocument(\Idno\Common\Entity $entity): array
    {
        $publicationUri = 'at://' . $this->session['did'] . '/site.standard.publication/self';

        // Build a record key from the entity ID (must be valid rkey: alphanumeric)
        $rkey = $this->entityToRkey($entity);

        // Get plain text content
        $body = $entity->getBody();
        $textContent = strip_tags($body);

        // Get HTML content for the content field
        $htmlContent = $body;

        // Build the path from the entity URL relative to the site URL
        $siteUrl = \Idno\Core\Idno::site()->config()->getDisplayURL();
        $entityUrl = $entity->getURL();
        $path = '/';
        if (strpos($entityUrl, $siteUrl) === 0) {
            $path = '/' . ltrim(substr($entityUrl, strlen($siteUrl)), '/');
        }

        $record = [
            '$type'       => 'site.standard.document',
            'title'       => $entity->getTitle(),
            'description' => $entity->getDescription(),
            'site'        => $publicationUri,
            'path'        => $path,
            'content'     => [
                '$type' => 'co.idno.html',
                'html'  => $htmlContent,
            ],
            'publishedAt' => date(\DateTime::RFC3339, $entity->created),
        ];

        return $this->xrpcPost('com.atproto.repo.putRecord', [
            'repo'       => $this->session['did'],
            'collection' => 'site.standard.document',
            'rkey'       => $rkey,
            'record'     => $record,
        ]);
    }

    /**
     * Delete a document record for an Idno entity.
     *
     * @param \Idno\Common\Entity $entity The content entity
     * @return array Response data
     */
    public function deleteDocument(\Idno\Common\Entity $entity): array
    {
        $rkey = $this->entityToRkey($entity);

        return $this->xrpcPost('com.atproto.repo.deleteRecord', [
            'repo'       => $this->session['did'],
            'collection' => 'site.standard.document',
            'rkey'       => $rkey,
        ]);
    }

    /**
     * Check if a document record exists for an entity.
     */
    public function documentExists(\Idno\Common\Entity $entity): bool
    {
        $rkey = $this->entityToRkey($entity);

        try {
            $this->xrpcGet('com.atproto.repo.getRecord', [
                'repo'       => $this->session['did'],
                'collection' => 'site.standard.document',
                'rkey'       => $rkey,
            ]);
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * Derive a stable rkey from an entity ID.
     * AT Protocol rkeys must match [a-zA-Z0-9._:~-]{1,512}
     */
    public function entityToRkey(\Idno\Common\Entity $entity): string
    {
        $id = $entity->getID();
        // The MongoDB ObjectID is already alphanumeric, just use it directly
        return (string) $id;
    }

    // ------------------------------------------------------------------
    // JWT / Crypto helpers
    // ------------------------------------------------------------------

    /**
     * Sign a JWT with ES256.
     */
    private static function signJwt(array $header, array $payload, string $privateKeyPem): string
    {
        $headerB64 = self::base64url(json_encode($header));
        $payloadB64 = self::base64url(json_encode($payload));

        $signingInput = $headerB64 . '.' . $payloadB64;

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if (!$privateKey) {
            throw new \Idno\Exceptions\TechnicalException('Invalid private key: ' . openssl_error_string());
        }

        $success = openssl_sign($signingInput, $derSignature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$success) {
            throw new \Idno\Exceptions\TechnicalException('JWT signing failed: ' . openssl_error_string());
        }

        // Convert DER signature to the raw R||S format required by JWS
        $signature = self::derToRaw($derSignature, 32);

        return $signingInput . '.' . self::base64url($signature);
    }

    /**
     * Convert a DER-encoded ECDSA signature to raw R||S format.
     */
    private static function derToRaw(string $der, int $length): string
    {
        $hex = unpack('H*', $der)[1];
        // DER: 30 <len> 02 <len_r> <r> 02 <len_s> <s>
        $pos = 6; // skip 30 <len> 02 (SEQUENCE tag, length, INTEGER tag)

        // R
        $rLen = hexdec(substr($hex, $pos, 2)) * 2;
        $pos += 2;
        $r = substr($hex, $pos, $rLen);
        $pos += $rLen;

        // S
        $pos += 2; // skip 02 (INTEGER tag)
        $sLen = hexdec(substr($hex, $pos, 2)) * 2;
        $pos += 2;
        $s = substr($hex, $pos, $sLen);

        // Pad/trim to expected length
        $r = str_pad(ltrim($r, '0'), $length * 2, '0', STR_PAD_LEFT);
        $s = str_pad(ltrim($s, '0'), $length * 2, '0', STR_PAD_LEFT);

        // Ensure exact length (trim leading zeros if longer)
        $r = substr($r, -$length * 2);
        $s = substr($s, -$length * 2);

        return pack('H*', $r . $s);
    }

    /**
     * Base64url encode (no padding).
     */
    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ------------------------------------------------------------------
    // HTTP helpers (raw curl to avoid Idno's multipart default)
    // ------------------------------------------------------------------

    /**
     * Extract the DPoP-Nonce header from a response.
     */
    private static function extractDPoPNonce(array $response): ?string
    {
        if (!empty($response['headers'])) {
            foreach ($response['headers'] as $header) {
                if (stripos($header, 'dpop-nonce:') === 0) {
                    return trim(substr($header, strlen('dpop-nonce:')));
                }
            }
        }
        return null;
    }

    /**
     * Perform an HTTP GET request.
     *
     * @return array{status: int, body: string, headers: array}
     */
    private static function httpGet(string $url, array $extraHeaders = []): ?array
    {
        if (self::$transport) {
            return call_user_func(self::$transport, 'GET', $url, null, $extraHeaders);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Known-StandardSiteSync/0.1');
        curl_setopt($ch, CURLOPT_HEADER, true);

        if (!empty($extraHeaders)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $extraHeaders);
        }

        // Proxy support from Idno config
        if (!empty(\Idno\Core\Idno::site()->config()->proxy_string)) {
            curl_setopt($ch, CURLOPT_PROXY, \Idno\Core\Idno::site()->config()->proxy_string);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            curl_close($ch);
            return null;
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerStr = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        curl_close($ch);

        $headers = array_filter(explode("\r\n", $headerStr), function ($h) {
            return strpos($h, ':') !== false;
        });

        return ['status' => $status, 'body' => $body, 'headers' => array_values($headers)];
    }

    /**
     * Perform an HTTP POST request.
     *
     * @return array{status: int, body: string, headers: array}
     */
    private static function httpPost(string $url, string $body, array $headers = []): array
    {
        if (self::$transport) {
            return call_user_func(self::$transport, 'POST', $url, $body, $headers);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Known-StandardSiteSync/0.1');
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // Proxy support
        if (!empty(\Idno\Core\Idno::site()->config()->proxy_string)) {
            curl_setopt($ch, CURLOPT_PROXY, \Idno\Core\Idno::site()->config()->proxy_string);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Idno\Exceptions\TechnicalException('HTTP POST failed: ' . $error);
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerStr = substr($response, 0, $headerSize);
        $bodyContent = substr($response, $headerSize);
        curl_close($ch);

        $responseHeaders = array_filter(explode("\r\n", $headerStr), function ($h) {
            return strpos($h, ':') !== false;
        });

        return ['status' => $status, 'body' => $bodyContent, 'headers' => array_values($responseHeaders)];
    }
}
