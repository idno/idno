<?php

declare(strict_types=1);

namespace Idno\Storage;

use Idno\Storage\Attribute\AsEntity;
use Idno\Storage\Attribute\IndexedField;

/**
 * Finds the classes in a directory that carry #[AsEntity] or #[IndexedField], for
 * IndexedFields::reconcileDirectory().
 *
 * Every PHP file under the directory (recursively) with a line that starts (after any indentation)
 * with one of the attributes, written imported (`#[AsEntity(`) or fully qualified
 * (`#[\Idno\Storage\Attribute\AsEntity(`), is a candidate: a mention in a comment or docblock
 * does not make it one. Its class name comes from its path, as the PSR-4 autoloader maps it: the path under
 * the project's directory of the namespace (Idno/, IdnoPlugins/, ...), or, for a directory outside
 * the project (a plugin in the site's external plugin path), under its nearest enclosing directory
 * named Idno or IdnoPlugins. The class is loaded through the autoloader (never `require`), and
 * listed only when reflection confirms that it carries one of the attributes itself. A file that
 * fails to load, or that does not define the class its path names, is skipped.
 *
 * A static helper, not a container service (see config/services.php).
 */
final class EntityClassFinder
{
    /** The text that makes a file a candidate: the attribute at the start of a line (after any indentation). */
    private const ATTRIBUTE_TEXT = '/^[ \t]*#\[\s*(?:\\\\?Idno\\\\Storage\\\\Attribute\\\\)?(?:AsEntity|IndexedField)\b/m';

    /** The namespaces whose directories are named after them, for a directory outside the project. */
    private const ROOT_NAMESPACES = ['Idno', 'IdnoPlugins'];

    /**
     * @return list<class-string> the classes under $directory carrying #[AsEntity] or #[IndexedField], sorted
     */
    public static function in(string $directory): array
    {
        $directory = realpath($directory);
        if ($directory === false || !is_dir($directory)) {
            return [];
        }
        $root = self::namespaceRoot($directory);
        if ($root === null) {
            return [];
        }

        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $text = @file_get_contents($file->getPathname());
            if (!is_string($text) || preg_match(self::ATTRIBUTE_TEXT, $text) !== 1) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            if (self::declaresAttribute($class)) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    /**
     * The directory the class names under $directory are relative to: the project's directory
     * when $directory is inside it, else the parent of its nearest enclosing directory named after
     * a root namespace; null when there is none.
     */
    private static function namespaceRoot(string $directory): ?string
    {
        $project = realpath(dirname(__DIR__, 2));
        if ($project !== false && str_starts_with($directory . DIRECTORY_SEPARATOR, $project . DIRECTORY_SEPARATOR) && $directory !== $project) {
            return $project;
        }
        for ($path = $directory; dirname($path) !== $path; $path = dirname($path)) {
            if (in_array(basename($path), self::ROOT_NAMESPACES, true)) {
                return dirname($path);
            }
        }

        return null;
    }

    /**
     * Whether the class loads and carries one of the attributes itself.
     */
    private static function declaresAttribute(string $class): bool
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $class) !== 1) {
            return false;
        }
        try {
            if (!class_exists($class)) {
                return false;
            }
            $reflection = new \ReflectionClass($class);
        } catch (\Throwable) {
            // A file that fails to load (a missing parent class, a syntax error, a warning turned into an exception).
            return false;
        }

        return $reflection->getAttributes(AsEntity::class) !== [] || $reflection->getAttributes(IndexedField::class) !== [];
    }
}
