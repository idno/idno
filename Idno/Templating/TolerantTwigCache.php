<?php

declare(strict_types=1);

namespace Idno\Templating;

use Psr\Log\LoggerInterface;
use Twig\Cache\CacheInterface;
use Twig\Cache\FilesystemCache;
use Twig\Cache\RemovableCacheInterface;

/**
 * Twig's file cache that survives a directory it cannot write.
 *
 * Twig's own cache throws when it cannot create or write its directory, so a site whose cache directory belongs to
 * another user (a deploy run as root, a read-only file system) could draw no page that holds a Twig template. This one
 * logs ONE warning for the process and goes on: Twig then evaluates the compiled template in memory, which is slower
 * (every request compiles what it draws) and otherwise the same. `php bin/console cache:warmup`, run as the web
 * server's user, writes them ahead of time.
 */
final class TolerantTwigCache implements CacheInterface, RemovableCacheInterface
{
    private readonly FilesystemCache $files;

    private bool $warned = false;

    /**
     * @param \Closure(): LoggerInterface $logger fetched when the first write fails (the site's logger needs the legacy application)
     */
    public function __construct(private readonly string $directory, private readonly \Closure $logger)
    {
        $this->files = new FilesystemCache($directory);
    }

    /**
     * Whether a write has failed in this process (the directory could not be made or written).
     */
    public function hasFailedToWrite(): bool
    {
        return $this->warned;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function generateKey(string $name, string $className): string
    {
        return $this->files->generateKey($name, $className);
    }

    public function write(string $key, string $content): void
    {
        try {
            $this->files->write($key, $content);
        } catch (\RuntimeException) {
            if (!$this->warned) {
                $this->warned = true;
                try {
                    ($this->logger)()->warning('Twig could not write its compiled template cache: ' . $this->directory);
                } catch (\Throwable) {
                    // the site's logger needs the legacy application, which is not booted in every process that compiles a template
                }
            }
        }
    }

    public function load(string $key): void
    {
        $this->files->load($key);
    }

    public function getTimestamp(string $key): int
    {
        return $this->files->getTimestamp($key);
    }

    public function remove(string $name, string $cls): void
    {
        try {
            $this->files->remove($name, $cls);
        } catch (\RuntimeException) {
            // nothing to remove that this process could remove
        }
    }
}
