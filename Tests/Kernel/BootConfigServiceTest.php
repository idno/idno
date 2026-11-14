<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Config\BootConfig;
use Idno\Config\SiteSettings;
use Idno\Kernel;
use Idno\Legacy\LegacySiteSettings;
use PHPUnit\Framework\TestCase;

final class BootConfigServiceTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        unset($_SERVER['IDNO_BOOTCONFIG_SENTINEL']);
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    private function boot(): Kernel
    {
        $this->kernel = new Kernel('test', true, bootLegacy: false);
        $this->kernel->boot();
        return $this->kernel;
    }

    public function testBootConfigIsBuiltAtRuntimeFromTheEnvironment(): void
    {
        $_SERVER['IDNO_BOOTCONFIG_SENTINEL'] = 'sentinel-value-from-env';
        $kernel = $this->boot();

        $boot = $kernel->getContainer()->get(BootConfig::class);
        $this->assertInstanceOf(BootConfig::class, $boot);
        $this->assertSame('sentinel-value-from-env', $boot->get('bootconfig_sentinel'));
    }

    public function testNoConfigurationValueIsCompiledIntoTheContainerCache(): void
    {
        $_SERVER['IDNO_BOOTCONFIG_SENTINEL'] = 'sentinel-value-from-env';
        $kernel = $this->boot();
        $kernel->getContainer()->get(BootConfig::class);

        $compiled = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($kernel->getCacheDir(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $compiled++;
                $this->assertStringNotContainsString('sentinel-value-from-env', (string) file_get_contents($file->getPathname()));
            }
        }
        $this->assertGreaterThan(0, $compiled, 'expected compiled container files in the cache directory');
    }

    public function testSiteSettingsIsAliasedToTheLegacyImplementation(): void
    {
        $container = $this->boot()->getContainer();

        $this->assertTrue($container->has(SiteSettings::class));
        $this->assertInstanceOf(LegacySiteSettings::class, $container->get(SiteSettings::class));
    }
}
