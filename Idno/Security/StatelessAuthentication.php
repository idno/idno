<?php

declare(strict_types=1);

namespace Idno\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Whether a request was authenticated by a stateless credential (a signed API request, a bearer token, a plugin's
 * hook) rather than by the browser session. The authenticator marks the request; a controller asks.
 */
final class StatelessAuthentication
{
    public const ATTRIBUTE = '_idno_stateless';

    public static function isStateless(Request $request): bool
    {
        return $request->attributes->get(self::ATTRIBUTE) === true;
    }

    public static function mark(Request $request): void
    {
        $request->attributes->set(self::ATTRIBUTE, true);
    }
}
