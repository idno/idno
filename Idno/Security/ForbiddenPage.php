<?php

declare(strict_types=1);

namespace Idno\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Draws the site's 403 page for a request that was refused, or null when it cannot be drawn (the legacy
 * application that owns the templates is not booted).
 */
interface ForbiddenPage
{
    public function render(Request $request): ?Response;
}
