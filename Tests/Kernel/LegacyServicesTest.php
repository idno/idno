<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Config\BootConfig;
use Idno\Config\SiteSettings;
use Idno\Core\Idno;
use Idno\Legacy\LegacyBootstrap;
use Idno\Legacy\NotInstalledException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The legacy services are built by the Symfony container, and Idno::site() hands out
 * the very same objects.
 */
final class LegacyServicesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function services(): array
    {
        return [
            'routes' => [\Idno\Core\PageHandler::class, 'routes'],
            'events' => [\Idno\Core\EventDispatcher::class, 'events'],
            'config' => [\Idno\Core\Config::class, 'config'],
            'db' => [\Idno\Core\DataConcierge::class, 'db'],
            'filesystem' => [\Idno\Files\FileSystem::class, 'filesystem'],
            'logging' => [\Idno\Core\Logging::class, 'logging'],
            'session' => [\Idno\Core\Session::class, 'session'],
            'actions' => [\Idno\Core\Actions::class, 'actions'],
            'template' => [\Idno\Core\Template::class, 'template'],
            'language' => [\Idno\Core\Language::class, 'language'],
            'syndication' => [\Idno\Core\Syndication::class, 'syndication'],
            'queue' => [\Idno\Core\EventQueue::class, 'queue'],
            'statistics' => [\Idno\Stats\StatisticsCollector::class, 'statistics'],
            'cache' => [\Idno\Caching\PersistentCache::class, 'cache'],
            'plugins' => [\Idno\Core\Plugins::class, 'plugins'],
            'themes' => [\Idno\Core\Themes::class, 'themes'],
        ];
    }

    /**
     * @dataProvider services
     */
    public function testAccessorReturnsContainerService(string $id, string $accessor): void
    {
        $this->assertNotNull(Idno::site()->$accessor(), "Idno::site()->$accessor() is null");
        $this->assertSame(Idno::container()->get($id), Idno::site()->$accessor());
    }

    public function testBootIsIdempotent(): void
    {
        $site = Idno::site();
        Idno::container()->get(LegacyBootstrap::class)->boot();
        $this->assertSame($site, Idno::site());
    }

    public function testDefaultConfigThrowsNotInstalled(): void
    {
        $site = Idno::site();
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === BootConfig::class) {
                    return new BootConfig([], true);
                }
                throw new \LogicException("Unexpected service $id: nothing may be built on an uninstalled site.");
            }

            public function has(string $id): bool
            {
                return $id === BootConfig::class;
            }
        };

        try {
            $this->expectException(NotInstalledException::class);
            (new LegacyBootstrap($container))->boot();
        } finally {
            $this->assertSame($site, Idno::site(), 'a second Idno was constructed');
        }
    }

    public function testConfigIsBuiltFromTheContainersBootConfig(): void
    {
        $property = new \ReflectionProperty(\Idno\Core\Config::class, 'bootConfig');

        $this->assertSame(Idno::container()->get(BootConfig::class), $property->getValue(Idno::site()->config()));
    }

    public function testSiteSettingsWrapsTheSiteConfig(): void
    {
        $settings = Idno::container()->get(SiteSettings::class);
        $property = new \ReflectionProperty($settings, 'config');

        $this->assertSame(Idno::site()->config(), $property->getValue($settings));
    }
}
