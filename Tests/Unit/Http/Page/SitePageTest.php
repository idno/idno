<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Page;

use Idno\Http\Page\SitePage;
use PHPUnit\Framework\TestCase;

#[SitePage('Fixture\ClassName', accountPage: true)]
final class SitePageFixtureController
{
    #[SitePage('Fixture\MethodName', replaces: ['Fixture\Old'], publicOnPrivateSite: true)]
    public function method(): void
    {
    }

    public function plain(): void
    {
    }

    public function __invoke(): void
    {
    }
}

final class SitePageTest extends TestCase
{
    public function testMethodAttributeWinsOverClass(): void
    {
        $page = SitePage::of([new SitePageFixtureController(), 'method']);

        $this->assertNotNull($page);
        $this->assertSame('Fixture\MethodName', $page->name);
        $this->assertSame(['Fixture\Old'], $page->replaces);
        $this->assertTrue($page->publicOnPrivateSite);
        $this->assertFalse($page->accountPage);
    }

    public function testTheClassAttributeIsUsedWhenTheMethodHasNone(): void
    {
        $page = SitePage::of([new SitePageFixtureController(), 'plain']);

        $this->assertNotNull($page);
        $this->assertSame('Fixture\ClassName', $page->name);
        $this->assertTrue($page->accountPage);
        $this->assertFalse($page->settingsPage);
        $this->assertNull($page->defaultType);
    }

    public function testOfAcceptsStringsArraysAndInvokables(): void
    {
        $class = SitePageFixtureController::class;

        $this->assertSame('Fixture\MethodName', SitePage::of($class . '::method')?->name);
        $this->assertSame('Fixture\MethodName', SitePage::of([$class, 'method'])?->name);
        $this->assertSame('Fixture\ClassName', SitePage::of(new SitePageFixtureController())?->name);
        $this->assertSame('Fixture\ClassName', SitePage::of($class)?->name);
        $this->assertSame('Fixture\ClassName', SitePage::of($class . '::plain')?->name);
    }

    public function testNoneForAPlainController(): void
    {
        $plain = new class {
            public function __invoke(): void
            {
            }
        };

        $this->assertNull(SitePage::of($plain));
        $this->assertNull(SitePage::of([$plain, '__invoke']));
        $this->assertNull(SitePage::of(static fn () => null));
        $this->assertNull(SitePage::of('no_such_function'));
        $this->assertNull(SitePage::of('No\Such\Class::method'));
        $this->assertNull(SitePage::of([]));
    }
}
