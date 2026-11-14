<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelBootTest extends TestCase
{
    private ?Kernel $kernel = null;

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    private function boot(): Kernel
    {
        $this->kernel = new Kernel('test', true, bootLegacy: false);
        $this->kernel->boot();
        return $this->kernel;
    }

    public function testKernelBootsWithoutLegacy(): void
    {
        $this->assertTrue($this->boot()->getContainer()->has('router'));
    }

    public function testSymfonySessionIsDisabled(): void
    {
        $this->assertFalse($this->boot()->getContainer()->has('session.factory'));
    }

    public function testProjectDirIsRepositoryRoot(): void
    {
        $this->assertFileExists($this->boot()->getProjectDir() . '/version.idno');
    }

    /**
     * @return array{error_unchanged: bool, exception_unchanged: bool}
     */
    private function bootInCleanProcess(string $scenario): array
    {
        $cacheDir = sys_get_temp_dir() . '/idno-test-handlers-' . bin2hex(random_bytes(6));
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 '
            . escapeshellarg(__DIR__ . '/boot_handlers.php') . ' ' . escapeshellarg($scenario) . ' 2>&1';
        $output = [];
        $code = 0;
        exec('IDNO_CACHE_DIR=' . escapeshellarg($cacheDir) . ' ' . $command, $output, $code);
        $this->removeDir($cacheDir);

        $decoded = json_decode(implode("\n", $output), true);
        $this->assertIsArray($decoded, 'Subprocess output was not JSON: ' . implode("\n", $output));
        $this->assertSame(0, $code);

        return $decoded;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $file) {
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    public function testBootInstallsNoHandlersWhenNoneWereSet(): void
    {
        $this->assertSame(
            ['error_unchanged' => true, 'exception_unchanged' => true],
            $this->bootInCleanProcess('none')
        );
    }

    public function testBootLeavesExistingHandlersInPlace(): void
    {
        $this->assertSame(
            ['error_unchanged' => true, 'exception_unchanged' => true],
            $this->bootInCleanProcess('custom')
        );
    }

    public function testLegacyBootLeavesTheLegacyErrorHandlerInPlace(): void
    {
        $cacheDir = sys_get_temp_dir() . '/idno-test-legacy-handlers-' . bin2hex(random_bytes(6));
        $command = escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -d display_errors=stderr '
            . escapeshellarg(__DIR__ . '/boot_legacy_handlers.php');
        $env = ['IDNO_CACHE_DIR' => $cacheDir, 'PATH' => (string) getenv('PATH')];
        foreach (['IDNO_DOMAIN', 'IDNO_PORT'] as $name) {
            if (getenv($name) !== false) {
                $env[$name] = (string) getenv($name);
            }
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $this->removeDir($cacheDir);

        $this->assertSame(0, $code, "Subprocess failed:\n$stdout\n$stderr");
        $this->assertSame(['legacy_owns_error_handler' => true], json_decode($stdout, true), "Subprocess output:\n$stdout\n$stderr");
    }

    public function testCacheDirIncludesTheBuildNumber(): void
    {
        $this->kernel = new Kernel('test', true, bootLegacy: false);
        $build = parse_ini_file($this->kernel->getProjectDir() . '/version.idno')['build'];

        $this->assertStringEndsWith('/test/' . $build, $this->kernel->getCacheDir());
    }

    public function testCacheDirFallsBackToDevWithoutAVersionFile(): void
    {
        $projectDir = sys_get_temp_dir() . '/idno-test-noversion-' . bin2hex(random_bytes(6));
        mkdir($projectDir);
        $previous = $_SERVER['IDNO_CACHE_DIR'] ?? null;
        $_SERVER['IDNO_CACHE_DIR'] = $projectDir . '/cache';
        try {
            $kernel = new class ('test', true, false, $projectDir) extends Kernel {
                public function __construct(string $environment, bool $debug, bool $bootLegacy, private readonly string $dir)
                {
                    parent::__construct($environment, $debug, $bootLegacy);
                }

                public function getProjectDir(): string
                {
                    return $this->dir;
                }
            };

            $this->assertSame($projectDir . '/cache/test/dev', $kernel->getCacheDir());
        } finally {
            if ($previous === null) {
                unset($_SERVER['IDNO_CACHE_DIR']);
            } else {
                $_SERVER['IDNO_CACHE_DIR'] = $previous;
            }
            $this->removeDir($projectDir);
        }
    }

    public function testCompiledContainerContainsNoSecrets(): void
    {
        $kernel = $this->boot();
        $dump = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($kernel->getCacheDir(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $dump .= file_get_contents($file->getPathname());
            }
        }
        $this->assertStringNotContainsString('site_secret', $dump);
        $this->assertStringNotContainsString('dbpass', $dump);
    }
}
