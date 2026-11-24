<?php

declare(strict_types=1);

namespace Idno\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Limits failed logins: LIMIT failures per WINDOW_SECONDS for each login name (what was typed in the login field,
 * trimmed and lower-cased), counted by symfony/rate-limiter's fixed window on the site's one cache pool.
 *
 * The count is kept under a hash of the login name, so the name is never a cache key or file name. It is for a
 * login name, not an address: behind a reverse proxy every client shares the proxy's address, and Idno has no
 * trusted proxies yet. A handle and the email address of the same account are two names with a count each.
 *
 * Fixed window: the window opens at the first failure and its count is forgotten WINDOW_SECONDS later, so
 * an attacker can keep an account refused for no longer than that after the last attempt that is counted (a
 * refused attempt is never counted). The limiter reads the real clock and takes no lock (it is given none: a
 * lock would need a store of its own), so concurrent attempts can let a few more than LIMIT through. With the
 * cache disabled (no pool is usable), nothing is stored and nothing is ever refused.
 */
final class LoginThrottle
{
    public const LIMIT = 5;
    public const WINDOW_SECONDS = 300;

    private readonly RateLimiterFactory $factory;

    public function __construct(CacheItemPoolInterface $pool)
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
     * The key a login name is counted under.
     */
    public static function keyFor(string $login): string
    {
        return hash('sha256', mb_strtolower(trim($login)));
    }

    /**
     * Whether an attempt for this login name may go on to the password check. Uses nothing up.
     */
    public function allows(string $login): bool
    {
        return $this->factory->create(self::keyFor($login))->consume(0)->getRemainingTokens() > 0;
    }

    /**
     * Counts a failed attempt.
     */
    public function recordFailure(string $login): void
    {
        $this->factory->create(self::keyFor($login))->consume(1);
    }

    /**
     * Forgets the count (a successful login).
     */
    public function reset(string $login): void
    {
        $this->factory->create(self::keyFor($login))->reset();
    }
}
