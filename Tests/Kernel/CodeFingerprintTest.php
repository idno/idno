<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Kernel\CodeFingerprint;
use PHPUnit\Framework\TestCase;

final class CodeFingerprintTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    /** @var list<string> */
    private array $unreadable = [];

    protected function tearDown(): void
    {
        foreach ($this->unreadable as $path) {
            @chmod($path, 0755);
        }
        $this->unreadable = [];
        foreach ($this->dirs as $dir) {
            $this->removeDir($dir);
        }
        $this->dirs = [];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/idno-test-fingerprint-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->dirs[] = $dir;

        return $dir;
    }

    private function write(string $dir, string $path, string $content, int $mtime = 1_700_000_000): void
    {
        $file = $dir . '/' . $path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
        touch($file, $mtime);
        clearstatcache(true, $file);
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

    private function compute(string $dir): string
    {
        // compute() stats files; a test that changes a file and recomputes in one process
        // must not see PHP's cached stat results.
        clearstatcache();

        return CodeFingerprint::compute($dir);
    }

    private function project(): string
    {
        $dir = $this->tempDir();
        $this->write($dir, 'version.idno', "version = '1'\nbuild = 1\n");
        $this->write($dir, 'config/services.php', '<?php return 1;');
        $this->write($dir, 'Idno/Kernel.php', '<?php class K {}');
        $this->write($dir, 'Idno/Kernel/A.php', '<?php class A {}');
        $this->write($dir, 'vendor/composer/installed.php', '<?php return [];');

        return $dir;
    }

    public function testIsTwelveLowercaseHexCharacters(): void
    {
        $this->assertSame(1, preg_match('/^[0-9a-f]{12}$/', $this->compute($this->project())));
    }

    public function testIsStableForUnchangedTree(): void
    {
        $dir = $this->project();

        $this->assertSame($this->compute($dir), $this->compute($dir));
    }

    public function testChangesWhenAConfigFileChanges(): void
    {
        $dir = $this->project();
        $before = $this->compute($dir);

        $this->write($dir, 'config/services.php', '<?php return 12345;', 1_700_000_100);

        $this->assertNotSame($before, $this->compute($dir));
    }

    public function testChangesWhenMtimeAloneChanges(): void
    {
        $dir = $this->project();
        $before = $this->compute($dir);

        touch($dir . '/config/services.php', 1_700_000_500);

        $this->assertNotSame($before, $this->compute($dir));
    }

    public function testChangesWhenAScannedClassIsAdded(): void
    {
        $dir = $this->project();
        $before = $this->compute($dir);

        $this->write($dir, 'Idno/Legacy/New.php', '<?php class N {}');

        $this->assertNotSame($before, $this->compute($dir));
    }

    public function testChangesWhenAScannedDirectoryAppears(): void
    {
        $dir = $this->project();
        $this->assertDirectoryDoesNotExist($dir . '/Idno/Http');
        $before = $this->compute($dir);

        $this->write($dir, 'Idno/Http/X.php', '<?php class X {}');

        $this->assertNotSame($before, $this->compute($dir));
    }

    public function testChangesWhenVersionFileChanges(): void
    {
        $dir = $this->project();
        $before = $this->compute($dir);

        $this->write($dir, 'version.idno', "version = '1'\nbuild = 22\n", 1_700_000_100);

        $this->assertNotSame($before, $this->compute($dir));
    }

    public function testChangesWhenInstalledPackagesChange(): void
    {
        $dir = $this->project();
        $before = $this->compute($dir);

        $this->write($dir, 'vendor/composer/installed.php', '<?php return [1];', 1_700_000_100);

        $this->assertNotSame($before, $this->compute($dir));
    }

    public function testIgnoresGeneratedReferenceFile(): void
    {
        $dir = $this->project();
        $before = $this->compute($dir);

        $this->write($dir, 'config/reference.php', '<?php return [];');
        $this->assertSame($before, $this->compute($dir));

        $this->write($dir, 'config/reference.php', '<?php return [1, 2, 3];', 1_700_000_900);
        $this->assertSame($before, $this->compute($dir));
    }

    public function testIgnoresUnscannedLegacyCode(): void
    {
        $dir = $this->project();
        $before = $this->compute($dir);

        $this->write($dir, 'Idno/Core/Whatever.php', '<?php class W {}');

        $this->assertSame($before, $this->compute($dir));
    }

    public function testIgnoresNonPhpFiles(): void
    {
        $dir = $this->project();
        $before = $this->compute($dir);

        $this->write($dir, 'config/notes.txt', 'hello');

        $this->assertSame($before, $this->compute($dir));
    }

    public function testMissingInputsAreSkipped(): void
    {
        $dir = $this->tempDir();
        $warnings = [];
        set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
            $warnings[] = $str;

            return true;
        });
        try {
            $fingerprint = $this->compute($dir);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
        $this->assertSame(1, preg_match('/^[0-9a-f]{12}$/', $fingerprint));
    }

    public function testIndependentOfIterationOrder(): void
    {
        $files = ['config/b.php', 'config/a.php', 'Idno/Kernel/Z.php', 'Idno/Kernel/M.php', 'Idno/Legacy/Q.php'];
        $first = $this->tempDir();
        $second = $this->tempDir();
        foreach ($files as $file) {
            $this->write($first, $file, '<?php // ' . $file);
        }
        foreach (array_reverse($files) as $file) {
            $this->write($second, $file, '<?php // ' . $file);
        }

        $this->assertSame($this->compute($first), $this->compute($second));
    }

    public function testChangesWhenTheKernelClassFileChanges(): void
    {
        $dir = $this->project();
        $before = $this->compute($dir);

        touch($dir . '/Idno/Kernel.php', 1_700_000_700);

        $this->assertNotSame($before, $this->compute($dir));
    }

    public function testUnreadableSubdirectoryIsSkipped(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Permissions are not enforced for root.');
        }
        $dir = $this->project();
        $this->write($dir, 'config/sub/inner.php', '<?php return 1;');
        $before = $this->compute($dir);

        $this->unreadable[] = $dir . '/config/sub';
        chmod($dir . '/config/sub', 0000);
        $after = $this->compute($dir);

        $this->assertSame(1, preg_match('/^[0-9a-f]{12}$/', $after));
        $this->assertNotSame($before, $after);

        // Other files in the same root still count.
        $this->write($dir, 'config/services.php', '<?php return 99999;', 1_700_000_300);
        $this->assertNotSame($after, $this->compute($dir));
    }

    public function testRouteImportDirectoriesAreAllFingerprinted(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/config/routes.php');
        $this->assertGreaterThanOrEqual(2, (int) preg_match_all("#/Idno/(\\w+)'#", $source, $matches), 'routes.php no longer names its Idno/ directories (Upgrade, Legacy) in the expected form');

        foreach ($matches[1] as $name) {
            $this->assertContains($name, CodeFingerprint::SERVICE_DIRECTORIES);
        }
    }
}
