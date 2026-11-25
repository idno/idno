<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use PHPUnit\Framework\TestCase;

/**
 * The style sheets and scripts under `css/` and `js/`: every shipped file is drawn by something, and everything a template
 * names is there. Derived from the file system, so a file added or removed is a change this test names.
 */
final class StaticAssetsTest extends TestCase
{
    /**
     * Files nothing in the repository names, kept on purpose. Each needs its reason; an entry whose file is gone or is
     * named after all fails the test, so the list cannot go stale.
     *
     * @var array<string, string>
     */
    private const KEPT = [
    ];

    /** Built files that are not tracked, so no shipped file stands behind them. */
    private const BUILD_OUTPUTS = ['js/service-worker.js'];

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    public function testEveryShippedCssAndJsFileIsReferenced(): void
    {
        $shipped = $this->shippedFiles();
        $this->assertNotEmpty($shipped);

        $corpus = $this->corpus();
        $inputs = $this->gruntInputPatterns();

        $referenced = [];
        foreach ($shipped as $file) {
            if ($this->namedIn($file, $corpus) || $this->matchesAny($file, $inputs)) {
                $referenced[$file] = true;
            }
        }

        // A referenced file may load another one itself (an `@import`, a `@use`, a script that names a library): one level.
        foreach (array_keys($referenced) as $file) {
            foreach ($this->namedBy($file, $shipped) as $other) {
                $referenced[$other] = true;
            }
        }

        $unreferenced = array_values(array_filter($shipped, static fn (string $file): bool => !isset($referenced[$file])));
        $unlisted = array_values(array_diff($unreferenced, array_keys(self::KEPT)));
        $this->assertSame([], $unlisted, 'shipped files that no template, page class, installer, build file or other shipped file names: delete them, or add them to KEPT with a reason');

        foreach (self::KEPT as $file => $reason) {
            $this->assertNotSame('', trim($reason));
            $this->assertContains($file, $shipped, $file . ' is on the kept list but is not shipped');
            $this->assertContains($file, $unreferenced, $file . ' is on the kept list but something names it');
        }
    }

