<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Security\SessionUser;
use PHPUnit\Framework\TestCase;

final class SessionUserTest extends TestCase
{
    public function testAMemberHasRoleUser(): void
    {
        $user = new SessionUser('http://example.com/profile/ada', 'ada', false);

        $this->assertSame('http://example.com/profile/ada', $user->getUserIdentifier());
        $this->assertSame('ada', $user->getHandle());
        $this->assertSame(['ROLE_USER'], $user->getRoles());
        $this->assertFalse($user->isStateless());
    }

    public function testAnAdministratorAlsoHasRoleAdmin(): void
    {
        $this->assertSame(['ROLE_USER', 'ROLE_ADMIN'], (new SessionUser('u', 'root', true))->getRoles());
    }

    public function testAStatelessAuthenticationIsRemembered(): void
    {
        $this->assertTrue((new SessionUser('u', 'ada', false, true))->isStateless());
    }

    public function testAStatelessAdministratorIsNoAdministratorWhateverTheCallerSaid(): void
    {
        $this->assertSame(['ROLE_USER'], (new SessionUser('u', 'h', true, true))->getRoles());
        $this->assertSame(['ROLE_USER', 'ROLE_ADMIN'], (new SessionUser('u', 'h', true, false))->getRoles());
    }
}
