<?php

namespace IdnoPlugins\ActivityPub;

/**
 * HTTP Signature utilities for ActivityPub federation.
 * Implements draft-cavage-http-signatures for request signing and verification.
 */
class HTTPSignature
{

    /**
     * Sign an outbound HTTP request for ActivityPub delivery.
     *
     * @param string $privateKey PEM-encoded private key
     * @param string $keyId      Public key ID (e.g., https://example.com/actor/user#main-key)
     * @param string $url        Target inbox URL
     * @param string $body       JSON-encoded request body
     * @param string $method     HTTP method (default: POST)
     * @return array Headers array ready for Webservice::post()
     */
    public static function sign(string $privateKey, string $keyId, string $url, string $body, string $method = 'POST'): array
    {
        $parsed = parse_url($url);
        $host = $parsed['host'];
        if (!empty($parsed['port']) && $parsed['port'] != 443 && $parsed['port'] != 80) {
            $host .= ':' . $parsed['port'];
        }
        $path = $parsed['path'] ?? '/';
        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        $date = gmdate('D, d M Y H:i:s T');
        $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
        $contentType = 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

        $signingHeaders = [
            '(request-target)' => strtolower($method) . ' ' . $path,
            'host'             => $host,
            'date'             => $date,
            'digest'           => $digest,
            'content-type'     => $contentType,
        ];

        $signingString = '';
        $headerNames = [];
        foreach ($signingHeaders as $name => $value) {
            if (!empty($signingString)) {
                $signingString .= "\n";
            }
            $signingString .= $name . ': ' . $value;
            $headerNames[] = $name;
        }

        $signature = '';
        $success = openssl_sign($signingString, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$success) {
            \Idno\Core\Idno::site()->logging()->error('ActivityPub: Failed to sign request to ' . $url);
            return [];
        }

        $signatureB64 = base64_encode($signature);
        $signatureHeader = sprintf(
            'keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $keyId,
            implode(' ', $headerNames),
            $signatureB64
        );

        return [
            'Date: ' . $date,
            'Digest: ' . $digest,
            'Content-Type: ' . $contentType,
            'Signature: ' . $signatureHeader,
            'Accept: application/ld+json; profile="https://www.w3.org/ns/activitystreams"',
        ];
    }

