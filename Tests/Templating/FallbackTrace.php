<?php

declare(strict_types=1);

namespace Tests\Templating;

/**
 * Reads a template trace (Tests\Fixtures\TemplateTrace: `type`, `name`, `file`, tab separated, one line a draw)
 * of the draws of the output that is not a web page, and keeps for each such type the `classic` files of core and of
 * the bundled plugins it drew: the entity templates of the look Idno 2026 replaced, which a feed, JSON, ActivityPub, KML
 * and mail still draw (a web page never does). It is what fixtures/fallback-trace.php holds.
 */
final class FallbackTrace
{
    /** The template types that are not a web page. */
    public const TYPES = ['rss', 'json', 'jsonp', 'jsonfeed', 'activitypub', 'kml', 'markdown', 'email', 'email-text'];

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
            if (preg_match('#^(?:IdnoPlugins/[^/]+/)?templates/classic/#', $file) !== 1) {
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
     * Every template name drawn per type, whichever file it was found in.
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
     * Every `.tpl.php` file under `templates/classic/` of core and of the bundled plugins: [name, file], sorted. Computed
     * from the file system.
     *
     * @return list<array{string, string}>
     */
    public static function classic(string $projectDirectory): array
    {
        $bases = array_merge([$projectDirectory], glob($projectDirectory . '/IdnoPlugins/*', GLOB_ONLYDIR) ?: []);
        $found = [];
        foreach ($bases as $base) {
            $directory = $base . '/templates/classic';
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.tpl.php')) {
                    $name = substr($file->getPathname(), strlen($directory) + 1, -strlen('.tpl.php'));
                    $relative = substr($file->getPathname(), strlen($projectDirectory) + 1);
                    $found[$name . "\t" . $relative] = [$name, $relative];
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
     * @param list<array{string, string}>                 $classic
     */
    public static function write(string $file, array $derived, array $classic): void
    {
        $text = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/*\n"
            . " * The `classic` template files: the entity templates of the look Idno 2026 replaced, which the output that is not a web page\n"
            . " * (feeds, JSON, ActivityPub, KML, mail) still draws, by falling back from its own template type to `classic` before `default`.\n"
            . " * `traced` is, for each type, the `classic` files a real run of the test site drew. `classic` is every file under\n"
            . " * `templates/classic/` of core and of the bundled plugins (computed from the file system, and asserted by the test).\n"
            . " *\n"
            . " * Derived from a real traced run, not written by hand. To regenerate it (only when a draw that reaches `classic` is\n"
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
        $text .= "    ],\n    'classic' => [\n";
        foreach ($classic as [$name, $path]) {
            $text .= "        ['" . $name . "', '" . $path . "'],\n";
        }
        file_put_contents($file, $text . "    ],\n];\n");
    }
}
