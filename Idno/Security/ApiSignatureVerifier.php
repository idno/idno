<?php

declare(strict_types=1);

namespace Idno\Security;

/**
 * Checks the signature of a signed API request: the base64 of the HMAC-SHA256 of the request address (path and
 * query, as sent) made with the member's API key, compared in constant time. Pure.
 */
final class ApiSignatureVerifier
{
    public function verify(string $requestUri, #[\SensitiveParameter] string $apiKey, #[\SensitiveParameter] string $signature): bool
    {
        // A member with no key has no way to sign: an HMAC made with an empty key is one anybody can make.
        if ($apiKey === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $requestUri, $apiKey, true));

        return hash_equals($expected, trim($signature));
    }
}
