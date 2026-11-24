<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\CascadeTwigLoader;
use Idno\Templating\TemplateCascade;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\LoaderError;

final class CascadeTwigLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/idno-twig-loader-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() || $file->isLink() ? @rmdir($file->getPathname()) || @unlink($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    private function file(string $path, string $contents): string
    {
        $path = $this->root . '/' . $path;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    private function loader(): CascadeTwigLoader
    {
        $cascade = new TemplateCascade($this->root . '/core');
        $cascade->addBase($this->root . '/plugin');
        $cascade->addBase($this->root . '/theme');

        return new CascadeTwigLoader($cascade);
    }

    public function testANameIsFoundInTheFirstDirectoryThatHasIt(): void
    {
        $core = $this->file('core/templates/default/x.html.twig', 'core');
        $plugin = $this->file('plugin/templates/default/x.html.twig', 'plugin');
        $this->file('core/templates/default/only-core.txt.twig', 'text');
        $this->file('theme/templates/default/y.html.twig', 'theme');
        $loader = $this->loader();

        $this->assertSame('plugin', $loader->getSourceContext('default/x.html.twig')->getCode(), 'a later added directory is first; the theme has none of this name');
        $this->assertSame($plugin, $loader->getSourceContext('default/x.html.twig')->getPath());
        $this->assertSame('default/x.html.twig', $loader->getSourceContext('default/x.html.twig')->getName());
        $this->assertSame('theme', $loader->getSourceContext('default/y.html.twig')->getCode());
        $this->assertSame('text', $loader->getSourceContext('default/only-core.txt.twig')->getCode());
        $this->assertStringStartsWith($plugin . "\0", $loader->getCacheKey('default/x.html.twig'), 'the cache key names the file');
        $this->assertSame('core', $loader->getSourceContext('@core/default/x.html.twig')->getCode(), '@core is core\'s copy');
        $this->assertStringStartsWith($core . "\0", $loader->getCacheKey('@core/default/x.html.twig'));
    }

    public function testAtCoreFindsCoresFilesOnly(): void
    {
        $this->file('theme/templates/default/themed.html.twig', 'theme');
        $this->file('core/templates/default/cored.html.twig', 'core');
        $loader = $this->loader();

        $this->assertTrue($loader->exists('default/themed.html.twig'));
        $this->assertFalse($loader->exists('@core/default/themed.html.twig'));
        $this->assertTrue($loader->exists('default/cored.html.twig'));
        $this->assertTrue($loader->exists('@core/default/cored.html.twig'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedNames(): array
    {
        return [
            'parent directory' => ['../../secret.html.twig'],
            'parent directory in the middle' => ['default/../../../secret.html.twig'],
            'parent after the file' => ['default/x.html.twig/../../y.html.twig'],
            'dots in a segment' => ['default/..x.html.twig'],
            'a dot segment' => ['default/./x.html.twig'],
            'at core and up' => ['@core/../../secret.html.twig'],
            'absolute' => ['/etc/passwd.html.twig'],
            'absolute with a real name' => ['/default/x.html.twig'],
            'doubled slash' => ['default//x.html.twig'],
            'trailing slash' => ['default/x/.html.twig'],
            'backslash' => ['default\\x.html.twig'],
            'backslash up' => ['default\\..\\x.html.twig'],
            'trailing newline' => ["default/x.html.twig\n"],
            'NUL' => ["default/x.html.twig\0"],
            'NUL inside' => ["default/x\0/y.html.twig"],
            'no type' => ['x.html.twig'],
            'php template' => ['default/x.tpl.php'],
            'no extension' => ['default/x'],
            'twig only' => ['default/x.twig'],
            'wrong case' => ['default/x.HTML.TWIG'],
            'a namespace of the bundle' => ['@Twig/Exception/error.html.twig'],
            'another namespace' => ['@other/default/x.html.twig'],
            'at core twice' => ['@core/@core/default/x.html.twig'],
            'at core with no type' => ['@core/x.html.twig'],
            'at core alone' => ['@core/'],
            'empty' => [''],
            'only the extension' => ['.html.twig'],
            'a name TemplateName would change' => ['_ABC/x.html.twig'],
            'a colon' => ['default/a:b.html.twig'],
            'a space' => ['default/a b.html.twig'],
            'a wrapper' => ['phar://x/default/x.html.twig'],
        ];
    }

    /**
     * @dataProvider refusedNames
     */
    public function testANameThatIsNotACascadeNameIsNeverLookedUp(string $name): void
    {
        // The files such a name would reach if it were joined to a path, in the directory above the base and in it
        $this->file('secret.html.twig', 'SECRET');
        $this->file('core/templates/default/x.html.twig', 'core');
        $this->file('core/templates/default/x.tpl.php', 'php');
        $this->file('core/secret.html.twig', 'SECRET');
        $loader = $this->loader();

        $this->assertFalse($loader->exists($name));
        $this->assertFalse($loader->isFresh($name, time() + 100));
        foreach ([fn () => $loader->getSourceContext($name), fn () => $loader->getCacheKey($name)] as $call) {
            try {
                $call();
                $this->fail('no file for ' . json_encode($name));
            } catch (LoaderError $e) {
                $this->assertStringNotContainsString("\0", $e->getMessage());
                $this->assertStringNotContainsString("\n", $e->getRawMessage());
            }
        }
    }

    public function testATemplateCannotIncludeOrExtendOrReadAFileOutsideATemplatesDirectory(): void
    {
        $this->file('secret.html.twig', 'SECRET');
        $this->file('core/secret.html.twig', 'SECRET');
        $this->file('core/templates/default/ok.html.twig', 'ok');
        $twig = new Environment($this->loader(), ['cache' => false]);

        $this->assertSame('ok', $twig->createTemplate("{% include 'default/ok.html.twig' %}")->render());
        foreach (['../../secret.html.twig', '@core/../secret.html.twig', 'default/../../../secret.html.twig', '../secret.html.twig'] as $name) {
            foreach (["{% include '$name' %}", "{{ source('$name') }}", "{% extends '$name' %}", "{{ include('$name') }}"] as $code) {
                try {
                    $out = $twig->createTemplate($code)->render();
                    $this->fail($code . ' drew: ' . $out);
                } catch (LoaderError) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function testTheCacheKeyNamesTheFileAndItsContentVersion(): void
    {
        $path = $this->file('core/templates/default/x.html.twig', 'core');
        touch($path, 1_000_000);
        $loader = $this->loader();

        $key = $loader->getCacheKey('default/x.html.twig');

        $this->assertStringContainsString($path, $key);
        $this->assertSame($key, $loader->getCacheKey('default/x.html.twig'), 'an unchanged file keeps its key');
        $this->assertSame($key, $this->loader()->getCacheKey('@core/default/x.html.twig'), 'whatever name reached it');
    }

    public function testAChangedFileAlwaysGetsANewKeyWhateverItsDate(): void
    {
        $path = $this->file('core/templates/default/x.html.twig', 'first');
        touch($path, 1_000_000);
        $loader = $this->loader();
        $first = $loader->getCacheKey('default/x.html.twig');

        file_put_contents($path, 'longer text');
        touch($path, 500_000);
        $older = $loader->getCacheKey('default/x.html.twig');

        file_put_contents($path, 'third');
        touch($path, 500_001);
        $sameSize = $loader->getCacheKey('default/x.html.twig');

        $this->assertNotSame($first, $older, 'a different size, an older date');
        $this->assertNotSame($first, $sameSize, 'the same size, another date');
        $this->assertNotSame($older, $sameSize);
    }

    public function testAFileIsFreshWhenItExistsBecauseItsKeyAlreadyHoldsItsVersion(): void
    {
        $path = $this->file('core/templates/default/x.html.twig', 'core');
        touch($path, 1_000_000);
        $loader = $this->loader();

        $this->assertTrue($loader->isFresh('default/x.html.twig', 0), 'a compiled class under this key is for this very file');
        $this->assertFalse($loader->isFresh('default/gone.html.twig', time()));
    }

    public function testATemplateDeletedWhileTheProcessRunsHasNoKeyAndIsNotFound(): void
    {
        $path = $this->file('core/templates/default/x.html.twig', 'core');
        $loader = $this->loader();
        $this->assertSame('core', $loader->getSourceContext('default/x.html.twig')->getCode());
        $loader->getCacheKey('default/x.html.twig');
        unlink($path);

        $warnings = [];
        set_error_handler(static function (int $number, string $message) use (&$warnings): bool {
            if ((error_reporting() & $number) === 0) {
                return false; // silenced with @
            }
            $warnings[] = $message;

            return true;
        });
        try {
            $thrown = [];
            foreach (['getCacheKey', 'getSourceContext'] as $method) {
                try {
                    $loader->$method('default/x.html.twig');
                } catch (LoaderError $e) {
                    $thrown[] = $e;
                }
            }
        } finally {
            restore_error_handler();
        }

        $this->assertCount(2, $thrown, 'the disk itself is asked, not the remembered answer');
        $this->assertSame([], $warnings, 'and no warning is raised for the missing file');
    }

    public function testTheLoaderForgetsItsNamesWhenTheCascadeForgetsItsFiles(): void
    {
        $cascade = new TemplateCascade($this->root . '/core');
        $loader = new CascadeTwigLoader($cascade);
        $this->assertFalse($loader->exists('default/x.html.twig'));
        $this->file('core/templates/default/x.html.twig', 'core');
        $this->assertFalse($loader->exists('default/x.html.twig'), 'remembered');

        $cascade->forgetFiles();

        $this->assertTrue($loader->exists('default/x.html.twig'), 'looked for again once the cascade forgot its files');
    }

    public function testADirectoryAddedAfterwardsIsSearched(): void
    {
        $cascade = new TemplateCascade($this->root . '/core');
        $loader = new CascadeTwigLoader($cascade);
        $this->file('core/templates/default/x.html.twig', 'core');
        $this->file('late/templates/default/x.html.twig', 'late');
        $this->assertSame('core', $loader->getSourceContext('default/x.html.twig')->getCode());

        $cascade->addBase($this->root . '/late');

        $this->assertSame('late', $loader->getSourceContext('default/x.html.twig')->getCode());
    }

    public function testErrorMessagesCarryNoControlCharactersOrLongNames(): void
    {
        $loader = $this->loader();
        try {
            $loader->getSourceContext("x\r\n\x1b[31m" . str_repeat('a', 500));
            $this->fail('refused');
        } catch (LoaderError $e) {
            $this->assertMatchesRegularExpression('/\A[\x20-\x7e]+\z/', $e->getRawMessage());
            $this->assertLessThan(260, strlen($e->getRawMessage()));
        }
    }

    public function testLogicalNames(): void
    {
        $this->assertTrue(CascadeTwigLoader::isLogicalName('default/x.html.twig'));
        $this->assertTrue(CascadeTwigLoader::isLogicalName('email-text/shell/body_1.txt.twig'));
        $this->assertFalse(CascadeTwigLoader::isLogicalName('x.html.twig'));
        $this->assertFalse(CascadeTwigLoader::isLogicalName('default/x.php'));
    }
}
