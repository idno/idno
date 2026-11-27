<?php

declare(strict_types=1);

namespace Idno\Security;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * The sign-in attempt rule: a throttle that cannot be built or fails is never a reason to refuse a login.
 *
 * It wraps LoginThrottle, which is built when first fetched (it needs the site's cache pool). attempt() is true
 * when the throttle cannot be built or throws; reset() never throws.
 *
 * Two deliberate improvements on the static methods of the login page this replaces: those warned on every failed
 * build of the throttle, and an exception from attempt() was silent. Now the warning is logged once a process
 * (a login page that serves many attempts in a worker would otherwise repeat it), and an exception from attempt() is
 * warned of too. A failed reset is logged on each failure.
 */
final class LoginAttempts
{
    private bool $unavailableWarned = false;

    /**
     * @param \Closure(): LoginThrottle $throttle the throttle, fetched when first asked
     * @param \Closure(): LoggerInterface $logger the site's logger, fetched when something is logged
     */
    public function __construct(
        #[AutowireServiceClosure(LoginThrottle::class)]
        private readonly \Closure $throttle,
        #[AutowireServiceClosure(LoggerInterface::class)]
        private readonly \Closure $logger,
    )
    {
    }

    /**
     * Reserves an attempt for the key; true when it may go on.
     */
    public function attempt(string $key): bool
    {
        try {
            return ($this->throttle)()->attempt($key);
        } catch (\Throwable $e) {
            $this->warnUnavailableOnce($e);

            return true;
        }
    }

    /**
     * Clears the count of a key (a successful login, a password reset).
     */
    public function reset(string $key): void
    {
        try {
            ($this->throttle)()->reset($key);
        } catch (\Throwable $e) {
            try {
                ($this->logger)()->warning('The count of failed logins could not be reset: ' . $e::class);
            } catch (\Throwable) {
                // A logger that cannot be built changes nothing.
            }
        }
    }

    private function warnUnavailableOnce(\Throwable $e): void
    {
        if ($this->unavailableWarned) {
            return;
        }
        $this->unavailableWarned = true;
        try {
            ($this->logger)()->warning('The login throttle is unavailable: ' . $e::class);
        } catch (\Throwable) {
            // A logger that cannot be built changes nothing.
        }
    }
}
