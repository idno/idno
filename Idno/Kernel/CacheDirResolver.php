<?php

declare(strict_types=1);

namespace Idno\Kernel;

/**
 * Chooses the directory the Symfony kernel uses for its compiled cache and logs.
 *
 * The compiled container is PHP that the kernel includes, so a directory that
 * another user could write to must never be returned.
 */
final class CacheDirResolver
{
    /**
     * Returns the first usable of: the override, "$projectDir/var/cache", and a
     * per-project directory under the system temp dir. "Usable" means it exists
     * and is writable, or can be created. The temp-dir fallback must also be owned
     * by the current user and not writable by group or others.
     *
     * @throws \RuntimeException when none of the locations is usable
     */
    public static function resolve(string $projectDir, ?string $override): string
    {
        $tried = [];

        if ($override !== null && $override !== '') {
            $tried[] = $override;
            if (self::usable($override)) {
                return $override;
            }
        }

        $projectCache = $projectDir . '/var/cache';
        $tried[] = $projectCache;
        if (self::usable($projectCache)) {
            return $projectCache;
        }

        $fallback = sys_get_temp_dir() . '/idno-' . substr(sha1($projectDir), 0, 12);
        $tried[] = $fallback;
        if (self::usableAndPrivate($fallback)) {
            return $fallback;
        }

        throw new \RuntimeException(sprintf(
            'No usable cache directory. Tried: %s. Set IDNO_CACHE_DIR to a directory writable by the web server user.',
            implode(', ', $tried)
        ));
    }

    private static function usable(string $dir): bool
    {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return false;
            }
        }

        return is_writable($dir);
    }

    private static function usableAndPrivate(string $dir): bool
    {
        if (!file_exists($dir) && !is_link($dir)) {
            // The predictable path may be created by someone else between the check and mkdir;
            // the ownership and mode checks below cover that.
            @mkdir($dir, 0700, true);
        }

        if (is_link($dir) || !is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        if (function_exists('posix_geteuid') && fileowner($dir) !== posix_geteuid()) {
            return false;
        }

        $perms = fileperms($dir);

        return $perms !== false && ($perms & 0022) === 0;
    }
}
