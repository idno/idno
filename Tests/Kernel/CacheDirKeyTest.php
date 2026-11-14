<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * Two installs that share IDNO_CACHE_DIR must never share a compiled container: the
 * container holds the project directory's absolute path (it reads that install's
 * config.ini), so the cache directory is keyed by the project directory as well as the code.
 */
final class CacheDirKeyTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    private mixed $previousCacheDir = null;

    protected function setUp(): void
    {
        $this->previousCacheDir = $_SERVER['IDNO_CACHE_DIR'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousCacheDir === null) {
            unset($_SERVER['IDNO_CACHE_DIR']);
        } else {
            $_SERVER['IDNO_CACHE_DIR'] = $this->previousCacheDir;
        }
        foreach ($this->dirs as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        $this->dirs = [];
    }

    private function tempDir(string $name): string
    {
        $dir = sys_get_temp_dir() . '/idno-test-' . $name . '-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->dirs[] = $dir;

        return $dir;
    }

    /**
     * A project tree whose files, sizes and modification times are byte-for-byte the same
     * wherever it is created.
     */
    private function project(): string
    {
        $dir = $this->tempDir('project');
        $files = [
            'version.idno' => "version = '1'\nbuild = 7\n",
            'Idno/Kernel.php' => '<?php class K {}',
            'config/services.php' => '<?php return 1;',
            'Idno/Kernel/A.php' => '<?php class A {}',
            'vendor/composer/installed.php' => '<?php return [];',
        ];
        foreach ($files as $path => $content) {
            $file = $dir . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $content);
            touch($file, 1_700_000_000);
        }
        clearstatcache();

        return $dir;
    }

    private function kernel(string $projectDir): Kernel
    {
        return new class ('prod', false, false, $projectDir) extends Kernel {
            public function __construct(string $environment, bool $debug, bool $bootLegacy, private readonly string $dir)
            {
                parent::__construct($environment, $debug, $bootLegacy);
            }

            public function getProjectDir(): string
            {
                return $this->dir;
            }
        };
    }

    public function testIdenticalTreesAtDifferentPathsGetDifferentCacheDirectories(): void
    {
        $_SERVER['IDNO_CACHE_DIR'] = $this->tempDir('shared-cache');
        $first = $this->project();
        $second = $this->project();

        $a = $this->kernel($first)->getCacheDir();
        $b = $this->kernel($second)->getCacheDir();

        $this->assertSame(dirname($a), dirname($b), 'both are under the same shared cache base');
        $this->assertNotSame($a, $b);
        $this->assertSame(1, preg_match('/^7-[0-9a-f]{12}$/', basename($a)), basename($a));
    }

    public function testTheSameTreeGetsTheSameCacheDirectory(): void
    {
        $_SERVER['IDNO_CACHE_DIR'] = $this->tempDir('shared-cache');
        $project = $this->project();

        $this->assertSame($this->kernel($project)->getCacheDir(), $this->kernel($project)->getCacheDir());
    }

    public function testASymlinkedPathGetsTheSameCacheDirectoryAsTheRealPath(): void
    {
        $_SERVER['IDNO_CACHE_DIR'] = $this->tempDir('shared-cache');
        $project = $this->project();
        $link = $this->tempDir('link') . '/site';
        symlink($project, $link);

        $this->assertSame($this->kernel($project)->getCacheDir(), $this->kernel($link)->getCacheDir());
    }
}
