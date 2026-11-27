<?php

declare(strict_types=1);

namespace Idno\Queue;

/**
 * Who a queued event runs as. The queue's handler and worker middleware depend on this, not on the
 * application's session (Idno\Legacy\LegacyUserContext is the adapter over it).
 */
interface UserContext
{
    /**
     * Runs $work as the user with the given uuid, or as nobody for null, and returns what it
     * returned. Nobody is logged on afterwards, whether $work returned or threw (anything thrown,
     * \Error included, is thrown on).
     *
     * Nothing is run, and null is returned, for an account that has been closed or deleted since the event was queued (it is dropped, not failed).
     *
     * @throws \RuntimeException when no user has the uuid ($work is not run)
     */
    public function runAs(?string $uuid, callable $work): mixed;

    /**
     * Logs off whoever is logged on; does nothing when nobody is.
     */
    public function ensureNobodyLoggedOn(): void;
}
