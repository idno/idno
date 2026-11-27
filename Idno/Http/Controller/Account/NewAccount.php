<?php

declare(strict_types=1);

namespace Idno\Http\Controller\Account;

use Idno\Entities\User;

/**
 * What making an account came to.
 */
final class NewAccount
{
    /** The account is saved (and the invitation, if there was one, used). */
    public const MADE = 'made';

    /** Somebody else used the invitation first, or it was withdrawn or sent again: nothing was made. */
    public const USED = 'used';

    /** The database was busy: nothing was changed, and the invitation is as it was. */
    public const BUSY = 'busy';

    /** The account could not be saved: nothing was made, and the invitation was not used. */
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $outcome,
        public readonly ?User $user = null,
        public readonly bool $administrator = false,
    )
    {
    }
}
