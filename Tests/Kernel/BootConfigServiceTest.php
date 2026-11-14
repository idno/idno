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
    private ?string $cacheDir = null;
    private mixed $previousCacheDir = null;

    protected function tearDown(): void
    {
        unset($_SERVER['IDNO_BOOTCONFIG_SENTINEL'], $_SERVER['APP_SECRET'], $_SERVER['IDNO_KERNEL_SECRET']);
        $this->kernel?->shutdown();
        $this->kernel = null;
        if ($this->cacheDir !== null) {
            $this->removeDir($this->cacheDir);
            $this->cacheDir = null;
            if ($this->previousCacheDir === null) {
                unset($_SERVER['IDNO_CACHE_DIR']);
            } else {
                $_SERVER['IDNO_CACHE_DIR'] = $this->previousCacheDir;
            }
        }
    }

    private function removeDir(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeDir($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    private function boot(): Kernel
    {
        // A fresh cache directory forces the container to be compiled during this test.
        $this->previousCacheDir = $_SERVER['IDNO_CACHE_DIR'] ?? null;
        $this->cacheDir = sys_get_temp_dir() . '/idno-test-cache-' . bin2hex(random_bytes(6));
        $_SERVER['IDNO_CACHE_DIR'] = $this->cacheDir;
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

    public function testTheKernelSecretComesFromAppSecretAndStaysOutOfTheBootConfig(): void
    {
        // Symfony's conventional name, so the IDNO_* sweep into the legacy config (which is
        // saved to the database and shown on the diagnostics page) never picks it up.
        $_SERVER['APP_SECRET'] = 'app-secret-sentinel';
        $kernel = $this->boot();

        $this->assertSame('app-secret-sentinel', $kernel->getContainer()->getParameter('kernel.secret'));
        $boot = $kernel->getContainer()->get(BootConfig::class);
        $this->assertNotContains('app-secret-sentinel', $boot->all());
        $this->assertFalse($boot->has('app_secret'));
        $this->assertFalse($boot->has('kernel_secret'));
    }

    public function testTheKernelSecretHasAHarmlessDefault(): void
    {
        $this->assertNotSame('', (string) $this->boot()->getContainer()->getParameter('kernel.secret'));
    }

    public function testSiteSettingsIsAliasedToTheLegacyImplementation(): void
    {
        $container = $this->boot()->getContainer();

        $this->assertTrue($container->has(SiteSettings::class));
        $this->assertInstanceOf(LegacySiteSettings::class, $container->get(SiteSettings::class));
    }
}
