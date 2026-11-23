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
        if ($this->probe !== null) {
            @unlink($this->probe);
        }
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

    public function testAFileEditedOnDiskIsRenderedAgainInProduction(): void
    {
        $file = $this->root . '/plugin/templates/default/reload.html.twig';

        file_put_contents($file, 'first');
        touch($file, time() - 100);
        $this->assertSame('first', $this->render());

        file_put_contents($file, 'second');
        touch($file, time() + 100);
        $this->assertSame('second', $this->render(), 'the edited file is compiled again');
    }

    /**
     * @return array<string, array{bool, string}>
     */
    public static function coreCases(): array
    {
        return ['not debug: the compiled core template is trusted' => [false, 'first'], 'debug: it is checked like any other' => [true, 'second']];
    }

    /**
     * Core's templates change with a release, which moves the compiled templates' directory, so outside debug they are not looked at on the disk.
     *
     * @dataProvider coreCases
     */
    public function testACoreTemplateEditedOnDiskIsRenderedAgainOnlyInDebug(bool $debug, string $expected): void
    {
        $name = 'zz-reload-probe-' . bin2hex(random_bytes(4));
        $file = dirname(__DIR__, 2) . '/templates/default/' . $name . '.html.twig';
        $this->probe = $file;
        file_put_contents($file, 'first');
        touch($file, time() - 100);
        $first = $this->renderFile($file, $debug);
        $this->assertSame('first', $first);

        file_put_contents($file, 'second');
        touch($file, time() + 100);
        $this->assertSame($expected, $this->renderFile($file, $debug));
    }

    private ?string $probe = null;

    private function renderFile(string $file, bool $debug): string
    {
        $code = <<<'PHP'
        require $argv[1] . '/vendor/autoload.php';
        $_SERVER['IDNO_CACHE_DIR'] = $argv[2];
        $kernel = new Idno\Kernel('prod', $argv[4] === '1', bootLegacy: false);
        $kernel->boot();
        $container = $kernel->getContainer();
        $context = new class () implements Idno\Templating\PhpTemplateContext {
            public function withVars(array $vars): static { return $this; }
            public function forgetVariables(array $names): void {}
            public function phpTemplateScope(): string { return self::class; }
            public function draw($templateName, $returnBlank = true) { return ''; }
            public function drawSlot($name) { return ''; }
        };
        $located = new Idno\Templating\LocatedTemplate($argv[3], 'twig', 'default', $argv[1], 'default/' . basename($argv[3]));
        echo $container->get(Idno\Templating\TwigTemplateEngine::class)->render($located, [], $context);
        PHP;
        exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -r ' . escapeshellarg($code) . ' ' . escapeshellarg(dirname(__DIR__, 2)) . ' ' . escapeshellarg($this->root . '/cache') . ' ' . escapeshellarg($file) . ' ' . ($debug ? '1' : '0') . ' 2>&1', $lines, $exit);
        $this->assertSame(0, $exit, implode("\n", $lines));

        return implode("\n", $lines);
    }
}
