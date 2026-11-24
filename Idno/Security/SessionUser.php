<?php

declare(strict_types=1);

namespace Idno\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The member who is making a request, as Symfony Security sees them: built from the User entity the legacy session
 * already loaded (nothing is looked up), so it holds no more than the firewall needs. Every member has ROLE_USER;
 * ROLE_ADMIN only an administrator authenticated by the browser session (never one authenticated statelessly: a
 * signed API request, a bearer token, a plugin's hook: User::isAdmin() is false for those too).
 */
final class SessionUser implements UserInterface
{
    public function __construct(
        private readonly string $uuid,
        private readonly string $handle,
        private readonly bool $admin,
        private readonly bool $stateless = false,
    )
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->uuid;
    }

    public function getHandle(): string
    {
        return $this->handle;
    }

    /**
     * Whether a stateless credential (not the browser session) authenticated this member for the request.
     */
    public function isStateless(): bool
    {
        return $this->stateless;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->admin ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];
    }
}
