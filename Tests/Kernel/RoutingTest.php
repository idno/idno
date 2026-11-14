<?php

declare(strict_types=1);

namespace Tests\Kernel;

use PHPUnit\Framework\TestCase;

/**
 * The legacy catch-all exists in every environment; the test-only fixture routes exist
 * only in the test environment.
 */
final class RoutingTest extends TestCase
{
    public function testProdHasTheLegacyCatchAllAndNoTestRoutes(): void
    {
        $this->assertSame(
            ['idno_legacy' => '/{path}', 'idno_test_ping' => null],
            $this->routesInCleanProcess('prod', false)
        );
    }

    public function testTestEnvironmentHasThePingRoute(): void
    {
        $this->assertSame(
            ['idno_legacy' => '/{path}', 'idno_test_ping' => '/_test/ping'],
            $this->routesInCleanProcess('test', true)
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function routesInCleanProcess(string $environment, bool $debug): array
    {
        $cacheDir = sys_get_temp_dir() . '/idno-test-routes-' . bin2hex(random_bytes(6));
        $command = 'IDNO_CACHE_DIR=' . escapeshellarg($cacheDir) . ' ' . escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 '
            . escapeshellarg(__DIR__ . '/boot_routes.php') . ' ' . escapeshellarg($environment) . ' ' . ($debug ? '1' : '0') . ' 2>&1';
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        $this->removeDir($cacheDir);

        $this->assertSame(0, $code, 'Subprocess failed: ' . implode("\n", $output));
        $decoded = json_decode(implode("\n", $output), true);
        $this->assertIsArray($decoded, 'Subprocess output was not JSON: ' . implode("\n", $output));

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
}
