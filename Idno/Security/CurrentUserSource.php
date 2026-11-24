<?php

declare(strict_types=1);

namespace Idno\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Says who is making a request, for the firewall. The one implementation reads the legacy session (the legacy
 * application authenticates the request while the kernel boots, before the firewall runs); it answers null when
 * there is nobody, and when the legacy application is not booted. The admin flag of the answer is already false for a
 * stateless authentication.
 */
interface CurrentUserSource
{
    public function current(Request $request): ?SessionUser;
}
