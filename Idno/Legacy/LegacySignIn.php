<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Common\Page;
use Idno\Core\Idno;
use Idno\Core\Webservice;
use Idno\Entities\User;
use Idno\Http\Controller\Session\SignIn;

/**
 * What the sign-in pages ask the site, over the legacy session and finders: what `Idno\Pages\Session\Login` and `Logout` asked, moved here unchanged.
 */
final class LegacySignIn implements SignIn
{
    public function currentUser(): ?User
    {
        $user = Idno::site()->session()->currentUser();

        return $user instanceof User ? $user : null;
    }

    public function isApiRequest(): bool
    {
        return (bool) Idno::site()->session()->isAPIRequest();
    }

    public function accountFor(string $login): ?User
    {
        $user = User::getByHandle($login) ?: User::getByEmail($login);

        return $user instanceof User ? $user : null;
    }

    public function checkPassword(User $user, #[\SensitiveParameter] string $password): bool
    {
        return (bool) $user->checkPassword($password);
    }

    public function logOn(User $user): void
    {
        Idno::site()->session()->logUserOn($user);
    }

    public function logOff(): void
    {
        $session = Idno::site()->session();
        $session->logUserOff();
        $session->startNewSession();
    }

    public function apiKey(User $user): string
    {
        return (string) $user->getAPIkey();
    }

    public function trigger(string $event, array $data): void
    {
        Idno::site()->events()->triggerEvent($event, $data);
    }

    public function message(string $text, string $type = 'alert-info'): void
    {
        Idno::site()->session()->addMessage($text, $type);
    }

    public function templateType(): string
    {
        return (string) Idno::site()->template()->getTemplateType();
    }

    public function displayUrl(): string
    {
        return Idno::site()->config()->getDisplayURL();
    }

    public function isSecure(): bool
    {
        return Page::isSSL();
    }

    public function decodeForward(string $value): string
    {
        return (string) Webservice::base64UrlDecode($value);
    }

    public function loginAddress(string $fwd): string
    {
        return Idno::site()->config()->getDisplayURL() . 'session/login/?fwd=' . Webservice::encodeValue($fwd);
    }
}
