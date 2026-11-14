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