    /**
     * The built-in look's script is the only one a page loads: a template that calls a global of an earlier script (`Template`,
     * `ImageTools`, `EXIF`, `Modernizr`) throws a ReferenceError when the call runs.
     */
    public function testNoTemplateCallsAGlobalOfAScriptNothingLoads(): void
    {
        $offenders = [];
        foreach ($this->referencingFiles(false) as $relative => $path) {
            if (!str_ends_with($relative, '.twig')) {
                continue;
            }
            $source = (string) file_get_contents($path);
            if (preg_match('/\b(?:ImageTools|Modernizr|EXIF|base64ToArrayBuffer)\b|\bTemplate\.[a-z]/', $source) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders);
    }

    public function testEveryReferencedAssetExists(): void
    {
        $missing = [];
        foreach ($this->referencingFiles(false) as $relative => $path) {
            preg_match_all('~(?<![\w/.\-])((?:css|js)/[A-Za-z0-9_.\-]+(?:/[A-Za-z0-9_.\-]+)*\.(?:css|js))(?![\w.\-])~', (string) file_get_contents($path), $matches);
            foreach (array_unique($matches[1]) as $asset) {
                if (!is_file($this->root . '/' . $asset) && !in_array($asset, self::BUILD_OUTPUTS, true)) {
                    $missing[] = $relative . ' names ' . $asset;
                }
            }
        }
        $this->assertSame([], $missing);
    }

    /**
     * @return list<string> relative paths of the files under css/ and js/ that are shipped: no source map, no `.htaccess`, and
     *                      nothing the repository ignores (a build's intermediate files)
     */
    private function shippedFiles(): array
    {
        $files = [];
        foreach (['css', 'js'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }
                $relative = substr($entry->getPathname(), strlen($this->root) + 1);
                if (str_ends_with($relative, '.map') || basename($relative) === '.htaccess' || in_array($relative, self::BUILD_OUTPUTS, true) || str_ends_with($relative, '.es6')) {
                    continue;
                }
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return array<string, string> relative path => path, of what may draw an asset: the templates of core and the bundled
     *                               plugins, their PHP, the installer, and (when $buildFiles) the build files
     */
    private function referencingFiles(bool $buildFiles): array
    {
        // A page class or a template of core or a bundled plugin; the installer shares some assets but runs without the site.
        $roots = [$this->root . '/templates', $this->root . '/Idno', $this->root . '/IdnoPlugins'];
        if ($buildFiles) {
            $roots[] = $this->root . '/warmup';
            $roots[] = $this->root . '/frontend/vite.config.js';
        }

        $files = [];
        foreach ($roots as $start) {
            if (is_file($start)) {
                $files[substr($start, strlen($this->root) + 1)] = $start;
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($start, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                $relative = substr($entry->getPathname(), strlen($this->root) + 1);
                if (!$entry->isFile() || str_contains($relative, '/external/') || str_contains($relative, '/Tests/') || str_contains($relative, '/node_modules/')) {
                    continue;
                }
                if (preg_match('~\.(?:twig|php|dist|ini|html)$~', $relative) !== 1) {
                    continue;
                }
                $files[$relative] = $entry->getPathname();
            }
        }

        return $files;
    }

    /** @return string every file that may name an asset, as one text */
    private function corpus(): string
    {
        $text = '';
        foreach ($this->referencingFiles(true) as $path) {
            $text .= "\n" . file_get_contents($path);
        }

        return $text;
    }

    private function namedIn(string $file, string $corpus): bool
    {
        return str_contains($corpus, $file);
    }

    /**
     * What a Grunt task reads: the paths and globs under css/scss/ and js/src/ that the Gruntfile names (its inputs; the files
     * it writes are not references).
     *
     * @return list<string> regular expressions
     */
    private function gruntInputPatterns(): array
    {
        preg_match_all('~[\'"]((?:css/scss|js/src)/[^\'"]+)[\'"]~', (string) file_get_contents($this->root . '/Gruntfile.js'), $matches);
        $patterns = [];
        foreach (array_unique($matches[1]) as $glob) {
            $patterns[] = '~^' . str_replace(['\*\*/', '\*\*', '\*'], ['(?:.*/)?', '.*', '[^/]*'], preg_quote($glob, '~')) . '$~';
        }

        return $patterns;
    }

    /** @param list<string> $patterns */
    private function matchesAny(string $file, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $file) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The shipped files a shipped file names: an `@import` or `@use` (a partial may be written without its underscore and
     * extension), or a path or file name written in the text.
     *
     * @param list<string> $shipped
     *
     * @return list<string>
     */
    private function namedBy(string $file, array $shipped): array
    {
        $text = (string) file_get_contents($this->root . '/' . $file);
        $found = [];
        $directory = dirname($file);

        if (preg_match_all('~@(?:import|use)\s+[\'"]([^\'"]+)[\'"]~', $text, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $base = $directory . '/' . dirname($name) . '/' . basename($name, '.scss');
                $partial = $directory . '/' . ltrim(dirname($name) . '/', './') . '_' . basename($name, '.scss') . '.scss';
                foreach ([$base . '.scss', $partial, $directory . '/' . $name] as $candidate) {
                    $candidate = (string) preg_replace('~/\./~', '/', $candidate);
                    if (in_array($candidate, $shipped, true)) {
                        $found[] = $candidate;
                    }
                }
            }
        }
        foreach ($shipped as $other) {
            if ($other !== $file && !str_ends_with($other, '.scss') && (str_contains($text, $other) || str_contains($text, '/' . basename($other) . '"') || str_contains($text, '/' . basename($other) . '\''))) {
                $found[] = $other;
            }
        }

        return $found;
    }
}
