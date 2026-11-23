<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Which template files core and the bundled plugins have, for the tests that follow the port to Twig: the web and mail
 * `.tpl.php` files (the types `default`, `email` and `email-text`; not `classic`, which stays, and not the feed, JSON, JSONP,
 * ActivityPub and KML types) by logical name `<base>:<type>/<name>` (base `core` or `IdnoPlugins/<Name>`), the Twig files
 * beside them, and the bundled plugins (the ones `.gitignore` makes an exception for: a plugin someone put in
 * IdnoPlugins/ is not bundled).
 */
final class BundledTemplates
{
    public const TYPES = ['default', 'email', 'email-text'];

    /**
     * @return list<string> the directories of core and the bundled plugins: the project directory, then `IdnoPlugins/<Name>`
     */
    public static function bases(string $projectDirectory): array
    {
        $bases = [''];
        foreach (self::plugins($projectDirectory) as $name) {
            $bases[] = 'IdnoPlugins/' . $name;
        }

        return $bases;
    }

    /**
     * @return list<string> the names of the bundled plugins that are there
     */
    public static function plugins(string $projectDirectory): array
    {
        $names = [];
        foreach (file($projectDirectory . '/.gitignore', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('#^!IdnoPlugins/([A-Za-z0-9_]+)/?$#', trim($line), $match) === 1 && is_dir($projectDirectory . '/IdnoPlugins/' . $match[1])) {
                $names[] = $match[1];
            }
        }
        sort($names);

        return $names;
    }

    /**
     * @return list<string> every `.tpl.php` of the three types, by logical name `<base>:<type>/<name>`, sorted
     */
    public static function phpTemplates(string $projectDirectory): array
    {
        $names = [];
        foreach (self::files($projectDirectory, '.tpl.php') as $logical => $path) {
            $names[] = $logical;
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @return array<string, string> every file with the extension, of the three types, by logical name (without the extension): its path
     */
    public static function files(string $projectDirectory, string $extension): array
    {
        $found = [];
        foreach (self::bases($projectDirectory) as $base) {
            $label = $base === '' ? 'core' : $base;
            foreach (self::TYPES as $type) {
                $directory = $projectDirectory . ($base === '' ? '' : '/' . $base) . '/templates/' . $type;
                if (!is_dir($directory)) {
                    continue;
                }
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    $path = $file->getPathname();
                    if ($file->isFile() && str_ends_with($path, $extension)) {
                        $found[$label . ':' . $type . '/' . substr($path, strlen($directory) + 1, -strlen($extension))] = $path;
                    }
                }
            }
        }
        ksort($found, SORT_STRING);

        return $found;
    }

    /**
     * @return array<string, string> the Twig files (`.html.twig`, and `.txt.twig` of the `email-text` type) by logical name: their paths
     */
    public static function twigTemplates(string $projectDirectory): array
    {
        $found = self::files($projectDirectory, '.html.twig');
        foreach (self::files($projectDirectory, '.txt.twig') as $logical => $path) {
            if (str_contains($logical, ':email-text/')) {
                $found[$logical] = $path;
            }
        }
        ksort($found, SORT_STRING);

        return $found;
    }
}
