<?php

declare(strict_types=1);

namespace Idno\Install;

/**
 * The proof that whoever asks the web installer to take over a database that already holds an Idno
 * site has access to the server's files. A site whose configuration file is lost but whose data is
 * there is reachable by anyone as an uninstalled site, and the installer writes the site's address:
 * without this, a stranger could adopt the database and give it an address of their own.
 *
 * The installer writes a random code to configuration/install-code (mode 0600; the shipped web
 * server configurations refuse the folder, which also refuses everything itself), and the page asks
 * for it. The code is compared in constant time, lasts LIFETIME seconds from when it was written,
 * and is removed once the site is adopted. It is not replaced while it is valid, so a visitor who
 * reaches the page again cannot invalidate the code the person at the server is reading. It is never
 * printed, logged, kept in a cookie or put in an address.
 *
 * It has nothing to do with the setup token (SetupToken: the browser that installed a site creates its
 * first administrator): another file, another length (32 hex characters, never 64), and neither class
 * accepts the other's value.
 */
final class InstallCode
{
    /** One hour. */
    public const LIFETIME = 3600;

    public const FILE_NAME = 'install-code';

    public function __construct(private readonly InstallPaths $paths)
    {
    }

    /**
     * The file, named from the project: what the page tells the person to read.
     */
    public static function relativePath(): string
    {
        return 'configuration/' . self::FILE_NAME;
    }

    /**
     * Writes a new code when there is none, or the one there has expired. The file is replaced under an exclusive lock and read
     * again under it, so requests that find the code expired at the same moment replace it once: the second keeps the code the
     * first wrote (which the person at the server may be reading). A configuration folder that is not there (it was lost with the
     * configuration) is created, with the .htaccess that refuses everything in it.
     *
     * @return bool Whether a valid code is there afterwards (false when the folder cannot be written to)
     */
    public function ensure(): bool
    {
        if ($this->valid() !== null) {
            return true;
        }
        $directory = $this->paths->configDir();
        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
                return false;
            }
            @chmod($directory, 0755);
            if (!file_exists($directory . '/.htaccess')) {
                @file_put_contents($directory . '/.htaccess', "Require all denied\n");
            }
        }
        $file = $this->file();
        $old = umask(0077);
        try {
            $handle = @fopen($file, 'c+');
        } finally {
            umask($old);
        }
        if ($handle === false) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            if ($this->valid() !== null) {
                return true;
            }
            $code = bin2hex(random_bytes(16));
            ftruncate($handle, 0);
            rewind($handle);
            $written = fwrite($handle, $code . "\n");
            fflush($handle);
            @chmod($file, 0600);
            touch($file);
            clearstatcache(true, $file);

            return $written === strlen($code) + 1;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Whether this is the code in the file, while it is valid. Spaces and letter case are ignored
     * (the person copies it from a terminal); anything else is not the code.
     */
    public function matches(mixed $typed): bool
    {
        if (!is_string($typed)) {
            return false;
        }
        $typed = strtolower((string) preg_replace('/\s+/', '', $typed));
        if (preg_match('/\A[a-f0-9]{32}\z/', $typed) !== 1) {
            return false;
        }
        $code = $this->valid();

        return $code !== null && hash_equals($code, $typed);
    }

    /**
     * Removes the code: it has been used.
     */
    public function clear(): void
    {
        @unlink($this->file());
    }

    private function file(): string
    {
        return $this->paths->configDir() . '/' . self::FILE_NAME;
    }

    /**
     * The code in the file, or null when there is none, it cannot be read, it is not one, or it has expired.
     */
    private function valid(): ?string
    {
        $file = $this->file();
        clearstatcache(true, $file);
        $written = @filemtime($file);
        $now = time();
        if ($written === false || $written + self::LIFETIME <= $now || $written > $now + 60) {
            return null;
        }
        $code = trim((string) @file_get_contents($file));

        return preg_match('/\A[a-f0-9]{32}\z/', $code) === 1 ? $code : null;
    }
}
