<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\CascadeTwigLoader;
use Idno\Templating\IdnoTwigExtension;
use Idno\Templating\TemplateCascade;
use Idno\Templating\TemplateLocator;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Cache\FilesystemCache;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\ArrayLoader;

/**
 * What drawing a template must not cost, counted and not timed (the timing test, PagePerformanceTest, is not run with the
 * suite): a path of the file system is looked at once and then remembered, the Twig loader resolves a name once, and a
 * template of the cascade is drawn without Twig building every function, filter, tag and operator of its extensions first.
 */
final class EngineCostTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/idno-engine-cost-' . bin2hex(random_bytes(6));
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

    private function file(string $path, string $contents = 'x'): string
    {
        $path = $this->root . '/' . $path;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    public function testAPathIsLookedAtOnceUntilADirectoryIsAddedOrTheFilesAreForgotten(): void
    {
        $present = $this->file('core/templates/default/a.tpl.php');
        $cascade = new TemplateCascade($this->root . '/core');

        $this->assertTrue($cascade->isFile($present));
        $this->assertFalse($cascade->isFile($this->root . '/core/templates/default/late.tpl.php'));
        unlink($present);
        $late = $this->file('core/templates/default/late.tpl.php');

        $this->assertTrue($cascade->isFile($present), 'a path that was a file is remembered as one');
        $this->assertFalse($cascade->isFile($late), 'a path that was not a file is remembered as none: no second system call');

        $cascade->forgetFiles();
        $this->assertFalse($cascade->isFile($present));
        $this->assertTrue($cascade->isFile($late));

        unlink($late);
        $this->file('plugin/templates/default/b.tpl.php');
        $cascade->addBase($this->root . '/plugin');
        $this->assertFalse($cascade->isFile($late), 'a new directory in the cascade starts the answers again');
    }

    public function testWhatIsRememberedIsBounded(): void
    {
        $cascade = new TemplateCascade($this->root);
        for ($i = 0; $i < TemplateCascade::REMEMBER_LIMIT + 10; ++$i) {
            $cascade->isFile($this->root . '/n' . $i);
        }

        $files = (new \ReflectionProperty($cascade, 'files'))->getValue($cascade);
        $this->assertLessThanOrEqual(TemplateCascade::REMEMBER_LIMIT, count($files));
    }

    public function testTheLoaderResolvesANameOnceAndTheLocatorsLookUpsAreNotRepeated(): void
    {
        $twig = $this->file('core/templates/default/shell/x.html.twig', 'x');
        $cascade = new TemplateCascade($this->root . '/core');
        $loader = new CascadeTwigLoader($cascade);
        $locator = new TemplateLocator($cascade);

        $this->assertSame($twig, $locator->locate('shell/x', 'default')?->path);
        unlink($twig);

        $this->assertSame($twig, $loader->getCacheKey('default/shell/x.html.twig'), 'the file the locator found is the loader\'s: its path was not looked at again');
        $this->assertSame($twig, $loader->getCacheKey('default/shell/x.html.twig'));
        $this->assertTrue($loader->exists('default/shell/x.html.twig'));
        $this->assertFalse($loader->exists('default/shell/gone.html.twig'));
        $this->file('core/templates/default/shell/gone.html.twig');
        $this->assertFalse($loader->exists('default/shell/gone.html.twig'), 'an answer is kept for the request, as the locator\'s is');

        $cascade->addBase($this->root);
        $this->expectException(LoaderError::class);
        $loader->getCacheKey('default/shell/x.html.twig');
    }

    public function testEveryFunctionAndFilterIsAMethodOfTheExtension(): void
    {
        $extension = $this->extension();
        $callables = [];
        foreach ($extension->getFunctions() as $function) {
            $callables['function ' . $function->getName()] = $function->getCallable();
        }
        foreach ($extension->getFilters() as $filter) {
            $callables['filter ' . $filter->getName()] = $filter->getCallable();
        }

        $this->assertNotEmpty($callables);
        foreach ($callables as $label => $callable) {
            $this->assertInstanceOf(\Closure::class, $callable, $label);
            $reflection = new \ReflectionFunction($callable);
            $this->assertStringNotContainsString('{closure', $reflection->getName(), $label . ' is a closure written in the extension: Twig looks it up at run time');
            $owner = $reflection->getClosureThis() ?? $reflection->getClosureCalledClass()?->getName();
            $this->assertTrue($owner === $extension || $owner === IdnoTwigExtension::class, $label . ' is a method of the extension (a call is compiled to a call of the method)');
        }
    }

    public function testATemplateIsDrawnWithoutBuildingTheExtensions(): void
    {
        $directory = $this->root . '/twig-cache';
        $template = "{{ site_url('a') }} {{ t('Hi') }} {{ setting('title') }} {{ url_with_var('offset', 5) }} {{ is_logged_in() ? 'in' : 'out' }} {{ 'https://e.example/x'|http_url }} {{ 'a b'|autop }}";

        // The first environment compiles the template, which needs everything of the extensions
        $first = $this->environment($directory, $template);
        $expected = $first->render('a.html.twig');
        $this->assertTrue(self::initialised($first), 'compiling a template builds the extensions');
        $this->assertStringContainsString('https://site.example/a', $expected);

        // A request that finds the compiled file draws it from the calls the file holds
        $second = $this->environment($directory, $template);
        $this->assertSame($expected, $second->render('a.html.twig'));
        $this->assertFalse(self::initialised($second), 'drawing a compiled template looks no function or filter up: Twig built every function, filter, tag and operator of every extension for the first one it looked up');
    }

    private static function initialised(Environment $twig): bool
    {
        // ExtensionSet::isInitialized() is also true once a template is loaded: the property is the one that says every function, filter, tag
        // and operator was built
        $set = (new \ReflectionProperty(Environment::class, 'extensionSet'))->getValue($twig);

        return (bool) (new \ReflectionProperty($set, 'initialized'))->getValue($set);
    }

    private function extension(): IdnoTwigExtension
    {
        return new IdnoTwigExtension(new class implements TemplateTranslation {
            public function translate(string $text, array $subs = []): string
            {
                return $text;
            }
        }, new TemplateHelpersDouble(), new RequestStack());
    }

    private function environment(string $cache, string $template): Environment
    {
        $twig = new Environment(new ArrayLoader(['a.html.twig' => $template]), ['cache' => new FilesystemCache($cache), 'autoescape' => 'name', 'auto_reload' => false]);
        $twig->addExtension($this->extension());

        return $twig;
    }
}
