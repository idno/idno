<?php

declare(strict_types=1);

namespace Idno\Install;

/**
 * What a database chosen for a new installation already holds.
 */
final class DatabaseFinding
{
    /** No Idno tables: a new site. (Another application's tables do not count.) */
    public const EMPTY = 'empty';
    /** An Idno site of this version's kind (its tables exist; they may need an upgrade). */
    public const IDNO = 'idno';
    /** An Idno 1.x site, which the upgrade copies into new tables. */
    public const IDNO_ONE_X = 'idno 1.x';
    /** A database Idno cannot start on; `message` says why. */
    public const REFUSED = 'refused';

    public function __construct(public readonly string $kind, public readonly string $message = '')
    {
    }

    public function holdsASite(): bool
    {
        return $this->kind === self::IDNO || $this->kind === self::IDNO_ONE_X;
    }
}
