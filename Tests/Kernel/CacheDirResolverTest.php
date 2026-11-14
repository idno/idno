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

        $fallback = sys_get_temp_dir() . '/idno-' . substr(sha1($project), 0, 12);
        $this->assertSame($fallback, CacheDirResolver::resolve($project, null));
        $this->assertSame(0700, fileperms($fallback) & 0777);
    }

    public function testFallbackThatIsGroupOrWorldWritableIsRejected(): void
    {
        $project = $this->tempDir();
        chmod($project, 0555);
        if (is_writable($project)) {
            $this->markTestSkipped('Directory permissions are not enforced (running as root?).');
        }
        $fallback = sys_get_temp_dir() . '/idno-' . substr(sha1($project), 0, 12);
        mkdir($fallback, 0777);
        chmod($fallback, 0777);

        try {
            CacheDirResolver::resolve($project, null);
            $this->fail('Expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($fallback, $e->getMessage());
        }
    }

    public function testExceptionNamesAllLocationsWhenNothingIsUsable(): void
    {
        $project = $this->tempDir();
        chmod($project, 0555);
        if (is_writable($project)) {
            $this->markTestSkipped('Directory permissions are not enforced (running as root?).');
        }
        $fallback = sys_get_temp_dir() . '/idno-' . substr(sha1($project), 0, 12);
        mkdir($fallback, 0777);
        chmod($fallback, 0777);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('#/proc/nonexistent/x.*' . preg_quote($project . '/var/cache', '#') . '.*' . preg_quote($fallback, '#') . '.*IDNO_CACHE_DIR#');
        CacheDirResolver::resolve($project, '/proc/nonexistent/x');
    }

    public function testOverrideThatExistsButIsNotWritableIsSkipped(): void
    {
        $project = $this->tempDir();
        $override = $this->tempDir();
        chmod($override, 0555);
        if (is_writable($override)) {
            $this->markTestSkipped('Directory permissions are not enforced (running as root?).');
        }

        $this->assertSame($project . '/var/cache', CacheDirResolver::resolve($project, $override));
    }

    public function testUnusableOverrideIsSkipped(): void
    {
        $project = $this->tempDir();

        $this->assertSame($project . '/var/cache', CacheDirResolver::resolve($project, '/proc/nonexistent/x'));
    }
}
