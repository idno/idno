<?php

declare(strict_types=1);

namespace Idno\Caching;

use Psr\Cache\CacheItemPoolInterface;

/**
 * What Idno::site()->cache() returns: the site's one cache pool behind the old cache API
 * (load, store, delete, array access and magic properties), for plugins that use it.
 * New code injects Psr\Cache\CacheItemPoolInterface instead.
 *
 * Any string is a key. PSR-6 refuses keys with the characters `{}()/\@:` (and a length limit),
 * so such a key, an empty one, or a long one is replaced by a hash of it. The pool is fetched
 * at first use, because the adapter is built before the services the pool needs exist.
 */
final class PersistentCache implements \ArrayAccess
{
    private const HASHED_PREFIX = '_h_';

    private ?CacheItemPoolInterface $pool = null;

    /**
     * @param \Closure(): CacheItemPoolInterface $pool
     */
    public function __construct(private readonly \Closure $source)
    {
    }

    /**
     * Retrieve a value from the cache; null on a miss.
     */
    public function load(mixed $key): mixed
    {
        $item = $this->pool()->getItem(self::key($key));

        return $item->isHit() ? $item->get() : null;
    }

    /**
     * Store or replace a value; a ttl is in seconds (none: until deleted).
     */
    public function store(mixed $key, mixed $value, ?int $ttl = null): bool
    {
        $item = $this->pool()->getItem(self::key($key));
        $item->set($value);
        $item->expiresAfter($ttl);

        return $this->pool()->save($item);
    }

    public function delete(mixed $key): bool
    {
        return $this->pool()->deleteItem(self::key($key));
    }

    public function __isset(string $key): bool
    {
        return (bool) $this->load($key);
    }

    public function __unset(string $key): void
    {
        $this->delete($key);
    }

    public function __get(string $key): mixed
    {
        return $this->load($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->store($key, $value);
    }

    public function offsetGet(mixed $key): mixed
    {
        return $this->load($key);
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        $this->store($key, $value);
    }

    public function offsetExists(mixed $key): bool
    {
        return (bool) $this->load($key);
    }

    public function offsetUnset(mixed $key): void
    {
        $this->delete($key);
    }

    private function pool(): CacheItemPoolInterface
    {
        return $this->pool ??= ($this->source)();
    }

    private static function key(mixed $key): string
    {
        $key = (string) $key;
        if (preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $key) === 1 && !str_starts_with($key, self::HASHED_PREFIX)) {
            return $key;
        }

        return self::HASHED_PREFIX . hash('sha256', $key);
    }
}
