<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Account;

/**
 * What the site says about an address and a code: the invitation they name when it can be used, else why it cannot.
 */
final class RegistrationAdmission
{
    public const INVALID = 'invalid';

    public const EXPIRED = 'expired';

    public function __construct(
        public readonly ?RegistrationInvitation $invitation,
        public readonly string $problem,
    )
    {
    }
}
