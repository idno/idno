<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Caching\PersistentCache;
use Idno\Core\Idno;
use Idno\Legacy\LegacyServiceFactory;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Idno::site()->cache() is the old cache API over the container's one cache pool.
 */
final class CacheAdapterTest extends TestCase
{
    public function testTheContainerHasOnePoolForBothInterfaces(): void
    {
        $pool = Idno::container()->get(CacheItemPoolInterface::class);

        $this->assertSame($pool, Idno::container()->get(CacheInterface::class));
        $this->assertInstanceOf(\Symfony\Component\Cache\Adapter\FilesystemAdapter::class, $pool);
    }

    public function testSiteCacheIsTheAdapterOverTheContainersPool(): void
    {
        $cache = Idno::site()->cache();
        $pool = Idno::container()->get(CacheItemPoolInterface::class);
        $key = 'adapter-test-' . bin2hex(random_bytes(4));

        $this->assertInstanceOf(PersistentCache::class, $cache);
        $this->assertSame($cache, Idno::container()->get(PersistentCache::class));

        $this->assertTrue($cache->store($key, ['a' => 1], 60));
        $this->assertSame(['a' => 1], $pool->getItem($key)->get());

        $pool->save($pool->getItem($key)->set('from the pool'));
        $this->assertSame('from the pool', $cache->load($key));

        $cache->delete($key);
        $this->assertFalse($pool->hasItem($key));
    }

    public function testAKeyWithReservedCharactersWorksOnTheSitesPool(): void
    {
        $cache = Idno::site()->cache();
        $key = 'https://example.com/@a:b';

        $this->assertTrue($cache->store($key, 'v'));
        $this->assertSame('v', $cache->load($key));
        $cache->delete($key);
        $this->assertNull($cache->load($key));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function removedSettings(): array
    {
        return [
            'APCuCache' => ['APCuCache'],
            'ArrayCache' => ['ArrayCache'],
            'a class that does not exist' => ['Vendor\\Missing\\MyCache'],
        ];
    }

    /**
     * @dataProvider removedSettings
     */
    public function testTheCacheSettingIsIgnoredWithOneWarning(string $setting): void
    {
        $config = Idno::site()->config();
        $previous = $config->cache;
        $log = tempnam(sys_get_temp_dir(), 'idno-cache-log');
        $previousLog = ini_set('error_log', $log);
        try {
            $config->cache = $setting;
            $pool = new ArrayAdapter();
            $factory = new LegacyServiceFactory(cachePool: static fn () => $pool);
            $cache = $factory->cache();

            $this->assertSame('', (string) file_get_contents($log), 'nothing is logged until the cache is used');

            $cache->store('k', 'v');
            $cache->load('k');
            $cache->store('k2', 'v');

            $this->assertSame('v', $pool->getItem('k')->get(), 'the setting does not change which pool is used');
            $lines = array_values(array_filter(explode("\n", (string) file_get_contents($log)), static fn (string $line): bool => str_contains($line, 'cache" setting')));
            $this->assertCount(1, $lines);
            $this->assertStringContainsString('warning', $lines[0]);
            $this->assertStringContainsString($setting, $lines[0]);
        } finally {
            $config->cache = $previous;
            ini_set('error_log', (string) $previousLog);
            @unlink($log);
        }
    }

    public function testNoSettingMeansNoWarning(): void
    {
        $config = Idno::site()->config();
        $previous = $config->cache;
        $log = tempnam(sys_get_temp_dir(), 'idno-cache-log');
        $previousLog = ini_set('error_log', $log);
        try {
            $config->cache = null;
            $factory = new LegacyServiceFactory(cachePool: static fn () => new ArrayAdapter());
            $factory->cache()->store('k', 'v');

            $this->assertStringNotContainsString('cache" setting', (string) file_get_contents($log));
        } finally {
            $config->cache = $previous;
            ini_set('error_log', (string) $previousLog);
            @unlink($log);
        }
    }
}
