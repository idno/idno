<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\EventDispatcher\Debug\WrappedListener;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * DoctrineBundle brings only the DBAL connection: Idno\Kernel\DoctrineTrimPass removes every
 * console command the bundle registers (dbal:run-sql, doctrine:database:create and
 * doctrine:database:drop today, and any it adds later) and its idle-connection request listener.
 * Checked in the test container (PHPUnit's kernel) and in a freshly compiled prod container.
 */
final class DoctrineTrimTest extends TestCase
{
    private ?Kernel $kernel = null;

    private ?string $cacheDir = null;

    private mixed $previousCacheDir = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
        if ($this->cacheDir !== null) {
            self::removeDir($this->cacheDir);
            $this->cacheDir = null;
            if ($this->previousCacheDir === null) {
                unset($_SERVER['IDNO_CACHE_DIR']);
            } else {
                $_SERVER['IDNO_CACHE_DIR'] = $this->previousCacheDir;
            }
        }
    }

    private static function removeDir(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeDir($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
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
    public function testNoDoctrineCommandIsRegistered(string $environment): void
    {
        $kernel = $this->kernel($environment);
        $this->assertSame($environment, $kernel->getEnvironment());
        $this->assertTrue($kernel->getContainer()->has('database_connection'), 'the connection service is still there');

        $names = array_keys((new Application($kernel))->all());

        $this->assertContains('cache:clear', $names, 'Symfony\'s own commands are still there');
        $doctrine = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'doctrine:') || str_starts_with($name, 'dbal:')));
        $this->assertSame([], $doctrine);
    }

    /**
     * @dataProvider kernels
     */
    public function testNoDoctrineRequestListener(string $environment): void
    {
        $dispatcher = $this->kernel($environment)->getContainer()->get('event_dispatcher');
        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $classes = [];
        foreach ($dispatcher->getListeners(KernelEvents::REQUEST) as $listener) {
            if ($listener instanceof WrappedListener) {
                $listener = $listener->getWrappedListener();
            }
            $classes[] = is_array($listener) && is_object($listener[0]) ? $listener[0]::class : get_debug_type($listener);
        }

        $this->assertNotSame([], $classes, 'the dispatcher has request listeners');
        foreach ($classes as $class) {
            $this->assertStringNotContainsString('Doctrine', $class);
        }
    }
}
