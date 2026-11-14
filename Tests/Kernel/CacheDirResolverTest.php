<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Kernel\CacheDirResolver;
use PHPUnit\Framework\TestCase;

final class CacheDirResolverTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/idno-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->dirs[] = $dir;
        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->remove($dir);
        }
        // Fallback directory created under the system temp dir by the fallback test.
        foreach ($this->dirs as $dir) {
            $this->remove(sys_get_temp_dir() . '/idno-' . substr(sha1($dir), 0, 12));
        }
        $this->dirs = [];
    }

    private function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            @chmod($path, 0777);
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            @rmdir($path);
            return;
        }
        @unlink($path);
    }

    public function testOverrideWins(): void
    {
        $project = $this->tempDir();
        $override = $this->tempDir();

        $this->assertSame($override, CacheDirResolver::resolve($project, $override));
    }

    public function testProjectVarCacheUsedWhenWritable(): void
    {
        $project = $this->tempDir();

        $this->assertSame($project . '/var/cache', CacheDirResolver::resolve($project, null));
        $this->assertDirectoryExists($project . '/var/cache');
    }

    public function testFallsBackToTempWhenProjectNotWritable(): void
    {
        $project = $this->tempDir();
        chmod($project, 0555);
        if (is_writable($project)) {
            $this->markTestSkipped('Directory permissions are not enforced (running as root?).');
        }

        $this->assertSame(
            sys_get_temp_dir() . '/idno-' . substr(sha1($project), 0, 12),
            CacheDirResolver::resolve($project, null)
        );
    }

    public function testUnusableOverrideIsSkipped(): void
    {
        $project = $this->tempDir();

        $this->assertSame($project . '/var/cache', CacheDirResolver::resolve($project, '/proc/nonexistent/x'));
    }
}
