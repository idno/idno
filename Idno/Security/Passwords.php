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
 * password does not verify that way, so such a typed password is also tried with
 * LegacyBcryptPasswordHasher, which is plain password_verify(); a hash it alone verified is kept as it is
 * (see verifyAndRehash()). Passwords are trimmed before they are hashed or
 * checked (the forms trim them too). Needs nothing of the legacy application.
 */
final class Passwords
{
    /**
     * The bcrypt cost PHP's password_hash() has always used here; Symfony's own default (13) is not used, so
     * that a site's existing hashes are not all rehashed for a cost change nobody asked for.
     */
    public const COST = 12;

    /** How many bytes of a password a bcrypt hash made by password_hash() covers. */
    private const BCRYPT_BYTES = 72;

    /**
     * A bcrypt hash (cost COST) of a password nobody has, for spendVerification().
     */
    private const NOBODY = '$2y$12$lxzVop/8EkaXiM5ddKyyv.ZZPo9rUXU1xQ82nuwv7QMMLUAImK0TS';

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
     * when it is fine as it is, and a new hash (to be stored) when the hash was made at another cost or by
     * another algorithm and the typed password covers all of what the old hash checked.
     *
     * A hash made by PHP's password_hash() from a password of more than 72 bytes, or holding a NUL byte, only ever
     * covered the first 72 bytes (or the part before the NUL). When ONLY the legacy check verifies such a typed
     * password, the rest of what was typed is unverified, so the login succeeds and the stored hash is returned
     * unchanged, now and on every later login: a mistyped tail never becomes the password. Such a hash stays
     * until the owner sets the password again.
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
        if (strlen($plain) <= self::BCRYPT_BYTES && !str_contains($plain, "\0")) {
            // The native hasher has just run password_verify() on exactly this password: the legacy check
            // would run the same bcrypt again, for the same answer.
            return null;
        }

        return $this->legacy->verify($hash, $plain) ? $hash : null;
    }

    /**
     * Does the work of checking a password (one bcrypt) against nobody's hash and says nothing: a login for a name that
     * no member has calls it, so that it costs what a login for a member with a wrong password costs and the time it
     * takes does not tell the two apart. An empty password costs nothing, as it costs nothing for a member.
     */
    public function spendVerification(#[\SensitiveParameter] string $plain): void
    {
        $plain = trim($plain);
        if ($plain !== '') {
            $this->hasher->verify(self::NOBODY, $plain);
        }
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
