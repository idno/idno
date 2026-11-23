<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\TypeChain;
use PHPUnit\Framework\TestCase;
use Tests\Templating\FallbackTrace;

/**
 * Which template files the output that is not a web page (feeds, JSON, JSONP, JSON Feed, ActivityPub, KML) can draw, worked
 * out from the file system alone: the closure of the template names reachable from what each type draws first, resolved
 * with the type's own chain (its own directory, then `classic`, then `default`) over core and the bundled plugins.
 *
 * The rule it pins: such output never draws a file under a `default` directory, which is the web page look and is ported to
 * Twig, whose escaping is wrong for a feed. A `default` file that a feed draws has a copy in `classic`, found first.
 */
final class NonHtmlReachTest extends TestCase
{
    /** The names the helper methods of the template draw. */
    private const HELPER_NAMES = [
        'signForm' => 'forms/token',
        'createLink' => 'forms/link',
        'createForm' => 'forms/action',
        'drawSyndication' => 'content/syndication',
        'drawPagination' => 'shell/pagination',
    ];

    /** What `content/end/annotations` and the annotation page build the name of: `entity/annotations/<name>`. */
    private const ANNOTATION_NAMES = ['rsvps', 'likes', 'shares', 'replies', 'mentions', 'like', 'reply'];

    /**
     * @param bool $withPageBodies also start from every name a PHP class outside the templates names in a string (a page's body,
     *                             a plugin's `extendTemplate()`), and let a name built by concatenation (`'forms/input/' . $type`)
     *                             stand for every template under it
     *
     * @return array<string, list<string>> type => the files (relative to the project) its draws resolve to, sorted
     */
    public static function reachable(string $projectDir, bool $withPageBodies = false): array
    {
        $pageNames = $withPageBodies ? self::namedByPageClasses($projectDir) : [];
        $result = [];
        foreach (array_diff(FallbackTrace::TYPES, ['email', 'email-text']) as $type) {
            $files = [];
            $seen = [];
            $queue = array_merge(self::seeds($projectDir, $type), $pageNames);
            while ($queue !== []) {
                $name = array_shift($queue);
                if (isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                if ($withPageBodies && str_ends_with($name, '/')) {
                    foreach (self::namesUnder($projectDir, $name) as $under) {
                        $queue[] = $under;
                    }
                    continue;
                }
                foreach (self::resolve($projectDir, $type, $name) as $file) {
                    $files[$file] = true;
                    foreach (self::drawnNames((string) file_get_contents($projectDir . '/' . $file), $withPageBodies) as $next) {
                        $queue[] = $next;
                    }
                }
            }
            $list = array_keys($files);
            sort($list);
            $result[$type] = $list;
        }

        return $result;
    }

    /**
     * Every template name a page class (or any PHP class outside the templates) names in a literal draw call.
     *
     * @return list<string>
     */
    public static function namedByPageClasses(string $projectDir): array
    {
        $names = [];
        foreach (self::phpFilesOutsideTemplates($projectDir) as $file) {
            $php = (string) file_get_contents($file);
            foreach (self::drawnNames($php, true) as $name) {
                $names[$name] = true;
            }
            if (preg_match_all('/[\'"]([a-z0-9_-]+(?:\/[A-Za-z0-9_-]+)+)[\'"]/', $php, $matches) > 0) {
                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }
        $list = array_map('strval', array_keys($names));
        sort($list);

        return $list;
    }

    public function testEveryTemplateANonHtmlTypeReachesIsAPhpFileOutsideDefault(): void
    {
        $inDefault = [];
        foreach (self::reachable(dirname(__DIR__, 3)) as $type => $files) {
            foreach ($files as $file) {
                if (preg_match('#(^|/)templates/default/#', $file) === 1) {
                    $inDefault[$file][] = $type;
                }
            }
        }
        ksort($inDefault);

        $this->assertSame([], array_map(static fn (array $types): string => implode(', ', $types), $inDefault));
    }

    public function testNoClassicFileIsUnreachable(): void
    {
        $root = dirname(__DIR__, 3);
        $reached = [];
        foreach (self::reachable($root, true) as $files) {
            foreach ($files as $file) {
                $reached[$file] = true;
            }
        }
        $unreachable = [];
        foreach (FallbackTrace::classic($root) as [$name, $file]) {
            if (!isset($reached[$file])) {
                $unreachable[] = $file;
            }
        }

        $this->assertSame([], $unreachable);
    }

    /**
     * @return list<string>
     */
    private static function seeds(string $projectDir, string $type): array
    {
        $seeds = ['entity/default'];
        $own = $projectDir . '/templates/' . $type;
        if (is_dir($own)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($own, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.tpl.php')) {
                    $seeds[] = substr($file->getPathname(), strlen($own) + 1, -strlen('.tpl.php'));
                }
            }
        }
        foreach (self::entityClasses($projectDir) as $short) {
            $seeds[] = 'entity/' . $short;
        }
        foreach (self::HELPER_NAMES as $name) {
            $seeds[] = $name;
        }

        return $seeds;
    }

    /**
     * The short names of the classes that carry `#[AsEntity]` under `Idno/` and `IdnoPlugins/`.
     *
     * @return list<string>
     */
    private static function entityClasses(string $projectDir): array
    {
        $short = [];
        foreach ([$projectDir . '/Idno', $projectDir . '/IdnoPlugins'] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/templates/')) {
                    continue;
                }
                $text = (string) file_get_contents($file->getPathname());
                if (preg_match('/^\s*#\[(?:\\\\?Idno\\\\Storage\\\\Attribute\\\\)?AsEntity\b/m', $text) === 1) {
                    $short[$file->getBasename('.php')] = true;
                }
            }
        }
        $list = array_keys($short);
        sort($list);

