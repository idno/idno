<?php

declare(strict_types=1);

namespace Idno\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Limits logins: LIMIT attempts per WINDOW_SECONDS for each KEY, counted by symfony/rate-limiter's fixed window on the
 * site's one cache pool. The login page makes the key: the uuid of the account when the name typed resolves to one
 * (keyForAccount(): the handle and the email address of one account share a count, however they are spelled), else
 * the name typed, folded (keyForUnknownName()). A key is a hash: a name is never a cache key or file name.
 *
 * attempt() reserves an attempt BEFORE the password is checked and says whether it may go on, so parallel requests cannot all
 * get a guess while the first is still being checked; a successful login calls reset(). The window opens at the first
 * attempt and its count is forgotten WINDOW_SECONDS later, so an attacker can keep an account refused for no longer than that
 * after the last attempt that is counted (a refused attempt is not counted). The limiter reads the real clock and takes
 * no lock (a lock would need a store of its own): concurrent cache writes can lose a few increments, so a burst can get a
 * few more than LIMIT attempts through. With the cache disabled (no pool is usable) nothing is stored and nothing is
 * ever refused. About once in every few hundred attempts the pool is pruned (when it can be), so the entries of names
 * never seen again do not pile up.
 */
final class LoginThrottle
{
    public const LIMIT = 5;
    public const WINDOW_SECONDS = 300;

    /** One attempt in this many (about) prunes the pool. */
    public const PRUNE_ONE_IN = 300;

    private readonly RateLimiterFactory $factory;

    /**
     * @param (\Closure(int, int): int)|null $random the random_int() the pruning decision uses (a test's)
     */
    public function __construct(
        private readonly CacheItemPoolInterface $pool,
        private readonly ?\Closure $random = null,
    )
    {
        $this->factory = new RateLimiterFactory(
            [
                'id' => 'idno_login',
                'policy' => 'fixed_window',
                'limit' => self::LIMIT,
                'interval' => self::WINDOW_SECONDS . ' seconds',
            ],
            new CacheStorage($pool)
        );
    }

    /**
     * The key of an account: its uuid.
     */
    public static function keyForAccount(string $uuid): string
    {
        return hash('sha256', 'account:' . $uuid);
    }

    /**
     * The key of a login name that no account has: the name trimmed, in Unicode normalisation form NFKC when the intl
     * extension is there, and lower-cased.
     */
    public static function keyForUnknownName(string $name): string
    {
        $name = trim($name);
        if (class_exists(\Normalizer::class)) {
            $name = \Normalizer::normalize($name, \Normalizer::FORM_KC) ?: $name;
        }

        return hash('sha256', 'name:' . mb_strtolower($name));
    }

    /**
     * Reserves an attempt for the key: true when it may go on to the password check, false when the key has used
     * them all in this window.
     */
    public function attempt(string $key): bool
    {
        $accepted = $this->factory->create($key)->consume(1)->isAccepted();
        $this->maybePrune();

        return $accepted;
    }

    /**
     * Forgets the count (a successful login, a password reset).
     */
    public function reset(string $key): void
    {
        $this->factory->create($key)->reset();
    }

    private function maybePrune(): void
    {
        if ($this->pool instanceof PruneableInterface && ($this->random ?? random_int(...))(1, self::PRUNE_ONE_IN) === 1) {
            $this->pool->prune();
        }
    }
}
