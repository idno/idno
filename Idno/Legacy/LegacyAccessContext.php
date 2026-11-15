<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Idno;
use Idno\Storage\AccessContext;

/**
 * The AccessContext of the legacy application: the logged-on user from the legacy session
 * (Idno::site()->session()) and the ignore-access flag of the db() service, all read at call time,
 * never in the constructor (the db() service will itself depend on EntityRepository, so it cannot
 * be a constructor dependency).
 *
 * Where there is no session object at all (before Idno::init() has built it), this is what
 * getObjects() does in that case: no user, not an admin, read groups ['PUBLIC'].
 *
 * It needs the legacy application: calling it before the legacy application has booted (a kernel
 * built with bootLegacy: false) throws a \LogicException.
 */
final class LegacyAccessContext implements AccessContext
{
    public function currentUserUuid(): ?string
    {
        $session = $this->site()->session();
        $uuid = $session ? $session->currentUserUUID() : false;

        return empty($uuid) ? null : (string) $uuid;
    }

    public function isAdmin(): bool
    {
        $session = $this->site()->session();

        return $session !== null && (bool) $session->isAdmin();
    }

    public function readAccessGroupIds(): array
    {
        $session = $this->site()->session();

        // Session::getReadAccessGroupIDs() passes through the permission:read:ids event: kept as is.
        return $session ? (array) $session->getReadAccessGroupIDs() : ['PUBLIC'];
    }

    public function ignoreAccess(): bool
    {
        $db = $this->site()->db();

        return $db !== null && (bool) $db->getIgnoreAccess();
    }

    private function site(): Idno
    {
        $site = Idno::site();
        if (!$site instanceof Idno) {
            throw new \LogicException('LegacyAccessContext needs the legacy application, which has not booted yet (a kernel built with bootLegacy: false?).');
        }

        return $site;
    }
}
