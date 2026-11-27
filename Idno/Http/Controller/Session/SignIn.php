<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Session;

use Idno\Entities\User;

/**
 * What the sign-in pages ask the site: who is signed in, who an account name is, whether a password is right, signing a member on and off, the events
 * and messages of the pages, and the addresses they send the browser to. `Idno\Legacy\LegacySignIn` answers over the legacy session; the service that
 * serves the pages (`SessionPages`) never names the legacy application.
 */
interface SignIn
{
    /**
     * The member signed in to this request (for a signed API request, the member it was signed by), null when nobody is.
     */
    public function currentUser(): ?User;

    /**
     * Whether a stateless credential (a signed request, a bearer token) authenticated this request.
     */
    public function isApiRequest(): bool;

    /**
     * The account a name is: the member whose handle it is, else the member whose email address it is, null when there is none.
     */
    public function accountFor(string $login): ?User;

    /**
     * Whether the password is the member's (a better hash than the stored one replaces it).
     */
    public function checkPassword(User $user, #[\SensitiveParameter] string $password): bool;

    /**
     * Signs the member on: a new session id and a new form token.
     */
    public function logOn(User $user): void;

    /**
     * Ends the session (its data, its user, its form token and its id) and starts a new, empty one.
     */
    public function logOff(): void;

    /**
     * The member's API key.
     */
    public function apiKey(User $user): string;

    /**
     * Triggers an event of the site (the sign-in events are the audit trail).
     *
     * @param array<string, mixed> $data
     */
    public function trigger(string $event, array $data): void;

    /**
     * A message the next page the browser loads shows, once.
     */
    public function message(string $text, string $type = 'alert-info'): void;

    /**
     * The template type the request asked for (`default`, `json`, ...).
     */
    public function templateType(): string;

    /**
     * The site's address, with its trailing slash.
     */
    public function displayUrl(): string;

    /**
     * The site's configured address (its scheme is the configured one, where `displayUrl()` follows the request).
     */
    public function configuredUrl(): string;

    /**
     * Whether the request came over a secure connection (the one rule of the site: `Idno\Http\TrustedProxies::isSecure()`).
     */
    public function isSecure(): bool;

    /**
     * The page to go to as a sign-in form's `fwd` field of a GET holds it (base64, URL-safe), decoded.
     */
    public function decodeForward(string $value): string;

    /**
     * The address of the login page that asks to go on to a page: `<site address>session/login/?fwd=<the page, encoded>`.
     */
    public function loginAddress(string $fwd): string;
}
