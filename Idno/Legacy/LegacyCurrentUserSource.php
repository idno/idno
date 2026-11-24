<?php

declare(strict_types=1);

namespace Idno\Legacy;

use Idno\Core\Idno;
use Idno\Entities\User;
use Idno\Kernel;
use Idno\Security\CurrentUserSource;
use Idno\Security\SessionUser;
use Symfony\Component\HttpFoundation\Request;

/**
 * Who is making the request, from the legacy session (Idno::site()->session()): the user the legacy application
 * authenticated while the kernel booted, by the browser session or by a stateless credential (a signed API request,
 * a bearer token, a plugin's hook). Built from the User entity the session already holds: no lookup. Null when
 * the legacy application is not booted by this kernel (a kernel built with bootLegacy: false, or a closed schema
 * gate): the session is never built here.
 */
final class LegacyCurrentUserSource implements CurrentUserSource
{
    public function __construct(private readonly Kernel $kernel)
    {
    }

    public function current(Request $request): ?SessionUser
    {
        if (!$this->kernel->legacyBooted() || !Idno::site() instanceof Idno) {
            return null;
        }
        $session = Idno::site()->session();
        $user = $session ? $session->currentUser() : false;
        if (!$user instanceof User) {
            return null;
        }
        $uuid = (string) $user->getUUID();
        if ($uuid === '') {
            return null;
        }

        // isAdmin() is false for a stateless request: only the browser session makes an administrator.
        return new SessionUser($uuid, (string) $user->getHandle(), (bool) $user->isAdmin(), (bool) $session->isAPIRequest());
    }
}
