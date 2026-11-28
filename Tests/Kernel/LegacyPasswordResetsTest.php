<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Tests\Support\PrivateSiteTestCase;

/**
 * What the password-reset pages ask the site (`Idno\Legacy\LegacyPasswordResets`), on a site of the test's own with an administrator, a member, a member
 * whose address is blocked and one whose account is being deleted, each method against the legacy expression it wraps.
 */
final class LegacyPasswordResetsTest extends PrivateSiteTestCase
{
    private const SEED = <<<'PHP'
    $site = \Idno\Core\Idno::site();
    $site->config()->blocked_emails = ['blocked@example.com'];
    $site->config()->from_email = 'site@example.com';
    $site->config()->smtp_host = 'mail.example.test';
    $site->config()->save();
    $make = static function (string $handle, string $email, bool $admin = false): \Idno\Entities\User {
        $u = new \Idno\Entities\User();
        $u->handle = $handle;
        $u->email = $email;
        $u->setTitle(ucfirst($handle));
        $u->setPassword('correct-horse-battery-9');
        $u->setAdmin($admin);
        $u->save(true);

        return $u;
    };
    $make('ada', 'ada@example.com', true);
    $make('bob', 'bob@example.com');
    $make('blocked', 'blocked@example.com');
    $closed = $make('closed', 'closed@example.com');
    $closed->close();
    $resets = \Idno\Core\Idno::container()->get(\Idno\Http\Controller\Account\PasswordResets::class);
    PHP;

    /**
     * Catches: an address that may not reset (blocked by the site, an account that is being deleted, no account, an address that is only a handle)
     * being given a code and a mail, or a member who may being refused.
     */
    public function testWhoAnAddressMayResetThePasswordOf(): void
    {
        $out = $this->runInSite(self::SEED . <<<'PHP'
        $found = static fn (?\Idno\Entities\User $u): ?string => $u?->getHandle();

        return [
            'bob' => $found($resets->accountForRequest('bob@example.com')),
            'upper' => $found($resets->accountForRequest('BOB@Example.com')),
            'padded' => $found($resets->accountForRequest(' bob@example.com ')),
            'blocked' => $found($resets->accountForRequest('blocked@example.com')),
            'closed' => $found($resets->accountForRequest('closed@example.com')),
            'nobody' => $found($resets->accountForRequest('nobody@example.com')),
            'handle' => $found($resets->accountForRequest('bob')),
            'empty' => $found($resets->accountForRequest('')),
            'signedIn' => $resets->isSignedIn(),
        ];
        PHP);

        $this->assertSame(
            ['bob' => 'bob', 'upper' => 'bob', 'padded' => null, 'blocked' => null, 'closed' => null, 'nobody' => null, 'handle' => null, 'empty' => null, 'signedIn' => false],
            $out
        );
    }

    /**
     * Catches: a code that does not match, one that is used up, and one older than three hours resetting a password; a right one being refused.
     */
    public function testACodeMustBeTheMembersCurrentOne(): void
    {
        $out = $this->runInSite(self::SEED . <<<'PHP'
        $bob = \Idno\Entities\User::getByEmail('bob@example.com');
        $code = $resets->issueCode($bob);
        $reload = static fn () => \Idno\Entities\User::getByEmail('bob@example.com');
        $out = [
            'right' => $resets->memberFor('bob@example.com', $code)?->getHandle(),
            'wrong' => $resets->memberFor('bob@example.com', str_repeat('f', 32)),
            'empty code' => $resets->memberFor('bob@example.com', ''),
            'empty email' => $resets->memberFor('', $code),
            "another's email" => $resets->memberFor('ada@example.com', $code),
            'stored hash only' => $reload()->password_recovery_code === $code,
        ];
        $bob = $reload();
        $bob->password_recovery_code_time = time() - 3 * 3600 - 5;
        $bob->save(true);
        $out['expired'] = $resets->memberFor('bob@example.com', $code);
        $bob = $reload();
        $bob->password_recovery_code_time = time();
        $bob->save(true);
        $bob = $reload();
        $bob->clearPasswordRecoveryCode();
        $bob->save(true);
        $out['used'] = $resets->memberFor('bob@example.com', $code);

        return $out;
        PHP);

        $this->assertSame(
            ['right' => 'bob', 'wrong' => null, 'empty code' => null, 'empty email' => null, "another's email" => null, 'stored hash only' => false, 'expired' => null, 'used' => null],
            $out
        );
    }

