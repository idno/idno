<?php

declare(strict_types=1);

namespace Idno\Install;

/**
 * What an earlier site left on this server, read from the project's folders and never from a request. A site whose configuration
 * file was lost is an uninstalled site to anyone who reaches it: without this, a stranger could install a NEW site beside the old
 * one's data (on an empty database of their own) and become its administrator on the owner's server and domain. Where there is
 * any sign, the web installer asks for the code from the server (InstallCode) before it sets up anything.
 *
 * The signs: an SQLite database file that is not empty anywhere under data/; a stored file under the upload folder (anything but
 * the readme and the .htaccess the project ships there, in any letter case); a file in configuration/ other than the .htaccess the
 * project ships and the install code itself. A stray .DS_Store or .gitkeep is no sign anywhere. A checkout as shipped has none.
 *
 * A site whose data is only remote (a MySQL server, an s3:// upload path) leaves nothing on this server's disk, so it shows no sign
 * and this rule cannot tell it from a new site.
 */
final class EarlierSite
{
    public const DATABASE = 'database';
    public const UPLOADS = 'uploads';
    public const CONFIGURATION = 'configuration';

    /** The files the project ships in the upload folder and in configuration/, which are no sign */
    private const SHIPPED_UPLOADS = ['readme.txt', '.htaccess'];
    private const SHIPPED_CONFIGURATION = ['.htaccess', InstallCode::FILE_NAME];

    /** Files an operating system or a version control tool leaves in a folder: no sign of a site, in any folder */
    private const STRAY = ['.DS_Store', '.gitkeep'];

    /**
     * @return list<string> the signs found, in the order DATABASE, UPLOADS, CONFIGURATION
     */
    public static function signs(InstallPaths $paths): array
    {
        $signs = [];
        foreach (self::files($paths->dataDir()) as $relative => $size) {
            if ($size > 0 && preg_match('/\.sqlite\z/i', $relative) === 1) {
                $signs[] = self::DATABASE;
                break;
            }
        }
        foreach (self::files($paths->uploadDir()) as $relative => $size) {
            if (!self::ignored($relative, self::SHIPPED_UPLOADS)) {
                $signs[] = self::UPLOADS;
                break;
            }
        }
        foreach (self::files($paths->configDir(), false) as $relative => $size) {
            if (!self::ignored($relative, self::SHIPPED_CONFIGURATION)) {
                $signs[] = self::CONFIGURATION;
                break;
            }
        }

        return $signs;
    }

    /**
     * Whether a file is one the project ships there (the whole relative path, ignoring letter case: a file system may not keep it)
     * or a stray one (by its own name, at any depth).
     *
     * @param list<string> $shipped
     */
    private static function ignored(string $relative, array $shipped): bool
    {
        foreach ($shipped as $name) {
            if (strcasecmp($relative, $name) === 0) {
                return true;
            }
        }
        foreach (self::STRAY as $name) {
            if (strcasecmp(basename($relative), $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The signs in words a visitor may read: folders of the project, never a file's name or the server's path.
     *
     * @param list<string> $signs
     */
    public static function describe(array $signs): string
    {
        $words = [
            self::DATABASE => 'a database file in the folder data',
            self::UPLOADS => 'uploaded files in the folder Uploads',
            self::CONFIGURATION => 'other files in the folder configuration',
        ];

        return implode(', ', array_map(static fn (string $sign): string => $words[$sign] ?? $sign, $signs));
    }

    /**
     * The files under a folder (relative path => size), every level or the top only; nothing when the folder is not there.
     *
     * @return \Generator<string, int>
     */
    private static function files(string $directory, bool $deep = true): \Generator
    {
        if (!is_dir($directory)) {
            return;
        }
        if (!$deep) {
            foreach (scandir($directory) ?: [] as $name) {
                if ($name !== '.' && $name !== '..' && !is_dir($directory . '/' . $name)) {
                    yield $name => (int) @filesize($directory . '/' . $name);
                }
            }

            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                yield substr($file->getPathname(), strlen($directory) + 1) => (int) $file->getSize();
            }
        }
    }
}
