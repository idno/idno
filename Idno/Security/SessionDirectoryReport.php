<?php

declare(strict_types=1);

namespace Idno\Security;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Whether the line saying a session directory cannot be used is logged. A web request is a process of its own, so "once" needs a memory that
 * outlives it: the site's own cache pool (private to the site's user, unlike the shared temporary directory), a key made of a hash of the setting
 * and the problem, kept for an hour. The pool is asked for only when there is a problem to report, and through a closure, because the session
 * starts before the logger and the cache exist. A pool that is missing, disabled or failing is never an error: the line is then said once for
 * the request.
 */
final class SessionDirectoryReport
{
    public const SECONDS = 3600;

    /** @var array<string, true> the lines this object has already let out */
    private array $said = [];

    /**
     * @param \Closure(): ?CacheItemPoolInterface $pool
     */
    public function __construct(private readonly \Closure $pool)
    {
    }

    public function shouldSay(string $setting, string $problem): bool
    {
        $key = 'session_directory_' . hash('sha256', $setting . "\0" . $problem);
        if (isset($this->said[$key])) {
            return false;
        }
        $this->said[$key] = true;

        try {
            $pool = ($this->pool)();
            if ($pool === null) {
                return true;
            }
            $item = $pool->getItem($key);
            if ($item->isHit()) {
                return false;
            }
            $pool->save($item->set(true)->expiresAfter(self::SECONDS));
        } catch (\Throwable) {
            // never an exception, never a blocked sign-in
        }

        return true;
    }
}