    /**
     * Catches: a member who was blocked, or whose account is being deleted, after asking for a link still resetting with it.
     */
    public function testABlockedOrClosedAccountsCodeIsRefused(): void
    {
        $out = $this->runInSite(self::SEED . <<<'PHP'
        $out = [];
        foreach (['blocked@example.com', 'closed@example.com'] as $email) {
            $u = \Idno\Entities\User::getByEmail($email);
            $code = $u->addPasswordRecoveryCode();
            $u->save(true);
            $out[$email] = $resets->memberFor($email, $code);
        }

        return $out;
        PHP);

        $this->assertSame(['blocked@example.com' => null, 'closed@example.com' => null], $out);
    }

    /**
     * Catches: the new password not being stored as the legacy page stored it (the old one still signing in, the code not used up, the other sessions of the
     * account not ended).
     */
    public function testChangingAPasswordUsesUpTheCodeAndTheNewOneSignsIn(): void
    {
        $out = $this->runInSite(self::SEED . <<<'PHP'
        $bob = \Idno\Entities\User::getByEmail('bob@example.com');
        $code = $resets->issueCode($bob);
        $before = \Idno\Entities\User::getByEmail('bob@example.com')->password_generation;
        $resets->changePassword($bob, 'a-new-passphrase-77');
        $after = \Idno\Entities\User::getByEmail('bob@example.com');

        return [
            'new' => $after->checkPassword('a-new-passphrase-77'),
            'old' => $after->checkPassword('correct-horse-battery-9'),
            'code' => (bool) $after->getPasswordRecoveryCode(),
            'generation changed' => $before !== $after->password_generation,
            'id' => $resets->accountId($after) === (string) $after->getUUID(),
            'strong' => [$resets->passwordIsStrong('abc1234'), $resets->passwordIsStrong('abc123'), $resets->passwordIsStrong('')],
        ];
        PHP);

        $this->assertSame(
            ['new' => true, 'old' => false, 'code' => false, 'generation changed' => true, 'id' => true, 'strong' => [true, false, false]],
            $out
        );
    }

    /**
     * Catches: the reset mail not being the one the page has always sent (to the member's own address, with the code in a link on the configured
     * address, in the email shell), or a failing mail raising out of the page.
     */
    public function testTheMailGoesToTheMemberWithTheLinkAndTheCode(): void
    {
        $out = $this->runInSite(self::SEED . <<<'PHP'
        $bob = \Idno\Entities\User::getByEmail('bob@example.com');
        $recorded = [];
        $mailer = new class ($recorded) implements \Symfony\Component\Mailer\MailerInterface {
            public function __construct(public array &$recorded)
            {
            }

            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                $this->recorded[] = $message;
            }
        };
        \Idno\Core\Email::useMailer($mailer);
        $resets->mailCode($bob, 'BOB@example.com', 'abc123');
        \Idno\Core\Email::useMailer(null);
        $m = $recorded[0] ?? null;

        return [
            'count' => count($recorded),
            'to' => $m ? array_map(static fn ($a) => $a->getAddress(), $m->getTo()) : null,
            'link' => $m ? str_contains((string) $m->getTextBody(), 'account/password/reset/?email=BOB%40example.com&code=abc123') : null,
            'configured' => $resets->mailIsConfigured(),
        ];
        PHP);

        $this->assertSame(['count' => 1, 'to' => ['bob@example.com'], 'link' => true, 'configured' => true], $out);
    }
}
