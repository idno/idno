<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Security\Passwords;
use PHPUnit\Framework\TestCase;

final class PasswordsTest extends TestCase
{
    private function passwords(): Passwords
    {
        return new Passwords(4);
    }

    public function testTrims(): void
    {
        $passwords = $this->passwords();
        $hash = $passwords->hash("  secret-words \n");

        $this->assertTrue($passwords->verify($hash, 'secret-words'));
        $this->assertTrue($passwords->verify($hash, "\tsecret-words  "));
        $this->assertTrue(password_verify('secret-words', $hash), 'the stored hash is of the trimmed password');
    }

    public function testEmptyIsRefused(): void
    {
        $passwords = $this->passwords();
        foreach (['', '   ', "\n"] as $empty) {
            try {
                $passwords->hash($empty);
                $this->fail('an empty password is refused');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
            $this->assertFalse($passwords->verify($passwords->hash('x'), $empty));
        }
    }

    public function testAnOverlongPasswordIsRefusedAsAnInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->passwords()->hash(str_repeat('a', 4097));
    }

    public function testANewHashOfALongPasswordDistinguishesByte73(): void
    {
        $passwords = $this->passwords();
        $hash = $passwords->hash(str_repeat('a', 72) . 'x');

        $this->assertTrue($passwords->verify($hash, str_repeat('a', 72) . 'x'));
        $this->assertFalse($passwords->verify($hash, str_repeat('a', 72) . 'y'));
        $this->assertFalse($passwords->verify($hash, str_repeat('a', 72)));
        $this->assertSame($hash, $passwords->verifyAndRehash($hash, str_repeat('a', 72) . 'x'), 'a hash it made is fine as it is');
    }

    public function testANewHashOfANulPasswordCountsEveryByte(): void
    {
        $passwords = $this->passwords();
        $hash = $passwords->hash("a\0b");

        $this->assertTrue($passwords->verify($hash, "a\0b"));
        $this->assertFalse($passwords->verify($hash, "a\0c"));
        $this->assertFalse($passwords->verify($hash, 'a'));
    }

    public function testALegacyHashNeedsRehash(): void
    {
        $passwords = $this->passwords();
        $cost10 = password_hash('plain-one', PASSWORD_BCRYPT, ['cost' => 10]);

        $this->assertTrue($passwords->needsRehash($cost10), 'a hash of another cost');
        $upgraded = $passwords->verifyAndRehash($cost10, 'plain-one');
        $this->assertNotNull($upgraded);
        $this->assertNotSame($cost10, $upgraded);
        $this->assertSame($upgraded, $passwords->verifyAndRehash($upgraded, 'plain-one'), 'once is enough');
        $this->assertNull($passwords->verifyAndRehash($cost10, 'plain-two'));

        $long = str_repeat('a', 100);
        $legacyLong = password_hash($long, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertFalse($passwords->needsRehash($legacyLong), 'the hash alone does not tell: it is bcrypt of the right cost');
        $replacement = $passwords->verifyAndRehash($legacyLong, $long);
        $this->assertNotNull($replacement);
        $this->assertNotSame($legacyLong, $replacement, 'a hash only the legacy hasher verified is replaced');
        $this->assertFalse($passwords->verify($replacement, str_repeat('a', 72) . 'z'));
    }

    public function testAWrongOrMalformedHashVerifiesNothing(): void
    {
        $passwords = $this->passwords();
        $this->assertNull($passwords->verifyAndRehash('', 'x'));
        $this->assertNull($passwords->verifyAndRehash('not a hash', 'x'));
    }
}
