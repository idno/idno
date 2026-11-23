<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\HttpKernel\KernelEvents;
use Twig\Environment;

/**
 * TwigBundle brings the Twig environment, `lint:twig` and `debug:twig`: Idno\Kernel\TwigTrimPass removes what Idno does not use
 * (the error renderer's decoration, which would change the error page Symfony draws; the mailer's listener and body renderer) and
 * takes the bundle's own filesystem loader out of the chain, so that the cascade's loader is the only one. Checked in the test
 * container (PHPUnit's kernel) and in a freshly compiled prod container, with no legacy application (so also what
 * `debug:twig` needs while the schema gate is closed).
 */
final class TwigTrimTest extends TestCase
{
    private ?Kernel $kernel = null;

    private ?string $cacheDir = null;

    private mixed $previousCacheDir = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        if ($this->cacheDir !== null) {
            exec('rm -rf ' . escapeshellarg($this->cacheDir));
            $this->cacheDir = null;
            if ($this->previousCacheDir === null) {
                unset($_SERVER['IDNO_CACHE_DIR']);
            } else {
                $_SERVER['IDNO_CACHE_DIR'] = $this->previousCacheDir;
            }
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function kernels(): array
    {
        return ['test (PHPUnit\'s kernel)' => ['test'], 'prod (freshly compiled)' => ['prod']];
    }

    private function kernel(string $environment): Kernel
    {
        if ($environment === 'test') {
            $kernel = Idno::container()->get('kernel');
            $this->assertInstanceOf(Kernel::class, $kernel);

            return $kernel;
        }
        $this->previousCacheDir = $_SERVER['IDNO_CACHE_DIR'] ?? null;
        $this->cacheDir = sys_get_temp_dir() . '/idno-test-cache-' . bin2hex(random_bytes(6));
        $_SERVER['IDNO_CACHE_DIR'] = $this->cacheDir;
        $this->kernel = new Kernel($environment, false, bootLegacy: false);
        $this->kernel->boot();

        return $this->kernel;
    }

    /**
     * @dataProvider kernels
     */
    public function testOnlyLintTwigAndDebugTwigAreRegistered(string $environment): void
    {
        $kernel = $this->kernel($environment);

        $twig = [];
        foreach ((new Application($kernel))->all() as $name => $command) {
            if (str_contains($name, 'twig') || str_contains($command::class, 'Twig')) {
                $twig[] = $name;
            }
        }
        sort($twig);

        $this->assertSame(['debug:twig', 'lint:twig'], $twig);
    }

    /**
     * The code the compiled container instantiates, all of its files (a service's factory is in a file of its own).
     */
    private function compiledContainer(Kernel $kernel): string
    {
        $source = '';
        foreach (glob(dirname((string) (new \ReflectionClass($kernel->getContainer()))->getFileName()) . '/*.php') ?: [] as $file) {
            $source .= (string) file_get_contents($file);
        }

        return $source;
    }

    /**
     * @dataProvider kernels
     */
    public function testNothingOfTheErrorRendererOrTheMailerIsRegistered(string $environment): void
    {
        $source = $this->compiledContainer($this->kernel($environment));

        $this->assertStringContainsString('HtmlErrorRenderer', $source, 'Symfony\'s own error page is what is drawn');
        $this->assertStringContainsString('CascadeTwigLoader', $source);
        foreach (['TwigErrorRenderer', 'Symfony\\Bridge\\Twig\\Mime\\BodyRenderer', 'Symfony\\Component\\Mailer\\EventListener\\MessageListener', 'Twig\\Loader\\FilesystemLoader'] as $class) {
            $this->assertStringNotContainsString($class, $source, $class . ' is not built by the container');
        }
    }

    public function testTheCascadesLoaderIsTheOnlyLoader(): void
    {
        $twig = $this->kernel('test')->getContainer()->get('idno.test.twig');

        $this->assertInstanceOf(Environment::class, $twig);
        $this->assertInstanceOf(\Idno\Templating\CascadeTwigLoader::class, $twig->getLoader());
        $this->assertFalse($twig->getLoader()->exists('default/shell.tpl.php'));
        $this->assertFalse($twig->getLoader()->exists('@Twig/Exception/error.html.twig'));
    }

    public function testTheEnvironmentIsNotBuiltWhenTheKernelBootsOrWhenTheListenersAreBuilt(): void
    {
        $container = $this->kernel('prod')->getContainer();
        $this->assertFalse($container->initialized('twig'), 'booting a kernel builds no Twig environment');

        $dispatcher = $container->get('event_dispatcher');
        foreach ([KernelEvents::REQUEST, KernelEvents::CONTROLLER, KernelEvents::VIEW, KernelEvents::RESPONSE, KernelEvents::EXCEPTION, KernelEvents::TERMINATE] as $event) {
            $dispatcher->getListeners($event);
        }

        $this->assertFalse($container->initialized('twig'), 'neither does asking for the listeners of an event (the Twig template attribute listener holds it lazily)');
    }

    /**
     * @dataProvider kernels
     */
    public function testDebugTwigRunsWithoutTheLegacyApplication(string $environment): void
    {
        $kernel = $this->kernel($environment);
        if ($environment === 'prod') {
            $this->assertFalse($kernel->legacyBooted());
        }
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $exit = $tester->run(['command' => 'debug:twig', '--format' => 'json'], ['capture_stderr_separately' => true]);

        $this->assertSame(0, $exit, $tester->getErrorOutput());
        $listing = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($listing);
        $this->assertContains('idno_include', array_keys($listing['functions']));
        $this->assertContains('slot', array_keys($listing['functions']));
        $this->assertContains('t', array_keys($listing['functions']));
    }

    /**
     * @dataProvider kernels
     */
    public function testTheHttpKernelExtensionIsGone(string $environment): void
    {
        $kernel = $this->kernel($environment);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $this->assertSame(0, $tester->run(['command' => 'debug:twig', '--format' => 'json'], ['capture_stderr_separately' => true]), $tester->getErrorOutput());
        $functions = array_keys(json_decode($tester->getDisplay(), true)['functions']);

        foreach (['render', 'render_esi', 'render_hinclude', 'controller', 'fragment_uri'] as $name) {
            $this->assertNotContains($name, $functions, $name . '() would draw another request\'s page into a template');
        }
        $this->assertContains('path', $functions, 'routing\'s path() stays');
        $this->assertStringNotContainsString('Symfony\\Bridge\\Twig\\Extension\\HttpKernelExtension', $this->compiledContainer($kernel));
    }

    /**
     * @dataProvider kernels
     */
    public function testTheCompiledTemplateCacheIsTheTolerantOneAndTheBundlesWarmerIsGone(string $environment): void
    {
        $kernel = $this->kernel($environment);
        $source = $this->compiledContainer($kernel);

        $this->assertStringContainsString('TolerantTwigCache', $source);
        $this->assertStringContainsString('TwigCacheWarmer', $source);
        $this->assertStringNotContainsString('TwigBundle\\CacheWarmer\\TemplateCacheWarmer', $source);
    }

    /**
     * @dataProvider kernels
     */
    public function testNoMailListenerIsRegistered(string $environment): void
    {
        $dispatcher = $this->kernel($environment)->getContainer()->get('event_dispatcher');

        $this->assertSame([], $dispatcher->getListeners(\Symfony\Component\Mailer\Event\MessageEvent::class));
    }
}
