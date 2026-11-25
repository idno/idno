<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Idno;
use Idno\Entities\User;
use Idno\Install\AdministratorDetails;
use Idno\Install\FirstAdministrator;

/**
 * The site's first administrator, made with the legacy user entity, configuration and session. It
 * needs the legacy application.
 */
final class LegacyFirstAdministrator implements FirstAdministrator
{
    public function administratorExists(): bool
    {
        return (bool) $this->ignoringAccess(static fn () => User::getOne(['admin' => true]));
    }

    public function problems(AdministratorDetails $details): array
    {
        $problems = $details->problems();
        $site = self::site();
        if (!isset($problems['handle']) && $this->ignoringAccess(static fn () => User::getByHandle(strtolower($details->handle)))) {
            $problems['handle'] = 'That username is already taken on this site. Choose another.';
        }
        if (!isset($problems['email'])) {
            if ($site->config()->emailIsBlocked($details->email)) {
                $problems['email'] = 'That email address cannot be used on this site.';
            } elseif ($this->ignoringAccess(static fn () => User::getByEmail($details->email))) {
                $problems['email'] = 'There is already an account with that email address on this site.';
            }
        }
        if (!isset($problems['password']) && !isset($problems['password2']) && !User::checkNewPasswordStrength(trim($details->password))) {
            $problems['password'] = 'That password is not strong enough for this site. Choose a longer one.';
        }

        return $problems;
    }

    public function create(AdministratorDetails $details, string $siteTitle): string
    {
        if ($this->administratorExists()) {
            throw new \RuntimeException('This site already has an administrator.');
        }
        if ($this->problems($details) !== []) {
            throw new \RuntimeException('The account\'s details are not valid.');
        }
        $site = self::site();

        $user = new User();
        $user->email = $details->email;
        $user->handle = strtolower($details->handle);
        $user->setPassword(trim($details->password));
        $user->setTitle($details->displayName());
        $user->setAdmin(true);

        $config = $site->config();
        $siteTitle = trim($siteTitle);
        if ($siteTitle !== '') {
            $config->title = $siteTitle;
        } elseif ($config->title == 'New Idno site' || empty($config->title)) {
            $config->title = $details->displayName() . '\'s Idno';
        }
        $config->open_registration = false;
        if (empty($config->from_email)) {
            $config->from_email = $details->email;
        }
        $config->save();

        $site->events()->triggerEvent('site/firstadmin', ['user' => $user]);
        if (!$user->save(true) || empty($user->getUUID())) {
            throw new \RuntimeException('The account could not be saved.');
        }

        return (string) $user->getUUID();
    }

    public function signIn(string $uuid): void
    {
        $user = $this->ignoringAccess(static fn () => User::getByUUID($uuid));
        if ($user instanceof User) {
            self::site()->session()->logUserOn($user);
        }
    }

    private static function site(): Idno
    {
        $site = Idno::site();
        if (!$site instanceof Idno) {
            throw new \LogicException('The first administrator needs the legacy application, which is not booted.');
        }

        return $site;
    }

    /**
     * Runs a lookup without the viewer's access rules: nobody is signed in while a site is set up.
     */
    private function ignoringAccess(\Closure $lookup): mixed
    {
        $db = self::site()->db();
        $before = $db->getIgnoreAccess();
        $db->setIgnoreAccess(true);
        try {
            return $lookup();
        } finally {
            $db->setIgnoreAccess($before);
        }
    }
}
