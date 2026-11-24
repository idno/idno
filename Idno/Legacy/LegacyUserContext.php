<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Session;
use Idno\Entities\User;
use Idno\Queue\UserContext;

/**
 * The queue's UserContext over the legacy session: it logs the user on (Session::logUserOn(), which
 * triggers the logon events) and, whatever the work does, logs off afterwards (Session::logUserOff(),
 * which the legacy queue also called after every event, for events run as nobody too). A user a listener held
 * statelessly (Session::authenticateStatelessly()) is forgotten before and after each message.
 */
final class LegacyUserContext implements UserContext
{
    public function __construct(private readonly Session $session)
    {
    }

    public function runAs(?string $uuid, callable $work): mixed
    {
        // What an earlier message authenticated statelessly must not shadow the user this one runs as.
        $this->session->resetStatelessAuthentication();
        try {
            if ($uuid !== null && $uuid !== '') {
                // Read fresh, never from the entity cache: a worker lives long, and a user deleted since an
                // earlier event cached them must not pass. A user the session will not log on (a blocked
                // address) is no user either: the event must not run as nobody instead.
                $user = User::getByUUID($uuid, false);
                if (!$user instanceof User || !$this->session->logUserOn($user)) {
                    throw new \RuntimeException('Invalid user (' . $uuid . ') given for runAsContext, aborting');
                }
            } else {
                $this->ensureNobodyLoggedOn();
            }

            return $work();
        } finally {
            $this->session->resetStatelessAuthentication();
            $this->session->logUserOff();
        }
    }

    public function ensureNobodyLoggedOn(): void
    {
        // Neither a session user nor a stateless one (a listener may have authenticated one) stays for the next message.
        $this->session->resetStatelessAuthentication();
        if ($this->session->currentUser() !== false) {
            $this->session->logUserOff();
        }
    }
}
