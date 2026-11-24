<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use PHPUnit\Framework\TestCase;
use Tests\Templating\FallbackTrace;

/**
 * `templates/classic/` of core and of the bundled plugins: the entity templates of the look Idno 2026 replaced, which the
 * output that is not a web page (feeds, JSON, ActivityPub, KML, mail) draws. Everything here is derived from the file system,
 * so a file added or removed is a change this test names, never a count to update.
 */
final class ClassicTemplatesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    /**
     * A `classic` file stands behind a `default` template of the same name in the same directory (a Twig file, or a `.tpl.php`
     * where there is none): it is the template that file replaced. A classic file with no default one beside it would be a
     * template only feeds could draw.
     */
    public function testEveryClassicFileHasADefaultTemplateOfTheSameNameBesideIt(): void
    {
        $classic = FallbackTrace::classic($this->root);

        $this->assertNotEmpty($classic, 'there are classic templates');
        $missing = [];
        foreach ($classic as [$name, $file]) {
            $default = substr(str_replace('/templates/classic/', '/templates/default/', '/' . $file), 0, -strlen('.tpl.php'));
            if (!is_file($this->root . $default . '.html.twig') && !is_file($this->root . $default . '.tpl.php')) {
                $missing[] = $file;
            }
        }
        $this->assertSame([], $missing);
    }

    /**
     * Core and every bundled plugin that has a `classic` directory is listed, and the fixture of the template trace holds exactly
     * what the file system does.
     */
    public function testTheRecordedListIsTheFileSystemsList(): void
    {
        $recorded = (require __DIR__ . '/../../Templating/fixtures/fallback-trace.php')['classic'];

        $this->assertSame(FallbackTrace::classic($this->root), $recorded, 'a classic template was added or removed: record it in fixtures/fallback-trace.php on purpose');
    }

    /**
     * Only `.tpl.php` files: a Twig file in `classic` would be drawn by feeds and mail with the autoescaping of a web page.
     */
    public function testClassicHoldsPhpTemplatesOnly(): void
    {
        $other = [];
        foreach ($this->classicDirectories() as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && !str_ends_with($file->getFilename(), '.tpl.php')) {
                    $other[] = substr($file->getPathname(), strlen($this->root) + 1);
                }
            }
        }

        $this->assertSame([], $other);
    }

    /**
     * Idno 2026 is the `default` look: neither core nor a bundled plugin has a `modern` directory any more (a third-party
     * plugin's may, for one release).
     */
    public function testNoBundledDirectoryHasAModernTemplateType(): void
    {
        $modern = array_merge(glob($this->root . '/templates/modern', GLOB_ONLYDIR) ?: [], glob($this->root . '/IdnoPlugins/*/templates/modern', GLOB_ONLYDIR) ?: []);

        $this->assertSame([], $modern);
    }

    /**
     * @return list<string>
     */
    private function classicDirectories(): array
    {
        return array_merge(glob($this->root . '/templates/classic', GLOB_ONLYDIR) ?: [], glob($this->root . '/IdnoPlugins/*/templates/classic', GLOB_ONLYDIR) ?: []);
    }
}
