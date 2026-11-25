<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

/**
 * A project root of a test's own in the temporary directory: the folders an installation has and a
 * copy of the configuration template, removed again by removeRoot().
 */
trait TemporaryRoot
{
    private string $root = '';

    private function makeRoot(): string
    {
        $this->root = sys_get_temp_dir() . '/idno-install-test-' . bin2hex(random_bytes(6));
        foreach (['configuration', 'data', 'Uploads', 'warmup/webserver-configs'] as $directory) {
            mkdir($this->root . '/' . $directory, 0755, true);
        }
        copy(dirname(__DIR__, 3) . '/warmup/webserver-configs/config.ini.template', $this->root . '/warmup/webserver-configs/config.ini.template');

        return $this->root;
    }

    private function removeRoot(): void
    {
        if ($this->root !== '') {
            self::removeTree($this->root);
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            chmod($path, 0755);
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /**
     * Every file under the root except the configuration template, relative to it, sorted.
     *
     * @return string[]
     */
    private function filesInRoot(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr((string) $file, strlen($this->root) + 1);
            if (!str_starts_with($relative, 'warmup/')) {
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }
}