        return $list;
    }

    /**
     * @return list<string> the files a draw of $name resolves to for $type: those of the first type of the chain that has one
     */
    private static function resolve(string $projectDir, string $type, string $name): array
    {
        $bases = array_merge(glob($projectDir . '/IdnoPlugins/*', GLOB_ONLYDIR) ?: [], [$projectDir]);
        foreach (TypeChain::for($type) as $candidate) {
            $found = [];
            foreach ($bases as $base) {
                $file = $base . '/templates/' . $candidate . '/' . $name . '.tpl.php';
                if (is_file($file)) {
                    $found[] = substr($file, strlen($projectDir) + 1);
                }
            }
            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * The template names a PHP file draws by a literal: `draw('name')`, the helper methods, and the annotation names built by concatenation.
     *
     * @return list<string>
     */
    private static function drawnNames(string $php, bool $withPrefixes = false): array
    {
        $names = [];
        if (preg_match_all('/\bdraw\(\s*[\'"]([A-Za-z0-9_\/-]+)[\'"]/', $php, $matches) > 0) {
            foreach ($matches[1] as $name) {
                if (!str_ends_with($name, '/')) {
                    $names[] = $name;
                }
            }
        }
        foreach (self::HELPER_NAMES as $helper => $name) {
            if (str_contains($php, $helper . '(')) {
                $names[] = $name;
            }
        }
        if (str_contains($php, "'entity/annotations/' .")) {
            foreach (self::ANNOTATION_NAMES as $annotation) {
                $names[] = 'entity/annotations/' . $annotation;
            }
        }

        if ($withPrefixes && preg_match_all('/[\'"]([A-Za-z0-9_\/-]+\/)[\'"]\s*\./', $php, $matches) > 0) {
            foreach ($matches[1] as $prefix) {
                $names[] = $prefix;
            }
        }

        return $names;
    }

    /**
     * The names of the `.tpl.php` templates of every type in core and the bundled plugins that start with $prefix.
     *
     * @return list<string>
     */
    private static function namesUnder(string $projectDir, string $prefix): array
    {
        $names = [];
        $bases = array_merge(glob($projectDir . '/IdnoPlugins/*', GLOB_ONLYDIR) ?: [], [$projectDir]);
        foreach ($bases as $base) {
            foreach (glob($base . '/templates/*', GLOB_ONLYDIR) ?: [] as $typeDirectory) {
                $directory = $typeDirectory . '/' . $prefix;
                if (!is_dir($directory)) {
                    continue;
                }
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile() && str_ends_with($file->getFilename(), '.tpl.php')) {
                        $names[] = substr($file->getPathname(), strlen($typeDirectory) + 1, -strlen('.tpl.php'));
                    }
                }
            }
        }

        return $names;
    }

    /**
     * @return \Generator<string>
     */
    private static function phpFilesOutsideTemplates(string $projectDir): \Generator
    {
        foreach ([$projectDir . '/Idno', $projectDir . '/IdnoPlugins'] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php' && !str_contains($file->getPathname(), '/templates/') && !str_contains($file->getPathname(), '/Tests/')) {
                    yield $file->getPathname();
                }
            }
        }
    }
}
