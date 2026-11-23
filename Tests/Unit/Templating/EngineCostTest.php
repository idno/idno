<?php

declare(strict_types=1);

namespace Tests\Unit\Templating;

use Idno\Templating\CascadeTwigLoader;
use Idno\Templating\IdnoTwigExtension;
use Idno\Templating\LocatedTemplate;
use Idno\Templating\PhpTemplateContext;
use Idno\Templating\TwigTemplateEngine;
use Idno\Templating\TemplateCascade;
use Idno\Templating\TemplateLocator;
use Idno\Templating\TemplateTranslation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Cache\FilesystemCache;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\ArrayLoader;
use Twig\Source;

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

    public function testAPlainMethodCallIsCompiledToACallOfTheExtension(): void
    {
        $twig = $this->environment($this->root . '/c', '{{ o.getName() }}{{ o.getName(1, 2) }}{{ o?.getName() }}{{ o.getName() is defined ? 1 : 0 }}{{ o.name }}{{ o.name() }}');
        $php = $twig->compileSource(new Source('{{ o.getName() }}{{ o.getName(1, 2) }}{{ o?.getName() }}{{ o.getName() is defined ? 1 : 0 }}{{ o.name }}{{ o.name() }}', 'a.html.twig'));

        $this->assertSame(2 + 1, substr_count($php, '->callMethod('), 'o.getName(), o.getName(1, 2) and o.name() are calls of the extension');
        $this->assertSame(3, substr_count($php, 'CoreExtension::getAttribute('), 'a null-safe call, an `is defined` test and a property are compiled as Twig does');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function objects(): array
    {
        $callable = new class {
            public function getName(mixed ...$a): string
            {
                return 'public' . implode(',', $a);
            }

            public function isReady(): bool
            {
                return true;
            }

            private function secret(): string
            {
                return 'private';
            }

            public function __call(string $name, array $arguments): string
            {
                return 'magic ' . $name;
            }
        };
        $plain = new class {
            public function getName(): string
            {
                return 'plain';
            }

            protected function hidden(): string
            {
                return 'protected';
            }
        };

        return ['public method' => [$plain], 'object with __call' => [$callable], 'null' => [null], 'array' => [['getName' => 1]], 'string' => ['text'], 'closure' => [static fn (): string => 'closure']];
    }

    /**
     * @dataProvider objects
     */
    public function testTheFastMethodCallAnswersWhatTwigsAttributeAccessAnswers(mixed $object): void
    {
        $extension = $this->extension();
        $twig = new Environment(new ArrayLoader([]), ['cache' => false]);
        $source = new Source('', 'a');
        foreach (['getName', 'getname', 'name', 'isReady', 'ready', 'secret', 'hidden', 'missing', '__invoke'] as $name) {
            foreach ([[], ['x', 2]] as $arguments) {
                $expected = \Twig\Extension\CoreExtension::getAttribute($twig, $source, $object, $name, $arguments, \Twig\Template::METHOD_CALL, false, true, false, 1);
                $this->assertSame($expected, $extension->callMethod($object, $name, $arguments, $twig, $source, true, 1), $name . ' on ' . get_debug_type($object));
            }
        }
    }

    public function testAMissingMethodIsAnErrorWhenVariablesAreStrict(): void
    {
        $twig = new Environment(new ArrayLoader([]), ['cache' => false, 'strict_variables' => true]);
        $this->expectException(\Twig\Error\RuntimeError::class);
        $this->extension()->callMethod(new \stdClass(), 'getNothing', [], $twig, new Source('', 'a'), false, 1);
    }

    public function testATemplateIsLoadedOnceForTheFileItIsDrawnFrom(): void
    {
        $file = $this->file('core/templates/default/x.html.twig', 'x {{ a }}');
        $cascade = new TemplateCascade($this->root . '/core');
        $built = 0;
        $engine = new TwigTemplateEngine(function () use ($cascade, &$built): Environment {
            ++$built;
            $twig = new Environment(new CascadeTwigLoader($cascade), ['cache' => false]);
            $twig->addExtension($this->extension());

            return $twig;
        });
        $context = $this->createStub(PhpTemplateContext::class);
        $located = new LocatedTemplate($file, 'twig', 'default', $this->root . '/core', 'default/x.html.twig');

        $this->assertSame('x 1', $engine->render($located, ['a' => 1], $context));
        $this->assertSame('x 2', $engine->render($located, ['a' => 2], $context));
        $this->assertSame(1, $built, 'the second draw asks Twig for nothing');
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
