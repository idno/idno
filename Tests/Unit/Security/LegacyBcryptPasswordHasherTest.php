<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Security\LegacyBcryptPasswordHasher;
use PHPUnit\Framework\TestCase;

final class LegacyBcryptPasswordHasherTest extends TestCase
{
    private function phpHash(string $plain): string
    {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    public function testVerifiesCost10(): void
    {
        $hasher = new LegacyBcryptPasswordHasher();
        $hash = $this->phpHash('correct horse');

        $this->assertTrue($hasher->verify($hash, 'correct horse'));
        $this->assertFalse($hasher->verify($hash, 'wrong horse'));
        $this->assertFalse($hasher->verify($hash, ''));
    }

    public function testVerifiesA100BytePasswordAsPhpTruncatedIt(): void
    {
        $hasher = new LegacyBcryptPasswordHasher();
        $password = str_repeat('a', 72) . str_repeat('b', 28);
        $hash = $this->phpHash($password);

        $this->assertTrue($hasher->verify($hash, $password));
        $this->assertTrue($hasher->verify($hash, str_repeat('a', 72) . 'different tail'), 'bcrypt reads 72 bytes: as PHP always did');
        $this->assertFalse($hasher->verify($hash, str_repeat('a', 71)));
    }

    public function testVerifiesANulBytePasswordAsPhpDid(): void
    {
        $hasher = new LegacyBcryptPasswordHasher();
        // PHP before 8.4 hashed such a password up to its NUL byte; password_hash() now refuses it, crypt() still
        // makes the same hash of the part before it.
        $hash = crypt('before', '$2y$10$' . substr(strtr(base64_encode(random_bytes(16)), '+', '.'), 0, 22));

        $this->assertTrue($hasher->verify($hash, "before\0after"));
        $this->assertTrue($hasher->verify($hash, "before\0other"), 'bcrypt stops at a NUL byte, as PHP does');
        $this->assertFalse($hasher->verify($hash, 'other'));
    }

    public function testHashThrows(): void
    {
        $this->expectException(\LogicException::class);
        (new LegacyBcryptPasswordHasher())->hash('anything');
    }

    public function testEveryHashNeedsReplacing(): void
    {
        $this->assertTrue((new LegacyBcryptPasswordHasher())->needsRehash($this->phpHash('x')));
    }
}
