<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\TypeChain;
use PHPUnit\Framework\TestCase;

final class TypeChainTest extends TestCase
{
    public function testAWebPageTriesModernThenDefault(): void
    {
        $this->assertSame(['modern', 'default'], TypeChain::for('default'));
        $this->assertSame(['modern', 'default'], TypeChain::for('modern'), '`modern` is the web page too');
    }

    public function testAnyOtherTypeIsTriedBeforeClassicAndDefault(): void
    {
        $this->assertSame(['rss', 'classic', 'default'], TypeChain::for('rss'));
        $this->assertSame(['email-text', 'classic', 'default'], TypeChain::for('email-text'));
        $this->assertSame(['json', 'classic', 'default'], TypeChain::for('json'));
    }

    /**
     * A type is a directory name, and on a file system that ignores case (macOS, Windows) `DEFAULT` is the directory `default`
     * and `CLASSIC` the directory `classic`: the names are compared in lower case, so no spelling reaches `classic` for a page.
     */
    public function testTheNamesAreComparedInLowerCase(): void
    {
        $this->assertSame(['modern', 'default'], TypeChain::for('DEFAULT'));
        $this->assertSame(['modern', 'default'], TypeChain::for('Modern'));
        $this->assertFalse(TypeChain::selectable('CLASSIC'));
        $this->assertFalse(TypeChain::selectable('Classic'));
    }

    public function testClassicIsNeverTriedTwice(): void
    {
        $this->assertSame(['classic', 'default'], TypeChain::for('classic'));
    }

    public function testEveryTypeButClassicIsSelectable(): void
    {
        $this->assertTrue(TypeChain::selectable('default'));
        $this->assertTrue(TypeChain::selectable('modern'));
        $this->assertTrue(TypeChain::selectable('rss'));
        $this->assertTrue(TypeChain::selectable('email-text'));
        $this->assertFalse(TypeChain::selectable('classic'), 'the output that is not a web page reaches classic by fallback only');
    }
}
