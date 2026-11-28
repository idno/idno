<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Email;
use Idno\Core\Idno;
use Idno\Entities\User;
use Idno\Http\Controller\Account\PasswordResets;

/**
 * What the password-reset pages ask the site, over the legacy session, finders and mailer: what `Idno\Pages\Account\Password` and
 * `Idno\Pages\Account\Password\Reset` asked, moved here unchanged.
 */
final class LegacyPasswordResets implements PasswordResets
{
    public function isSignedIn(): bool
    {
        return (bool) Idno::site()->session()->isLoggedIn();
    }

    public function accountForRequest(string $email): ?User
    {
        $user = User::getByEmail($email);

        return $user instanceof User && !$user->isClosed() && !Idno::site()->config()->emailIsBlocked($user->email) ? $user : null;
    }

    public function issueCode(User $account): ?string
    {
        $code = $account->addPasswordRecoveryCode();
        if (!$code) {
            return null;
        }
        $account->save(); // Save the recovery code to the user

        return (string) $code;
    }

    public function mailCode(User $account, string $email, #[\SensitiveParameter] string $code): void
    {
        $mail = new Email();
        $mail->addTo($account->email);
        $mail->setBodyFromTemplate('account/password', ['email' => $email, 'code' => $code]);
        $mail->send();
    }

    public function memberFor(string $email, #[\SensitiveParameter] string $code): ?User
    {
        if ($email === '' || $code === '') {
            return null;
        }
        $user = User::getByEmail($email);
        if (!$user instanceof User || $user->isClosed() || Idno::site()->config()->emailIsBlocked($user->email)) {
            return null;
        }

        return $user->passwordRecoveryCodeMatches($code) ? $user : null;
    }

    public function accountId(User $account): string
    {
        return (string) $account->getUUID();
    }

    public function passwordIsStrong(#[\SensitiveParameter] string $password): bool
    {
        return (bool) User::checkNewPasswordStrength($password);
    }

    public function changePassword(User $account, #[\SensitiveParameter] string $password): void
    {
        $account->setPassword($password);
        $account->clearPasswordRecoveryCode();
        $account->save(true);
    }

    public function mailIsConfigured(): bool
    {
        return (bool) Email::isConfigured();
    }

    public function message(string $text, string $type = 'alert-info'): void
    {
        Idno::site()->session()->addMessage($text, $type);
    }

    public function displayUrl(): string
    {
        return Idno::site()->config()->getDisplayURL();
    }
}
