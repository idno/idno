<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\SlotRegistry;
use PHPUnit\Framework\TestCase;

final class SlotRegistryTest extends TestCase
{
    public function testExtensionsKeepTheOrderAddedAndToFrontPutsOneFirst(): void
    {
        $slots = new SlotRegistry();
        $slots->extend('x', 'a');
        $slots->extend('x', 'b');
        $slots->extend('x', 'c', true);
        $slots->extend('x', 'only-rss', false, 'rss');

        $this->assertSame(['c', 'a', 'b'], $slots->extensions('x', '*'));
        $this->assertSame(['only-rss'], $slots->extensions('x', 'rss'));
        $this->assertSame([], $slots->extensions('x', 'json'));
        $this->assertSame([], $slots->extensions('y', '*'));
    }

    public function testPrepends(): void
    {
        $slots = new SlotRegistry();
        $slots->prepend('x', 'p1');
        $slots->prepend('x', 'p2');
        $slots->prepend('x', 'p3', true);
        $slots->prepend('y', 'first', true);

        $this->assertSame(['p3', 'p1', 'p2'], $slots->prepends('x'));
        $this->assertSame(['first'], $slots->prepends('y'));
        $this->assertSame([], $slots->prepends('z'));
    }

    public function testAReplacementForANameAndTypeIsOne(): void
    {
        $slots = new SlotRegistry();
        $slots->replace('x', 'r');
        $slots->replace('x', 'r2');
        $slots->replace('x', 'rss-r', 'rss');

        $this->assertSame('r2', $slots->replacement('x', '*'));
        $this->assertSame('rss-r', $slots->replacement('x', 'rss'));
        $this->assertNull($slots->replacement('x', 'json'));
        $this->assertNull($slots->replacement('y', '*'));
    }

    public function testAReplacementThatIsEmptyIsNone(): void
    {
        $slots = new SlotRegistry();
        $slots->replace('x', '');
        $slots->replace('y', '0');

        $this->assertNull($slots->replacement('x', '*'));
        $this->assertNull($slots->replacement('y', '*'));
    }

    public function testContentIsAddedInOrder(): void
    {
        $slots = new SlotRegistry();
        $slots->extendWithContent('x', '<c1>');
        $slots->extendWithContent('x', '<c2>');

        $this->assertSame('<c1><c2>', $slots->content('x'));
        $this->assertSame('', $slots->content('y'));
    }

    public function testTheMapsAreTheRegistrations(): void
    {
        $slots = new SlotRegistry();
        $slots->extend('x', 'e');
        $slots->prepend('x', 'p');
        $slots->replace('x', 'r');
        $slots->extendWithContent('x', 'c');

        $this->assertSame(['x' => ['*' => ['e']]], $slots->extensionMap());
        $this->assertSame(['x' => ['p']], $slots->prependMap());
        $this->assertSame(['x' => ['*' => 'r']], $slots->replacementMap());
        $this->assertSame(['x' => 'c'], $slots->contentMap());
    }

    public function testACloneKeepsItsRegistrationsToItself(): void
    {
        $slots = new SlotRegistry();
        $slots->extend('x', 'e');
        $copy = clone $slots;
        $copy->extend('x', 'e2');

        $this->assertSame(['e'], $slots->extensions('x', '*'));
        $this->assertSame(['e', 'e2'], $copy->extensions('x', '*'));
    }
}
