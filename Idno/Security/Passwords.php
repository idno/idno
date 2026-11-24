<?php

declare(strict_types=1);

namespace Idno\Security;

use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Makes and checks the hashes of members' passwords, on symfony/password-hasher.
 *
 * New hashes are bcrypt by Symfony's native hasher (which pre-hashes a password of more than 72 bytes or holding
 * a NUL byte, so that every byte of it counts). A hash made earlier by PHP's password_hash() from such a
 * password does not verify that way, so a hash the native hasher refuses is tried with
 * LegacyBcryptPasswordHasher, which is plain password_verify(). Passwords are trimmed before they are hashed or
 * checked (the forms trim them too). Needs nothing of the legacy application.
 */
final class Passwords
{
    /**
     * The bcrypt cost PHP's password_hash() has always used here; Symfony's own default (13) is not used, so
     * that a site's existing hashes are not all rehashed for a cost change nobody asked for.
     */
    public const COST = 12;

    private readonly PasswordHasherInterface $hasher;
    private readonly PasswordHasherInterface $legacy;

    public function __construct(int $cost = self::COST)
    {
        $this->hasher = new NativePasswordHasher(cost: $cost);
        $this->legacy = new LegacyBcryptPasswordHasher();
    }

    /**
     * The hash of a password.
     *
     * @throws \InvalidArgumentException for an empty password, or one longer than the hasher accepts (4096 bytes)
     */
    public function hash(#[\SensitiveParameter] string $plain): string
    {
        $plain = trim($plain);
        if ($plain === '') {
            throw new \InvalidArgumentException('A password cannot be empty.');
        }
        try {
            return $this->hasher->hash($plain);
        } catch (InvalidPasswordException $e) {
            throw new \InvalidArgumentException('The password is too long.', 0, $e);
        }
    }

    /**
     * Whether the password is the one the hash was made from; false for an empty password.
     */
    public function verify(string $hash, #[\SensitiveParameter] string $plain): bool
    {
        return $this->verifyAndRehash($hash, $plain) !== null;
    }

    /**
     * Checks a password and says whether its hash is to be replaced: null for a wrong password, the same hash
     * when it is fine as it is, and a new hash (to be stored) when only the legacy hasher could verify it (a
     * password PHP truncated, so that the hash does not tell byte 73 on) or the hash was made at another cost.
     */
    public function verifyAndRehash(string $hash, #[\SensitiveParameter] string $plain): ?string
    {
        $plain = trim($plain);
        if ($plain === '' || $hash === '') {
            return null;
        }
        if ($this->hasher->verify($hash, $plain)) {
            return $this->hasher->needsRehash($hash) ? $this->hasher->hash($plain) : $hash;
        }
        if ($this->legacy->verify($hash, $plain)) {
            try {
                return $this->hasher->hash($plain);
            } catch (InvalidPasswordException) {
                // Too long for the hasher to make a new hash of: the password is right, and the old hash stays.
                return $hash;
            }
        }

        return null;
    }

    /**
     * Whether the hash differs from what a new one would be (its cost or algorithm); a hash that only the legacy
     * hasher verifies cannot be told from the hash alone: verifyAndRehash() says it.
     */
    public function needsRehash(string $hash): bool
    {
        return $this->hasher->needsRehash($hash);
    }
}