    /**
     * Sign a GET request (used for fetching remote actors).
     *
     * @param string $privateKey PEM-encoded private key
     * @param string $keyId      Public key ID
     * @param string $url        Target URL
     * @return array Headers array
     */
    public static function signGet(string $privateKey, string $keyId, string $url): array
    {
        $parsed = parse_url($url);
        $host = $parsed['host'];
        if (!empty($parsed['port']) && $parsed['port'] != 443 && $parsed['port'] != 80) {
            $host .= ':' . $parsed['port'];
        }
        $path = $parsed['path'] ?? '/';
        if (!empty($parsed['query'])) {
            $path .= '?' . $parsed['query'];
        }

        $date = gmdate('D, d M Y H:i:s T');

        $signingHeaders = [
            '(request-target)' => 'get ' . $path,
            'host'             => $host,
            'date'             => $date,
        ];

        $signingString = '';
        $headerNames = [];
        foreach ($signingHeaders as $name => $value) {
            if (!empty($signingString)) {
                $signingString .= "\n";
            }
            $signingString .= $name . ': ' . $value;
            $headerNames[] = $name;
        }

        $signature = '';
        openssl_sign($signingString, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        $signatureB64 = base64_encode($signature);
        $signatureHeader = sprintf(
            'keyId="%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $keyId,
            implode(' ', $headerNames),
            $signatureB64
        );

        return [
            'Date: ' . $date,
            'Signature: ' . $signatureHeader,
            'Accept: application/activity+json',
        ];
    }

    /**
     * How old the Date of a signed request may be, in seconds. Twelve hours is what
     * Mastodon allows, so a delivery that Mastodon would accept is not refused here.
     * A server may sign a delivery once and send the same request again when it retries.
     */
    const MAX_AGE = 43200;

    /**
     * How far ahead of this server's clock the Date may be, in seconds: an allowance for
     * a sender whose clock is fast, again the same as Mastodon's.
     */
    const MAX_CLOCK_SKEW = 3600;

    /**
     * Verify an incoming HTTP signature.
     *
     * A signature proves only the headers it covers, so it must cover the method and
     * path, the host and the date, and for a request with a body (anything but GET and
     * HEAD) the digest of that body. The Date must be recent and the Digest must be the
     * body's. A request that falls short of any of this is refused, whether or not the
     * signature over the rest is valid.
     *
     * @param string $publicKeyPem PEM-encoded public key of the remote actor
     * @param array  $headers      Request headers (lowercase keys)
     * @param string $body         Raw request body
     * @param string $method       HTTP method
     * @param string $path         Request path (including query string)
     * @return bool True if signature is valid
     */
    public static function verify(string $publicKeyPem, array $headers, string $body, string $method, string $path): bool
    {
        $logging = \Idno\Core\Idno::site()->logging();

        // Parse the Signature header
        if (empty($headers['signature']) || !is_string($headers['signature'])) {
            $logging->warning('ActivityPub: No Signature header');
            return false;
        }

        $sigParams = self::parseSignatureHeader($headers['signature']);
        if (!$sigParams) {
            return false;
        }

        // The headers the signature covers
        $signedHeaders = preg_split('/\s+/', strtolower(trim($sigParams['headers'])), -1, PREG_SPLIT_NO_EMPTY);

        $required = ['(request-target)', 'host', 'date'];
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            $required[] = 'digest';
        }
        $missing = array_diff($required, $signedHeaders);
        if (!empty($missing)) {
            $logging->warning('ActivityPub: Signature does not cover ' . implode(', ', $missing));
            return false;
        }

        // Each of them must have been sent, too: a header that is absent would be signed as an empty value
        foreach ($required as $name) {
            if ($name !== '(request-target)' && (empty($headers[$name]) || !is_string($headers[$name]))) {
                $logging->warning('ActivityPub: Signed request has no ' . $name . ' header');
                return false;
            }
        }

        // The Date must be recent
        $requestTime = strtotime($headers['date']);
        $now = time();
        if ($requestTime === false || $requestTime < $now - self::MAX_AGE || $requestTime > $now + self::MAX_CLOCK_SKEW) {
            $logging->warning('ActivityPub: Signature date out of range');
            return false;
        }

        // The Digest must be the body's
        if (in_array('digest', $required, true) && !self::digestMatchesBody($headers['digest'], $body)) {
            $logging->warning('ActivityPub: Digest mismatch');
            return false;
        }

        // Reconstruct the signing string
        $lines = [];
        foreach ($signedHeaders as $headerName) {
            if ($headerName === '(request-target)') {
                $lines[] = '(request-target): ' . strtolower($method) . ' ' . $path;
            } else {
                $headerValue = $headers[$headerName] ?? '';
                $lines[] = $headerName . ': ' . (is_string($headerValue) ? $headerValue : '');
            }
        }
        $signingString = implode("\n", $lines);

        $decodedSignature = base64_decode($sigParams['signature']);
        if ($decodedSignature === false) {
            return false;
        }

        $result = openssl_verify($signingString, $decodedSignature, $publicKeyPem, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    /**
     * Whether a Digest header (RFC 3230) is the digest of the body. The header may list
     * several digests, separated by commas, and algorithm names are not case-sensitive.
     * At least one must be SHA-256 or SHA-512, and every one of those must match.
     *
     * @param string $header The Digest header value
     * @param string $body   Raw request body
     * @return bool
     */
    public static function digestMatchesBody(string $header, string $body): bool
    {
        $algorithms = ['sha-256' => 'sha256', 'sha-512' => 'sha512'];
        $matched = false;

        foreach (explode(',', $header) as $entry) {
            $parts = explode('=', trim($entry), 2);
            if (count($parts) !== 2) {
                continue;
            }
            $algorithm = strtolower(trim($parts[0]));
            if (!isset($algorithms[$algorithm])) {
                continue;
            }
            $expected = base64_encode(hash($algorithms[$algorithm], $body, true));
            if (!hash_equals($expected, trim($parts[1]))) {
                return false;
            }
            $matched = true;
        }

        return $matched;
    }

    /**
     * Parse the Signature header into its components.
     *
     * @param string $header The Signature header value
     * @return array|false Parsed components or false on failure
     */
    public static function parseSignatureHeader(string $header)
    {
        $params = [];
        // Match key="value" pairs
        if (preg_match_all('/(\w+)="([^"]*)"/', $header, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $params[$match[1]] = $match[2];
            }
        }

        if (empty($params['keyId']) || empty($params['signature']) || empty($params['headers'])) {
            \Idno\Core\Idno::site()->logging()->warning('ActivityPub: Incomplete Signature header');
            return false;
        }

        return $params;
    }

    /**
     * Extract request headers into a normalized associative array.
     *
     * @return array Headers with lowercase keys
     */
    public static function getRequestHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
        } else {
            // Fallback for servers that don't support getallheaders()
            foreach ($_SERVER as $key => $value) {
                if (substr($key, 0, 5) === 'HTTP_') {
                    $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                    $headers[$headerName] = $value;
                }
            }
            if (isset($_SERVER['CONTENT_TYPE'])) {
                $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
            }
            if (isset($_SERVER['CONTENT_LENGTH'])) {
                $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
            }
        }

        return $headers;
    }
}
