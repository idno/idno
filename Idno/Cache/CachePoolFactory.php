<?php

declare(strict_types=1);

namespace Idno\Cache;

use Symfony\Component\Cache\Adapter\AdapterInterface;
use Idno\Kernel\CacheDirResolver;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\NullAdapter;

/**
 * Builds the site's one cache pool: a Symfony Cache FilesystemAdapter whose namespace is the
 * site's host, so that two sites sharing a directory never share entries.
 *
 * The directory is the first usable of: the boot configuration's `cachepath`; `<cache base>/pool`
 * (the base is where the compiled container lives, without its per-build directory, so a deploy
 * does not empty the pool); a private directory under the system temporary directory. A directory inside the upload path is
 * never used: a cache does not belong in the uploads.
 */
final class CachePoolFactory
{
    /**
     * @param array<string, mixed> $bootConfig the boot configuration (`cachepath`, `uploadpath`)
     * @param string               $cacheBase  the compiled cache's base directory (`IDNO_CACHE_DIR`, else `var/cache`)
     * @param string               $host       the site's host name
     * @param string|null          $temporaryDirectory the system temporary directory (a test's)
     * @param (callable(): ?int)|null $effectiveUser     the current user's id (a test's); see CacheDirResolver::usableAndPrivate()
     */
    public static function create(array $bootConfig, string $cacheBase, string $host, ?string $temporaryDirectory = null, ?callable $effectiveUser = null): AdapterInterface
    {
        $directory = self::directory($bootConfig, $cacheBase, $temporaryDirectory, $effectiveUser);

        return $directory === null ? new NullAdapter() : new FilesystemAdapter(md5($host), 0, $directory);
    }

    /**
     * The directory under the temporary directory the last-resort pool uses (one per cache base).
     */
    public static function temporaryDirectory(string $cacheBase, ?string $temporaryDirectory = null): string
    {
        return rtrim($temporaryDirectory ?? sys_get_temp_dir(), '/') . '/idno-pool-' . substr(sha1($cacheBase), 0, 12);
    }

    /**
     * @param array<string, mixed> $bootConfig
     */
    public static function directory(array $bootConfig, string $cacheBase, ?string $temporaryDirectory = null, ?callable $effectiveUser = null): ?string
    {
        $uploadPath = $bootConfig['uploadpath'] ?? '';
        $uploadPath = is_string($uploadPath) && $uploadPath !== '' && !str_starts_with($uploadPath, 's3://') ? $uploadPath : '';

        $candidates = [];
        $configured = $bootConfig['cachepath'] ?? '';
        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }
        if ($cacheBase !== '') {
            $candidates[] = rtrim($cacheBase, '/') . '/pool';
        }

        foreach ($candidates as $candidate) {
            if (!self::insideUploads($candidate, $uploadPath) && self::usable($candidate)) {
                return $candidate;
            }
        }

        $fallback = self::temporaryDirectory($cacheBase, $temporaryDirectory);

        return !self::insideUploads($fallback, $uploadPath) && CacheDirResolver::usableAndPrivate($fallback, $effectiveUser, true) ? $fallback : null;
    }

    private static function usable(string $dir): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        return is_writable($dir);
    }

    private static function insideUploads(string $dir, string $uploadPath): bool
    {
        if ($uploadPath === '') {
            return false;
        }
        $upload = rtrim(self::resolved($uploadPath), '/') . '/';

        return str_starts_with(rtrim(self::resolved($dir), '/') . '/', $upload);
    }

    /**
     * The real path of the nearest existing ancestor, with the rest of the path appended.
     */
    private static function resolved(string $path): string
    {
        $rest = '';
        $head = $path;
        while ($head !== '' && $head !== '/' && realpath($head) === false) {
            $rest = '/' . basename($head) . $rest;
            $next = dirname($head);
            if ($next === $head) {
                break;
            }
            $head = $next;
        }
        $real = realpath($head);

        return ($real !== false ? $real : $head) . $rest;
    }
}
