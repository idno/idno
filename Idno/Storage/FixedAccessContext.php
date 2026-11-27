<?php

declare(strict_types=1);

namespace Idno\Storage;

/**
 * An AccessContext with fixed values, for tests. The default is an anonymous visitor: no user,
 * not an admin, read groups ['PUBLIC'], access control on.
 *
 * A value/helper class, not a container service (see config/services.php).
 */
final class FixedAccessContext implements AccessContext
{
    /**
     * @param list<string> $readAccessGroupIds
     */
    public function __construct(
        private readonly ?string $currentUserUuid = null,
        private readonly bool $isAdmin = false,
        private readonly array $readAccessGroupIds = ['PUBLIC'],
        private readonly bool $ignoreAccess = false,
        private readonly array $closedOwners = [],
        private readonly array $siteContentClasses = [],
    )
    {
    }

    public function currentUserUuid(): ?string
    {
        return $this->currentUserUuid;
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function readAccessGroupIds(): array
    {
        return $this->readAccessGroupIds;
    }

    public function ignoreAccess(): bool
    {
        return $this->ignoreAccess;
    }

    public function closedOwners(): array
    {
        return $this->closedOwners;
    }

    public function siteContentClasses(): array
    {
        return $this->siteContentClasses;
    }
}
