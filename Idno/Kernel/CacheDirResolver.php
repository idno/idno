<?php

declare(strict_types=1);

namespace Idno\Kernel;

/**
 * Chooses the directory the Symfony kernel uses for its compiled cache and logs.
 */
final class CacheDirResolver
{
    /**
     * Returns the first usable of: the override, "$projectDir/var/cache", and a
     * per-project directory under the system temp dir. "Usable" means it exists
     * and is writable, or can be created.
     */
    public static function resolve(string $projectDir, ?string $override): string
    {
        $candidates = [];
        if ($override !== null && $override !== '') {
            $candidates[] = $override;
        }
        $candidates[] = $projectDir . '/var/cache';
        $fallback = sys_get_temp_dir() . '/idno-' . substr(sha1($projectDir), 0, 12);
        $candidates[] = $fallback;

        foreach ($candidates as $candidate) {
            if (self::usable($candidate)) {
                return $candidate;
            }
        }

        return $fallback;
    }

    private static function usable(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }

        return @mkdir($dir, 0775, true) || is_dir($dir);
    }
}
