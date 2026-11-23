<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Templating\CascadeTwigLoader;
use Idno\Templating\TemplateCascade;
use Idno\Templating\TolerantTwigCache;
use Idno\Templating\TwigCacheWarmer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Tests\Unit\Queue\CollectingLogger;
use Twig\Environment;

/**
 * `php bin/console cache:warmup` compiles every Twig template of core, the bundled plugins and the themes, so that a web
 * server that cannot write the cache directory (or a first request that would compile a whole page) finds them ready.
 */
final class TwigCacheWarmerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/idno-twig-warm-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function file(string $path, string $contents): void
    {
        $path = $this->root . '/' . $path;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @return list<string> the compiled files
     */
    private function compiled(): array
    {
        return glob($this->root . '/cache/*/*.php') ?: [];
    }

    public function testEveryCoreAndBundledTwigFileIsCompiled(): void
    {
        $salt = bin2hex(random_bytes(4));
        $this->file('project/templates/default/warm/core.html.twig', 'core ' . $salt);
        $this->file('project/templates/default/warm/deep/nested.html.twig', 'nested ' . $salt);
        $this->file('project/templates/email-text/warm/text.txt.twig', 'text ' . $salt);
        $this->file('project/templates/default/warm/old.tpl.php', '<?php echo 1;');
        $this->file('project/IdnoPlugins/Warm/templates/default/warm/plugin.html.twig', 'plugin ' . $salt);
        $this->file('project/Themes/Warm/templates/default/warm/theme.html.twig', 'theme ' . $salt);

        $cascade = new TemplateCascade($this->root . '/project');
        $twig = new Environment(new CascadeTwigLoader($cascade), ['cache' => new TolerantTwigCache($this->root . '/cache', static fn () => new CollectingLogger()), 'auto_reload' => true]);
        $warmer = new TwigCacheWarmer($this->root . '/project', $cascade, static fn () => $twig, static fn () => new CollectingLogger());

        $this->assertInstanceOf(CacheWarmerInterface::class, $warmer);
        $this->assertTrue($warmer->isOptional(), 'a deploy step, not something every boot does');
        $this->assertSame([], $this->compiled());

        $warmer->warmUp($this->root . '/cache');

        $this->assertCount(5, $this->compiled(), 'core (three files), the plugin and the theme, and no .tpl.php');
    }

    public function testNothingToCompileIsNotAnError(): void
    {
        $this->file('project/templates/default/x.tpl.php', '');
        $cascade = new TemplateCascade($this->root . '/project');
        $twig = new Environment(new CascadeTwigLoader($cascade), ['cache' => $this->root . '/cache']);

        $this->assertSame([], (new TwigCacheWarmer($this->root . '/project', $cascade, static fn () => $twig, static fn () => new CollectingLogger()))->warmUp($this->root . '/cache'));
        $this->assertSame([], $this->compiled());
    }

    public function testAThemeOrPluginTemplateThatDoesNotCompileDoesNotStopTheWarmup(): void
    {
        $this->file('project/templates/default/warm/ok.html.twig', 'ok');
        $this->file('project/Themes/Broken/templates/default/warm/broken.html.twig', '{% if %}');
        $cascade = new TemplateCascade($this->root . '/project');
        $twig = new Environment(new CascadeTwigLoader($cascade), ['cache' => new TolerantTwigCache($this->root . '/cache', static fn () => new CollectingLogger())]);

        (new TwigCacheWarmer($this->root . '/project', $cascade, static fn () => $twig, static fn () => new CollectingLogger(), static function (string $line): void {
        }))->warmUp($this->root . '/cache');

        $this->assertCount(1, $this->compiled());
    }

    public function testACoreTemplateThatDoesNotCompileIsAnError(): void
    {
        $this->file('project/templates/default/warm/broken.html.twig', '{% if %}');
        $cascade = new TemplateCascade($this->root . '/project');
        $twig = new Environment(new CascadeTwigLoader($cascade), ['cache' => $this->root . '/cache']);

        $this->expectException(\Twig\Error\SyntaxError::class);
        (new TwigCacheWarmer($this->root . '/project', $cascade, static fn () => $twig, static fn () => new CollectingLogger()))->warmUp($this->root . '/cache');
    }

    public function testAPluginOrThemeTemplateThatDoesNotCompileIsNamedInOneWarning(): void
    {
        $this->file('project/templates/default/warm/ok.html.twig', 'ok');
        $this->file('project/Themes/Broken/templates/default/warm/broken.html.twig', '{% if %}');
        $cascade = new TemplateCascade($this->root . '/project');
        $twig = new Environment(new CascadeTwigLoader($cascade), ['cache' => new TolerantTwigCache($this->root . '/cache', static fn () => new CollectingLogger())]);
        $logger = new CollectingLogger();
        $shown = [];

        (new TwigCacheWarmer($this->root . '/project', $cascade, static fn () => $twig, static fn () => $logger, static function (string $line) use (&$shown): void {
            $shown[] = $line;
        }))->warmUp($this->root . '/cache');

        $expected = 'Twig template default/warm/broken.html.twig was not compiled: SyntaxError';
        $this->assertSame([['warning', $expected]], $logger->lines);
        $this->assertSame([$expected], $shown);
    }

    public function testAnUnwritableCacheDirectoryIsWarnedAboutLoudlyAndTheWarmupReturns(): void
    {
        $this->file('project/templates/default/warm/ok.html.twig', 'unwritable ' . bin2hex(random_bytes(4)));
        $this->file('blocker', 'a file: nothing can be made below it');
        $directory = $this->root . '/blocker/twig';
        $cascade = new TemplateCascade($this->root . '/project');
        $twig = new Environment(new CascadeTwigLoader($cascade), ['cache' => new TolerantTwigCache($directory, static fn () => new CollectingLogger())]);
        $logger = new CollectingLogger();
        $shown = [];

        $result = (new TwigCacheWarmer($this->root . '/project', $cascade, static fn () => $twig, static fn () => $logger, static function (string $line) use (&$shown): void {
            $shown[] = $line;
        }))->warmUp($this->root . '/cache');

        $this->assertSame([], $result, 'it returns normally');
        $this->assertCount(1, $logger->lines);
        $this->assertSame('warning', $logger->lines[0][0]);
        $this->assertStringContainsString('could not write the compiled Twig templates to ' . $directory, $logger->lines[0][1]);
        $this->assertSame([$logger->lines[0][1]], $shown, 'and the same line is shown to whoever runs the command');
    }

    public function testTheContainerRunsItInsteadOfTheBundlesWarmer(): void
    {
        $container = Idno::container();
        $kernel = $container->get('kernel');
        $source = '';
        foreach (glob(dirname((string) (new \ReflectionClass($kernel->getContainer()))->getFileName()) . '/*.php') ?: [] as $file) {
            $source .= (string) file_get_contents($file);
        }

        $this->assertStringContainsString('TwigCacheWarmer', $source);
        $this->assertStringNotContainsString('Symfony\\Bundle\\TwigBundle\\CacheWarmer\\TemplateCacheWarmer', $source);
    }
}
