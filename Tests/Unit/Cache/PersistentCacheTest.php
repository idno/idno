<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use Idno\Caching\PersistentCache;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

final class PersistentCacheTest extends TestCase
{
    private function cache(?MockClock $clock = null, ?ArrayAdapter &$pool = null): PersistentCache
    {
        $pool = new ArrayAdapter(0, true, 0, 0, $clock);

        return new PersistentCache(static fn () => $pool);
    }

    public function testStoreLoadDeleteRoundTrip(): void
    {
        $cache = $this->cache();
        $object = new \ArrayObject(['a' => 1]);

        $this->assertTrue($cache->store('string', 'text'));
        $this->assertTrue($cache->store('array', ['a' => [1, 2]]));
        $this->assertTrue($cache->store('object', $object));

        $this->assertSame('text', $cache->load('string'));
        $this->assertSame(['a' => [1, 2]], $cache->load('array'));
        $this->assertEquals($object, $cache->load('object'));

        $this->assertTrue($cache->delete('string'));
        $this->assertNull($cache->load('string'));
    }

    public function testAMissIsNull(): void
    {
        $this->assertNull($this->cache()->load('nothing-here'));
    }

    public function testArrayAccessAndMagicAccessorsWork(): void
    {
        $cache = $this->cache();

        $cache['one'] = 1;
        $cache->two = 2;

        $this->assertSame(1, $cache['one']);
        $this->assertSame(2, $cache->two);
        $this->assertTrue(isset($cache['one']));
        $this->assertTrue(isset($cache->two));
        unset($cache['one']);
        unset($cache->two);
        $this->assertNull($cache['one']);
        $this->assertFalse(isset($cache->two));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function awkwardKeys(): array
    {
        return [
            'a URL with reserved characters' => ['https://example.com/@a:b'],
            'braces and parentheses' => ['{a}(b)\\c'],
            'a very long key' => [str_repeat('long', 200)],
            'a key that looks like a hashed one' => ['_h_' . str_repeat('a', 64)],
            'an empty key' => [''],
            'spaces' => ['a key with spaces'],
        ];
    }

    /**
     * @dataProvider awkwardKeys
     */
    public function testAnyStringIsAKey(string $key): void
    {
        $cache = $this->cache();

        $this->assertTrue($cache->store($key, 'value'));
        $this->assertSame('value', $cache->load($key));
        $this->assertTrue($cache->delete($key));
        $this->assertNull($cache->load($key));
    }

    public function testDistinctKeysStayDistinct(): void
    {
        $cache = $this->cache();
        $cache->store('a:b', 1);
        $cache->store('a/b', 2);
        $cache->store('a@b', 3);

        $this->assertSame([1, 2, 3], [$cache->load('a:b'), $cache->load('a/b'), $cache->load('a@b')]);
    }

    public function testASimpleKeyIsUsedAsItIs(): void
    {
        $pool = null;
        $cache = $this->cache(null, $pool);
        $cache->store('example.com', 'v');

        $this->assertTrue($pool->getItem('example.com')->isHit());
    }

    public function testAnEntryExpiresAfterItsTtl(): void
    {
        $clock = new MockClock('2026-01-01 00:00:00');
        $cache = $this->cache($clock);

        $cache->store('short', 'v', 60);
        $cache->store('forever', 'v');

        $clock->sleep(59);
        $this->assertSame('v', $cache->load('short'));
        $clock->sleep(2);
        $this->assertNull($cache->load('short'));
        $this->assertSame('v', $cache->load('forever'));
    }

    public function testThePoolIsFetchedOnlyAtFirstUse(): void
    {
        $fetched = 0;
        $cache = new PersistentCache(static function () use (&$fetched) {
            $fetched++;

            return new ArrayAdapter();
        });

        $this->assertSame(0, $fetched);
        $cache->load('x');
        $cache->store('x', 1);
        $this->assertSame(1, $fetched);
    }
}
