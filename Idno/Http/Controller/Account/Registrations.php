<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Account;

use Idno\Entities\User;

/**
 * What the registration page asks the site: whether there are accounts, who is signed in, the registration setting, invitations, the rules a new account
 * has to meet, making the account, and the events and messages of the page. `Idno\Legacy\LegacyRegistrations` answers over the legacy application; the
 * service that serves the page (`RegistrationPages`) never names it.
 */
interface Registrations
{
    /**
     * Whether the site has any account. A site with none registers nobody: its first administrator is made by the installation.
     */
    public function siteHasAccounts(): bool;

    /**
     * What the member signed in to this request is called, null when nobody is.
     */
    public function signedInName(): ?string;

    /**
     * Whether the site is open to registration (the setting).
     */
    public function isOpen(): bool;

    /**
     * Whether the site can take another account (its user limit: the event `users/add/check`).
     */
    public function canAddUsers(): bool;

    /**
     * The invitation an address and a code name: set only when it can be used. A wrong code is always `invalid`, so only whoever holds the right code
     * learns anything about an address.
     */
    public function checkInvitation(string $email, string $code): RegistrationAdmission;

    public function emailIsBlocked(string $email): bool;

    public function accountExistsForEmail(string $email): bool;

    /**
     * Whether a handle is taken: an account has it, or it is kept back for the account that had it (an account that was deleted).
     */
    public function handleIsTaken(string $handle): bool;

    public function passwordIsStrong(#[\SensitiveParameter] string $password): bool;

    /**
     * The event `user/register/validate`: a listener (a spam filter) may refuse the registration.
     *
     * @param array<string, string> $fields name, handle, email, password, password2
     */
    public function validates(array $fields): bool;

    /**
     * Makes the account, in ONE step of the database: the invitation (if there is one) is used first, so of two registrations with one link only one gets
     * that far, then the account is built (it is an administrator only when the invitation as it is STORED says so, and its sender is still an
     * administrator), the event `site/newuser` is triggered and the account saved; if it cannot be saved the use of the invitation is undone with it. Never
     * an administrator without an invitation.
     *
     * @param string $handle the handle, lower case and trimmed
     */
    public function create(string $email, string $handle, #[\SensitiveParameter] string $password, string $name, ?RegistrationInvitation $invitation): NewAccount;

    /**
     * Signs the new member on: a new session id and a new form token.
     */
    public function logOn(User $user): void;

    /**
     * A message the next page the browser loads shows, once.
     */
    public function message(string $text, string $type = 'alert-info'): void;

    /**
     * The same, put before the messages already waiting.
     */
    public function messageAtStart(string $text, string $type = 'alert-info'): void;

    /**
     * The site's configured address (where a site with no account sends the visitor: the home page, which says it is being set up).
     */
    public function siteUrl(): string;

    /**
     * The site's address as the page is shown (where a member is sent, and where a new member lands).
     */
    public function displayUrl(): string;
}
