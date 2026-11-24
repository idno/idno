<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\LogsIn;
use Tests\Support\PrivateSiteTestCase;

/**
 * Hashes that PHP's password_hash() made before Idno used Symfony's password hasher keep working for the
 * member's login, are replaced by a new hash at the first successful login, and are never replaced twice. The hash of a password longer than 72 bytes or holding a NUL byte is never replaced (it covered only part of the password).
 * The legacy hashes are made of the raw password, as `User::setPassword()` made them, with bcrypt's own cost 10.
 */
final class PasswordCompatibilityTest extends PrivateSiteTestCase
{
    use LogsIn;

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}> the password as typed, the hash PHP made
     *         before (of the trimmed password), whether only the legacy hasher can verify it
     */
    public static function passwords(): array
    {
        $cost10 = static fn (string $p): string => password_hash($p, PASSWORD_BCRYPT, ['cost' => 10]);
        $nulHash = crypt('mid', '$2y$10$' . substr(strtr(base64_encode(random_bytes(16)), '+', '.'), 0, 22));
        $long = str_repeat('0123456789', 10);

        return [
            'ordinary' => ['correct-horse-battery-9', $cost10('correct-horse-battery-9'), false],
            'a hundred bytes' => [$long, $cost10($long), true],
            'a NUL byte in the middle' => ["mid\0dle", $nulHash, true],
            'non-ASCII' => ['pässwörd-日本語-9', $cost10('pässwörd-日本語-9'), false],
            'typed with surrounding spaces' => ['  spaced out words  ', $cost10('spaced out words'), false],
        ];
    }

    /**
     * @dataProvider passwords
     */
    public function testALegacyHashLogsInOverHttpIsUpgradedOnceAndLogsInAgain(string $typed, string $legacyHash, bool $onlyLegacyVerifies): void
    {
        $this->addUser('ada', 'placeholder-words-1');
        $this->runInSite('$u = \Idno\Entities\User::getByHandle("ada"); $u->setPasswordHash(' . $this->literal($legacyHash) . '); $u->save(true); return true;');

        // Before the upgrade: the member logs in with the password as typed.
        $this->loginCookie('ada', $typed);
        $after = $this->storedHash();
        $this->loginCookie('ada', $typed);
        $this->assertSame($after, $this->storedHash(), 'the hash is replaced at most once');

        if ($onlyLegacyVerifies) {
            // A hash that only covered 72 bytes (or the part before a NUL) is never replaced: what was typed after it
            // was never checked, so it must not become the password.
            $this->assertSame($legacyHash, $after, 'the old hash of such a password stays');
            $cut = strpos($typed, "\0") !== false ? substr($typed, 0, (int) strpos($typed, "\0") + 1) : substr($typed, 0, 72);
            $this->loginCookie('ada', $cut . 'other tail');
            $this->assertSame($legacyHash, $this->storedHash(), 'a mistyped tail is accepted as before and stores nothing');
            $this->loginCookie('ada', $typed);
            $this->assertSame($legacyHash, $this->storedHash(), 'the real password still works');
        } else {
            $this->assertNotSame($legacyHash, $after, 'the legacy hash is replaced at the first login');
            $info = password_get_info($after);
            $this->assertSame('2y', $info['algo']);
            $this->assertSame(12, $info['options']['cost'], 'at the cost PHP always used here');
        }
    }

    public function testTheUserApiKeepsItsReturnValuesAndAWrongPasswordChangesNothing(): void
    {
        $this->addUser('ada', 'correct-horse-battery-9');
        $result = $this->runInSite(
            '$u = \Idno\Entities\User::getByHandle("ada"); $before = $u->password;
            $r = [
                "right" => $u->checkPassword("correct-horse-battery-9"),
                "padded" => $u->checkPassword("  correct-horse-battery-9 "),
                "wrong" => $u->checkPassword("not-it"),
                "empty" => $u->checkPassword(""),
                "blank" => $u->checkPassword("   "),
                "unchanged" => $u->password === $before,
                "setEmpty" => $u->setPassword(""),
                "setBlank" => $u->setPassword("   "),
                "setNull" => $u->setPassword(null),
                "setOk" => $u->setPassword("another-long-words-4"),
                "checkNew" => $u->checkPassword("another-long-words-4"),
                "oldNow" => $u->checkPassword("correct-horse-battery-9"),
            ];
            return $r;'
        );

        $this->assertSame(
            ['right' => true, 'padded' => true, 'wrong' => false, 'empty' => false, 'blank' => false, 'unchanged' => true, 'setEmpty' => false, 'setBlank' => false, 'setNull' => false, 'setOk' => true, 'checkNew' => true, 'oldNow' => false],
            $result
        );
    }

    public function testAFailureToStoreTheUpgradedHashNeverFailsTheCheck(): void
    {
        $legacy = password_hash('correct-horse-battery-9', PASSWORD_BCRYPT, ['cost' => 10]);
        $result = $this->runInSite(
            '$u = new class extends \Idno\Entities\User { function save($overrideAccess = true) { throw new \RuntimeException("the database is gone"); } };
            $u->setPasswordHash(' . $this->literal($legacy) . ');
            return ["ok" => $u->checkPassword("correct-horse-battery-9"), "wrong" => $u->checkPassword("nope")];'
        );

        $this->assertSame(['ok' => true, 'wrong' => false], $result);
    }

    public function testASaveOfTheUpgradedHashAnnouncesNothingToFollowers(): void
    {
        // The ActivityPub and StandardSiteSync `updated` hooks ignore a User; the upgrade stores through save(true), as a
        // password change does, so it adds no kind of event beyond what that already fires.
        $this->addUser('ada', 'placeholder-words-1');
        $this->runInSite('$u = \Idno\Entities\User::getByHandle("ada"); $u->setPasswordHash(' . $this->literal(password_hash('x-words-12345', PASSWORD_BCRYPT, ['cost' => 10])) . '); $u->save(true); return true;');
        $count = $this->runInSite(
            '$n = 0; \Idno\Core\Idno::site()->events()->addListener("syndicate", function () use (&$n) { $n++; });
            \Idno\Core\Idno::site()->events()->addListener("webmention/send", function () use (&$n) { $n++; });
            \Idno\Entities\User::getByHandle("ada")->checkPassword("x-words-12345"); return $n;'
        );

        $this->assertSame(0, $count);
    }

    private function storedHash(): string
    {
        $hash = $this->runInSite('return \Idno\Entities\User::getByHandle("ada")->password;');
        $this->assertIsString($hash);

        return $hash;
    }
}
