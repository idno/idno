<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\AccountRegistry;
use Idno\Core\Idno;
use Idno\Entities\Invitation;
use Idno\Entities\User;
use Idno\Exceptions\DatabaseBusyException;
use Idno\Http\Controller\Account\NewAccount;
use Idno\Http\Controller\Account\RegistrationAdmission;
use Idno\Http\Controller\Account\RegistrationInvitation;
use Idno\Http\Controller\Account\Registrations;

/**
 * What the registration page asks the site, over the legacy session, finders and invitations: what `Idno\Pages\Account\Register` asked, moved here
 * unchanged.
 */
final class LegacyRegistrations implements Registrations
{
    public function siteHasAccounts(): bool
    {
        return (bool) User::get();
    }

    public function signedInName(): ?string
    {
        $session = Idno::site()->session();
        if (!$session->isLoggedIn()) {
            return null;
        }

        return (string) $session->currentUser()->getTitle();
    }

    public function isOpen(): bool
    {
        return !empty(Idno::site()->config()->open_registration);
    }

    public function canAddUsers(): bool
    {
        return (bool) Idno::site()->config()->canAddUsers();
    }

    public function checkInvitation(string $email, string $code): RegistrationAdmission
    {
        $check = Invitation::check($email, $code);
        $invitation = $check['invitation'];
        if (!$invitation instanceof Invitation) {
            return new RegistrationAdmission(null, (string) $check['problem']);
        }

        return new RegistrationAdmission(
            new RegistrationInvitation((string) $invitation->email, (bool) $invitation->makesAnAdministrator(), $invitation->getInviterName(), $invitation),
            (string) $check['problem']
        );
    }

    public function emailIsBlocked(string $email): bool
    {
        return (bool) Idno::site()->config()->emailIsBlocked($email);
    }

    public function accountExistsForEmail(string $email): bool
    {
        return (bool) User::getByEmail($email);
    }

    public function handleIsTaken(string $handle): bool
    {
        return (bool) (User::getByHandle($handle) ?: AccountRegistry::handleIsReserved($handle));
    }

    public function passwordIsStrong(#[\SensitiveParameter] string $password): bool
    {
        return (bool) User::checkNewPasswordStrength($password);
    }

    public function validates(array $fields): bool
    {
        return (bool) Idno::site()->events()->triggerEvent('user/register/validate', $fields, true);
    }

    public function create(string $email, string $handle, #[\SensitiveParameter] string $password, string $name, ?RegistrationInvitation $invitation): NewAccount
    {
        $user = new User();
        $user->email = $email;
        $user->handle = $handle;
        $user->setPassword($password);
        $user->setTitle($name);

        $source = $invitation?->handle;
        if ($source !== null && !$source instanceof Invitation) {
            throw new \InvalidArgumentException('The invitation was not made by this adapter.');
        }

        $site = Idno::site();
        $outcome = NewAccount::FAILED;
        try {
            $site->db()->atomically(
                function () use ($site, $user, $source, &$outcome) {
                    if ($source !== null && !$source->consume()) {
                        $outcome = NewAccount::USED;

                        return false;
                    }
                    // The role comes from the invitation as it is stored (consume() reads it again), never from the request
                    if ($source !== null && $source->makesAnAdministrator()) {
                        $user->setAdmin(true);
                    }
                    $site->events()->triggerEvent('site/newuser', ['user' => $user]); // Event hook for new user
                    if (!$user->save(true)) {
                        return false;
                    }
                    $outcome = NewAccount::MADE;

                    return true;
                }
            );
        } catch (DatabaseBusyException) {
            return new NewAccount(NewAccount::BUSY);
        }

        return $outcome === NewAccount::MADE ? new NewAccount($outcome, $user, (bool) $user->isAdmin()) : new NewAccount($outcome);
    }

    public function logOn(User $user): void
    {
        Idno::site()->session()->logUserOn($user);
    }

    public function message(string $text, string $type = 'alert-info'): void
    {
        Idno::site()->session()->addMessage($text, $type);
    }

    public function messageAtStart(string $text, string $type = 'alert-info'): void
    {
        Idno::site()->session()->addMessageAtStart($text, $type);
    }

    public function siteUrl(): string
    {
        return Idno::site()->config()->getURL();
    }

    public function displayUrl(): string
    {
        return Idno::site()->config()->getDisplayURL();
    }
}
