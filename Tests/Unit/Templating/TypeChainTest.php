<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\TypeChain;
use PHPUnit\Framework\TestCase;

final class TypeChainTest extends TestCase
{
    public function testDefaultIsTriedAlone(): void
    {
        $this->assertSame(['default'], TypeChain::for('default'));
    }

    public function testAnyOtherTypeIsTriedBeforeDefault(): void
    {
        $this->assertSame(['rss', 'default'], TypeChain::for('rss'));
        $this->assertSame(['modern', 'default'], TypeChain::for('modern'));
    }

    public function testEveryTypeIsSelectable(): void
    {
        $this->assertTrue(TypeChain::selectable('default'));
        $this->assertTrue(TypeChain::selectable('rss'));
    }
}
