<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Session;
use Idno\Entities\User;
use Idno\Queue\UserContext;

/**
 * The queue's UserContext over the legacy session: it logs the user on (Session::logUserOn(), which
 * triggers the logon events) and, whatever the work does, logs off afterwards (Session::logUserOff(),
 * which the legacy queue also called after every event, for events run as nobody too).
 */
final class LegacyUserContext implements UserContext
{
    public function __construct(private readonly Session $session)
    {
    }

    public function runAs(?string $uuid, callable $work): mixed
    {
        try {
            if ($uuid !== null && $uuid !== '') {
                $user = User::getByUUID($uuid);
                if (!$user instanceof User) {
                    throw new \RuntimeException('Invalid user (' . $uuid . ') given for runAsContext, aborting');
                }
                $this->session->logUserOn($user);
            } else {
                $this->ensureNobodyLoggedOn();
            }

            return $work();
        } finally {
            $this->session->logUserOff();
        }
    }

    public function ensureNobodyLoggedOn(): void
    {
        if ($this->session->currentUser() !== false) {
            $this->session->logUserOff();
        }
    }
}
