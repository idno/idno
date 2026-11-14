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

    public function testBootDoesNotRegisterErrorOrExceptionHandlers(): void
    {
        $errorBefore = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        $exceptionBefore = set_exception_handler(null);
        restore_exception_handler();

        $this->boot();

        $errorAfter = set_error_handler(static fn (): bool => false);
        restore_error_handler();
        $exceptionAfter = set_exception_handler(null);
        restore_exception_handler();

        $this->assertSame($errorBefore, $errorAfter);
        $this->assertSame($exceptionBefore, $exceptionAfter);
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
