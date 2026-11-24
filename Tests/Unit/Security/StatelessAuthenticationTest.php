<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Idno\Security\StatelessAuthentication;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class StatelessAuthenticationTest extends TestCase
{
    public function testARequestIsNotStatelessUntilMarked(): void
    {
        $request = Request::create('/');

        $this->assertFalse(StatelessAuthentication::isStateless($request));
        StatelessAuthentication::mark($request);
        $this->assertTrue(StatelessAuthentication::isStateless($request));
        $this->assertTrue($request->attributes->get(StatelessAuthentication::ATTRIBUTE));
    }

    public function testOnlyTrueCounts(): void
    {
        $request = Request::create('/');
        $request->attributes->set(StatelessAuthentication::ATTRIBUTE, 'yes');

        $this->assertFalse(StatelessAuthentication::isStateless($request));
    }
}
