<?php

declare(strict_types=1);

namespace Idno\Caching;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\PruneableInterface;

/**
 * What Idno::site()->cache() returns: the site's one cache pool behind the old cache API
 * (load, store, delete, array access and magic properties), for plugins that use it.
 * New code injects Psr\Cache\CacheItemPoolInterface instead.
 *
 * Any string is a key. PSR-6 refuses keys with the characters `{}()/\@:` (and a length limit),
 * so such a key, an empty one, or a long one is replaced by a hash of it. The pool is fetched
 * at first use, because the adapter is built before the services the pool needs exist.
 *
 * What a caller can rely on: load() returns null on a miss, and returns what was stored otherwise,
 * so a stored false, 0, 0.0, '' and [] come back as they went in; a stored null cannot be told from a
 * miss (it is null either way). isset(), isset($cache[$k]) and offsetExists() are true only for a value
 * that is truthy, as in the cache classes this replaces. Entries are removed when they expire; a pool
 * that can prune is pruned in about one store in 500, so expired entries of keys never read again do
 * not pile up.
 *
 * This is no longer an Idno\Common\Component, and has no size() or getCacheEngine().
 */
final class PersistentCache implements \ArrayAccess
{
    private const HASHED_PREFIX = '_h_';

    private ?CacheItemPoolInterface $pool = null;

    /**
     * @param \Closure(): CacheItemPoolInterface $source     the pool, fetched at first use
     * @param (\Closure(): bool)|null            $shouldPrune says whether this store also prunes; null: one in 500
     */
    public function __construct(private readonly \Closure $source, private readonly ?\Closure $shouldPrune = null)
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

        $saved = $this->pool()->save($item);

        $pool = $this->pool();
        if ($pool instanceof PruneableInterface && ($this->shouldPrune !== null ? ($this->shouldPrune)() : random_int(1, 500) === 1)) {
            $pool->prune();
        }

        return $saved;
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
