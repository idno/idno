<?php

declare(strict_types=1);

namespace Idno\Security;

/**
 * What NativeSession::configure() sets before a session handler is installed: whether the session cookie is `Secure` (the site is served over https).
 */
final class SessionSettings
{
    public function __construct(
        public readonly bool $secure,
    )
    {
    }
}
