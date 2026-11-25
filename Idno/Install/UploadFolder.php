<?php

declare(strict_types=1);

namespace Idno\Install;

/**
 * The folder an installation keeps its uploaded files in. Under the project it is inside the web
 * server's document root, and a stored file reached by its path there would skip the file page (and
 * with it a post's access rules and the administrators-only files). The shipped web server
 * configurations refuse the folder; an Apache server also obeys the folder's own `.htaccess`, which
 * refuses everything in it, even where the rewrite rules are not in force.
 */
final class UploadFolder
{
    public const HTACCESS = "Require all denied\n";

    /**
     * A path the installer's check page asks for over HTTP: a shipped file whose text holds
     * {@see self::PROBE_MARKER}, so that an answer of the site's own pages is not mistaken for it.
     */
    public const PROBE = 'Uploads/readme.txt';

    public const PROBE_MARKER = 'idno-uploads-probe';

    /**
     * Writes the folder's `.htaccess` when it has none. Nothing is replaced, and a folder that is not
     * there is left alone.
     *
     * @return bool Whether the folder has the file afterwards
     */
    public static function protect(string $directory): bool
    {
        $directory = rtrim($directory, '/\\');
        if (!is_dir($directory)) {
            return false;
        }
        $file = $directory . '/.htaccess';
        if (file_exists($file)) {
            return true;
        }

        return @file_put_contents($file, self::HTACCESS) === strlen(self::HTACCESS);
    }

    /**
     * Whether a folder is inside the project, where the web server serves files.
     */
    public static function isUnderRoot(string $directory, string $root): bool
    {
        $real = realpath($directory);
        $realRoot = realpath($root);

        return $real !== false && $realRoot !== false && str_starts_with($real . '/', rtrim($realRoot, '/') . '/');
    }
}
