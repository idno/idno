<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * Reads a template trace (Tests\Fixtures\TemplateTrace: `type`, `name`, `file`, tab separated, one line a draw)
 * of the draws of the output that is not a web page, and keeps for each such type the `default` files of core
 * and of the plugins that a `modern` file of the same name shadows: what a site with the Twenty26 theme would
 * draw differently if the type fell back to the theme's own files. It is what fixtures/fallback-trace.php holds.
 */
final class FallbackTrace
{
    /** The template types that are not a web page. */
    public const TYPES = ['rss', 'json', 'jsonp', 'jsonfeed', 'activitypub', 'kml', 'email', 'email-text'];

    /**
     * @return array<string, list<array{string, string}>> type => list of [name, file], sorted
     */
    public static function derive(string $trace, string $projectDirectory): array
    {
        $found = [];
        foreach (explode("\n", $trace) as $line) {
            $parts = explode("\t", $line);
            if (count($parts) !== 3 || !in_array($parts[0], self::TYPES, true) || $parts[2] === '-') {
                continue;
            }
            [$type, $name, $file] = $parts;
            if (preg_match('#^(?:IdnoPlugins/[^/]+/)?templates/default/#', $file) !== 1 || !self::isShadowedByModern($name, $projectDirectory)) {
                continue;
            }
            $found[$type][$name . "\t" . $file] = [$name, $file];
        }
        ksort($found);
        foreach ($found as $type => $entries) {
            ksort($entries);
            $found[$type] = array_values($entries);
        }

        return $found;
    }

    /**
     * Every template name drawn per type, whether or not a `modern` file shadows its `default` one.
     *
     * @return array<string, list<string>> type => sorted names
     */
    public static function draws(string $trace): array
    {
        $names = [];
        foreach (explode("\n", $trace) as $line) {
            $parts = explode("\t", $line);
            if (count($parts) === 3 && $parts[2] !== '-') {
                $names[$parts[0]][$parts[1]] = true;
            }
        }
        $result = [];
        foreach ($names as $type => $list) {
            $sorted = array_keys($list);
            sort($sorted);
            $result[$type] = $sorted;
        }
        ksort($result);

        return $result;
    }

    /**
     * Every `default` template file of core and of the bundled plugins that a `modern` file of the same name shadows
     * (Twenty26's, or a plugin's): [name, file], sorted. Computed from the file system.
     *
     * @return list<array{string, string}>
     */
    public static function shadowed(string $projectDirectory): array
    {
        $bases = array_merge([$projectDirectory], glob($projectDirectory . '/IdnoPlugins/*', GLOB_ONLYDIR) ?: [], glob($projectDirectory . '/Themes/*', GLOB_ONLYDIR) ?: []);
        $modern = [];
        foreach ($bases as $base) {
            $directory = $base . '/templates/modern';
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.tpl.php')) {
                    $modern[substr($file->getPathname(), strlen($directory) + 1, -strlen('.tpl.php'))] = true;
                }
            }
        }
        $found = [];
        foreach ($bases as $base) {
            if (str_contains($base, '/Themes/')) {
                continue;
            }
            foreach (array_keys($modern) as $name) {
                if (is_file($base . '/templates/default/' . $name . '.tpl.php')) {
                    $found[$name . "\t" . ltrim(substr($base, strlen($projectDirectory)) . '/', '/') . 'templates/default/' . $name . '.tpl.php'] = [$name, ltrim(substr($base, strlen($projectDirectory)) . '/', '/') . 'templates/default/' . $name . '.tpl.php'];
                }
            }
        }
        ksort($found);

        return array_values($found);
    }

    /**
     * Writes the arrays as a PHP file.
     *
     * @param array<string, list<array{string, string}>> $derived
     * @param list<array{string, string}>                 $shadowed
     */
    public static function write(string $file, array $derived, array $shadowed): void
    {
        $text = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/*\n"
            . " * The template files the output that is not a web page reaches by falling back from its own template type to `default`,\n"
            . " * where a `modern` file of the same name exists (Twenty26's, or a plugin's): for each type, [name, file] pairs, sorted.\n"
            . " * Today a feed or a mail draws core's and the plugins' `default` entity templates even on a Twenty26 site; `traced` is\n"
            . " * the list of files whose place in the template chain decides that. `shadowed` is every `default` file of core and of the\n"
            . " * bundled plugins that a `modern` file of the same name shadows, whether or not a traced page reaches it (computed from\n"
            . " * the file system, and asserted by the test).\n"
            . " *\n"
            . " * Derived from a real traced run, not written by hand. To regenerate it (only when a draw that reaches `default` is\n"
            . " * added or removed on purpose): IDNO_TEST_CAPTURE_GOLDENS=1 composer test -- Tests/Templating/RenderedPagesTest.php\n"
            . " * (the same run rewrites the golden pages: review `git diff` of both).\n"
            . " */\n"
            . "return [\n    'traced' => [\n";
        foreach ($derived as $type => $entries) {
            $text .= "        '" . $type . "' => [\n";
            foreach ($entries as [$name, $path]) {
                $text .= "            ['" . $name . "', '" . $path . "'],\n";
            }
            $text .= "        ],\n";
        }
        $text .= "    ],\n    'shadowed' => [\n";
        foreach ($shadowed as [$name, $path]) {
            $text .= "        ['" . $name . "', '" . $path . "'],\n";
        }
        file_put_contents($file, $text . "    ],\n];\n");
    }

    private static function isShadowedByModern(string $name, string $projectDirectory): bool
    {
        $bases = array_merge([$projectDirectory], glob($projectDirectory . '/IdnoPlugins/*', GLOB_ONLYDIR) ?: [], glob($projectDirectory . '/Themes/*', GLOB_ONLYDIR) ?: []);
        foreach ($bases as $base) {
            if (is_file($base . '/templates/modern/' . $name . '.tpl.php')) {
                return true;
            }
        }

        return false;
    }
}
