<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use Idno\Cache\CachePoolFactory;
use PHPUnit\Framework\TestCase;

final class CachePoolFactoryTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            exec('chmod -R u+rwx ' . escapeshellarg($dir) . ' 2>/dev/null; rm -rf ' . escapeshellarg($dir));
        }
        $this->dirs = [];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/idno-cache-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        $this->dirs[] = $dir;

        return $dir;
    }

    private function filesUnder(string $dir): int
    {
        $count = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $count += $file->isFile() ? 1 : 0;
        }

        return $count;
    }

    public function testCachepathWinsWhenSet(): void
    {
        $path = $this->tempDir();
        $base = $this->tempDir();

        $this->assertSame($path, CachePoolFactory::directory(['cachepath' => $path], $base));

        $pool = CachePoolFactory::create(['cachepath' => $path], $base, 'example.com');
        $item = $pool->getItem('k')->set('v');
        $pool->save($item);

        $this->assertSame(1, $this->filesUnder($path));
        $this->assertSame(0, $this->filesUnder($base));
    }

    public function testOtherwiseTheCacheBaseDirectoryHoldsAPoolDirectory(): void
    {
        $base = $this->tempDir();

        $this->assertSame($base . '/pool', CachePoolFactory::directory([], $base));
        $this->assertSame($base . '/pool', CachePoolFactory::directory(['cachepath' => ''], $base));

        $pool = CachePoolFactory::create([], $base, 'example.com');
        $pool->save($pool->getItem('k')->set('v'));

        $this->assertSame(1, $this->filesUnder($base . '/pool'));
    }

    public function testAnUnwritableDirectoryFallsBackToTheTemporaryDirectory(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can write to any directory');
        }
        $locked = $this->tempDir();
        chmod($locked, 0500);

        $this->assertSame(sys_get_temp_dir(), CachePoolFactory::directory(['cachepath' => $locked . '/sub'], $locked . '/base'));
        $this->assertSame(sys_get_temp_dir(), CachePoolFactory::directory([], $locked . '/base'));
        // The unusable cachepath falls through to the cache base, when that is usable.
        $base = $this->tempDir();
        $this->assertSame($base . '/pool', CachePoolFactory::directory(['cachepath' => $locked . '/sub'], $base));
    }

    public function testTheUploadPathIsNeverUsed(): void
    {
        $uploads = $this->tempDir();
        $elsewhere = $this->tempDir();

        // Not by default, and not when a configured directory lies inside it.
        $this->assertSame($elsewhere . '/pool', CachePoolFactory::directory(['uploadpath' => $uploads . '/'], $elsewhere));
        $this->assertSame($elsewhere . '/pool', CachePoolFactory::directory(['uploadpath' => $uploads . '/', 'cachepath' => $uploads . '/cache'], $elsewhere));
        $this->assertSame(sys_get_temp_dir(), CachePoolFactory::directory(['uploadpath' => $uploads . '/'], $uploads));

        $pool = CachePoolFactory::create(['uploadpath' => $uploads . '/'], $elsewhere, 'example.com');
        $pool->save($pool->getItem('k')->set('v'));
        $this->assertSame(0, $this->filesUnder($uploads));
    }

    public function testTwoHostsDoNotShareEntries(): void
    {
        $base = $this->tempDir();
        $a = CachePoolFactory::create([], $base, 'a.example.com');
        $b = CachePoolFactory::create([], $base, 'b.example.com');

        $a->save($a->getItem('k')->set('from a'));

        $this->assertTrue($a->getItem('k')->isHit());
        $this->assertFalse($b->getItem('k')->isHit());
    }

    public function testTwoPoolsOnTheSameDirectorySeeEachOthersEntries(): void
    {
        $base = $this->tempDir();
        $one = CachePoolFactory::create([], $base, 'example.com');
        $two = CachePoolFactory::create([], $base, 'example.com');

        $one->save($one->getItem('shared')->set(['n' => 1]));
        $this->assertSame(['n' => 1], $two->getItem('shared')->get());

        $two->deleteItem('shared');
        $this->assertFalse($one->getItem('shared')->isHit());
    }

    public function testAnotherProcessSeesAnEntryThisOneWrote(): void
    {
        $base = $this->tempDir();
        $pool = CachePoolFactory::create([], $base, 'example.com');
        $pool->save($pool->getItem('cross')->set('written here'));

        $script = sprintf(
            'require %s; $p = Idno\Cache\CachePoolFactory::create([], %s, "example.com"); $i = $p->getItem("cross"); echo $i->isHit() ? $i->get() : "miss"; $p->save($p->getItem("back")->set("written there"));',
            json_encode(dirname(__DIR__, 3) . '/vendor/autoload.php', JSON_UNESCAPED_SLASHES),
            json_encode($base, JSON_UNESCAPED_SLASHES)
        );
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=22527 -r ' . escapeshellarg($script) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertSame('written here', implode("\n", $output));
        $this->assertSame('written there', $pool->getItem('back')->get());
    }
}
