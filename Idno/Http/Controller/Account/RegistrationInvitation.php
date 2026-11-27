<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Account;

/**
 * An invitation as the registration page knows it: whom it is for, whether it makes an administrator and who sent it, which is all the form shows.
 * `$handle` is what the adapter needs to use the invitation up later; the page never reads it. Nothing of this is ever a variable of the page's own
 * template: the code is a credential.
 */
final class RegistrationInvitation
{
    public function __construct(
        public readonly string $email,
        public readonly bool $administrator,
        public readonly string $inviter,
        public readonly object $handle,
    )
    {
    }
}
