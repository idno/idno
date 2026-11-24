<?php

declare(strict_types=1);

namespace Idno\Security;

use Symfony\Component\Security\Csrf\TokenGenerator\TokenGeneratorInterface;

/**
 * Makes the session's form token: 32 random bytes as 64 lower-case hex characters, the shape Idno's form tokens have
 * always had (Symfony's own generator makes a URI-safe string of another length and alphabet).
 */
final class HexTokenGenerator implements TokenGeneratorInterface
{
    public function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
