<?php

declare(strict_types=1);

namespace Idno\Kernel;

/**
 * Identifies the code the compiled container was built from, so that the container is
 * recompiled whenever that code changes, with no cache to clear by hand.
 *
 * It hashes each input file's project-relative path, size and modification time, never
 * its contents, because it runs on every request.
 */
final class CodeFingerprint
{
    /**
     * The directories under Idno/ that the container scans for services (config/services.php).
     * This is the one place the list is defined; add a directory here when the container
     * scans it for services or config/routes.php imports attribute routes from it.
     *
     * @var list<string>
     */
    public const SERVICE_DIRECTORIES = ['Kernel', 'Config', 'Http', 'Console', 'Legacy'];

    /**
     * A short, stable identifier for the code the container is compiled from:
     * 12 lowercase hex characters. Inputs are version.idno, Idno/Kernel.php, the PHP files in config/
     * (except the generated config/reference.php), the PHP files in each scanned
     * directory (unreadable ones are skipped), and vendor/composer/installed.php. Missing inputs are skipped.
     *
     * PHP caches stat results within a process; a long-running caller that expects to
     * see edits made during its own lifetime must call clearstatcache() first.
     */
    public static function compute(string $projectDir): string
    {
        $entries = [];

        foreach (['version.idno', 'Idno/Kernel.php', 'vendor/composer/installed.php'] as $file) {
            self::add($entries, $projectDir, $file);
        }

        $directories = ['config'];
        foreach (self::SERVICE_DIRECTORIES as $dir) {
            $directories[] = 'Idno/' . $dir;
        }

        foreach ($directories as $relative) {
            $root = $projectDir . '/' . $relative;
            if (!is_dir($root)) {
                continue;
            }
            try {
                // CATCH_GET_CHILD skips a subdirectory that cannot be read instead of throwing.
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                    \RecursiveIteratorIterator::CATCH_GET_CHILD
                );
            } catch (\UnexpectedValueException) {
                continue; // The root itself is unreadable: it contributes nothing.
            }
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = $relative . substr($file->getPathname(), strlen($root));
                if ($path === 'config/reference.php') {
                    continue;
                }
                self::add($entries, $projectDir, $path);
            }
        }

        ksort($entries);

        return substr(sha1(implode("\n", $entries)), 0, 12);
    }

    /**
     * @param array<string, string> $entries
     */
    private static function add(array &$entries, string $projectDir, string $relative): void
    {
        $file = $projectDir . '/' . $relative;
        if (!is_file($file)) {
            return;
        }
        $size = @filesize($file);
        $mtime = @filemtime($file);
        if ($size === false || $mtime === false) {
            return;
        }
        $entries[$relative] = $relative . '|' . $size . '|' . $mtime;
    }
}
