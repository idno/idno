<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;

/**
 * A Twig file of a plugin or theme that is edited on disk (an update in place) is compiled again in a production kernel: the
 * compiled templates are kept per build, and nothing in the code fingerprint covers `IdnoPlugins/` or `Themes/`, so a cache that
 * trusted its entries would serve the old template until it was cleared by hand.
 */
final class TwigAutoReloadTest extends TestCase
{
    private string $root;


    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/idno-twig-reload-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/plugin/templates/default', 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * Renders the template in a production kernel in a process of its own, as a request does: Twig declares a compiled template's
     * class once for the life of the process, so a second render in this process would never look at the file again.
     */
    private function render(): string
    {
        $code = <<<'PHP'
        require $argv[1] . '/vendor/autoload.php';
        $_SERVER['IDNO_CACHE_DIR'] = $argv[2];
        $kernel = new Idno\Kernel('prod', false, bootLegacy: false);
        $kernel->boot();
        $container = $kernel->getContainer();
        $container->get(Idno\Templating\TemplateCascade::class)->addBase($argv[3]);
        $context = new class () implements Idno\Templating\PhpTemplateContext {
            public function withVars(array $vars): static { return $this; }
            public function forgetVariables(array $names): void {}
            public function phpTemplateScope(): string { return self::class; }
            public function draw($templateName, $returnBlank = true) { return ''; }
            public function drawSlot($name) { return ''; }
        };
        $located = new Idno\Templating\LocatedTemplate($argv[3] . '/templates/default/reload.html.twig', 'twig', 'default', $argv[3], 'default/reload.html.twig');
        echo $container->get(Idno\Templating\TwigTemplateEngine::class)->render($located, [], $context);
        PHP;
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -r ' . escapeshellarg($code) . ' ' . escapeshellarg(dirname(__DIR__, 2)) . ' ' . escapeshellarg($this->root . '/cache') . ' ' . escapeshellarg($this->root . '/plugin') . ' 2>&1';
        exec($command, $lines, $exit);
        $this->assertSame(0, $exit, implode("\n", $lines));

        return implode("\n", $lines);
    }

    private function file(): string
    {
        return $this->root . '/plugin/templates/default/reload.html.twig';
    }

    /**
     * @return array<string, int> each compiled template file and its modification time
     */
    private function compiled(): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/cache', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (str_ends_with($file->getPathname(), '.php') && str_contains($file->getPathname(), '/twig/')) {
                $found[$file->getPathname()] = $file->getMTime();
            }
        }

        return $found;
    }

    public function testAFileEditedOnDiskIsRenderedAgainInProduction(): void
    {
        file_put_contents($this->file(), 'first');
        touch($this->file(), time() - 100);
        $this->assertSame('first', $this->render());

        file_put_contents($this->file(), 'second');
        touch($this->file(), time() + 100);
        $this->assertSame('second', $this->render(), 'the edited file is compiled again');
    }

    public function testAFileWhoseDateIsOlderThanTheCompiledOneIsStillRenderedAgain(): void
    {
        // A rollback to an artifact built earlier, an archive that keeps the build's dates and a checkout of an older commit give a file
        // older than the compiled copy of the one it replaced
        file_put_contents($this->file(), 'first');
        touch($this->file(), time() - 5_000);
        $this->assertSame('first', $this->render());

        file_put_contents($this->file(), 'a longer second');
        touch($this->file(), time() - 9_000);
        $this->assertSame('a longer second', $this->render(), 'another size, an older date than the file and than its compiled copy');
    }

    public function testAFileOfTheSameSizeAndAnOlderDateIsRenderedAgain(): void
    {
        file_put_contents($this->file(), 'first');
        touch($this->file(), time() - 5_000);
        $this->assertSame('first', $this->render());

        file_put_contents($this->file(), 'third');
        touch($this->file(), time() - 9_000);
        $this->assertSame('third', $this->render(), 'the same size, another (older) date');
    }

    public function testAnUnchangedFileIsNotCompiledAgain(): void
    {
        file_put_contents($this->file(), 'first');
        touch($this->file(), time() - 5_000);
        $this->assertSame('first', $this->render());
        $compiled = $this->compiled();
        $this->assertNotSame([], $compiled);
        foreach (array_keys($compiled) as $path) {
            touch($path, time() + 3_600); // newer than any file Twig compares it with (the extensions' too), and a rewrite would set the time of the render
        }
        $before = $this->compiled();

        $this->assertSame('first', $this->render());

        $this->assertSame($before, $this->compiled(), 'no compiled file was written, replaced or added');
    }
}
