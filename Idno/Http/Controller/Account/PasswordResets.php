<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Account;

use Idno\Entities\User;

/**
 * What the password-reset pages ask the site: who is signed in, which account an address may reset, the code that proves the address is the member's,
 * the mail that carries it, and changing the password. `Idno\Legacy\LegacyPasswordResets` answers over the legacy session, finders and mailer; the service
 * that serves the pages (`PasswordResetPages`) never names it.
 */
interface PasswordResets
{
    /**
     * Whether a member is signed in to this request: the pages are for visitors.
     */
    public function isSignedIn(): bool;

    /**
     * The account an address asks a reset link for, null when none may be sent one: no member has the address, the account is being deleted, or the site
     * has blocked the address. The page answers every address alike, so the caller never says which.
     */
    public function accountForRequest(string $email): ?User;

    /**
     * Gives the account a new recovery code and stores it (only its hash is kept); the code is what is mailed. Null when none could be made.
     */
    public function issueCode(User $account): ?string;

    /**
     * Mails the code to the account, in a link to the reset page that carries the address as it was typed.
     */
    public function mailCode(User $account, string $email, #[\SensitiveParameter] string $code): void;

    /**
     * The member whose address this is, when the code is their current one (less than three hours old, not used) and they may reset: an account that is
     * being deleted, and an address the site has blocked, cannot. A wrong, a used and an expired code are the same answer.
     */
    public function memberFor(string $email, #[\SensitiveParameter] string $code): ?User;

    /**
     * What identifies the account to the sign-in throttle.
     */
    public function accountId(User $account): string;

    public function passwordIsStrong(#[\SensitiveParameter] string $password): bool;

    /**
     * Sets the new password, which ends the account's other sessions, uses the recovery code up and stores the account.
     */
    public function changePassword(User $account, #[\SensitiveParameter] string $password): void;

    /**
     * Whether the site can send mail (the request page warns a visitor when it cannot).
     */
    public function mailIsConfigured(): bool;

    /**
     * A message the next page the browser loads shows, once.
     */
    public function message(string $text, string $type = 'alert-info'): void;

    /**
     * The site's address as the page is shown (where the visitor is sent next).
     */
    public function displayUrl(): string;
}
