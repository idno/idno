<?php

declare(strict_types=1);

namespace Idno\Security;

use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Verifies a password hash as PHP's password_hash() made it from the password itself: bcrypt reads at most 72
 * bytes and stops at a NUL byte, and every hash Idno made before it used Symfony's hasher is of that kind.
 * Symfony's own native hasher pre-hashes such a password, so a hash made without it would no longer verify.
 * It only verifies: a hash is never made this way again (hash() throws). Passwords decides what a verification
 * by it means: a hash of a password over 72 bytes or holding a NUL byte is never replaced, because the hash
 * covered only part of what was typed.
 */
final class LegacyBcryptPasswordHasher implements PasswordHasherInterface
{
    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        throw new \LogicException('The legacy bcrypt hasher only verifies hashes; it never makes one.');
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
    {
        if ($plainPassword === '' || $hashedPassword === '') {
            return false;
        }

        return password_verify($plainPassword, $hashedPassword);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return true;
    }
}
