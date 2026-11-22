<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use Idno\Cache\CachePoolFactory;
use Idno\Kernel\CacheDirResolver;
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

        $tmp = $this->tempDir();
        $private = CachePoolFactory::temporaryDirectory($locked . '/base', $tmp);
        $this->assertSame($private, CachePoolFactory::directory(['cachepath' => $locked . '/sub'], $locked . '/base', $tmp));
        $this->assertSame($private, CachePoolFactory::directory([], $locked . '/base', $tmp));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($private)), -4), 'created private');
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
        $tmp = $this->tempDir();
        $this->assertSame(CachePoolFactory::temporaryDirectory($uploads, $tmp), CachePoolFactory::directory(['uploadpath' => $uploads . '/'], $uploads, $tmp));

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

    public function testAWorldOrGroupWritableTemporaryDirectoryIsRefused(): void
    {
        $tmp = $this->tempDir();
        $unusableBase = $tmp . '/no/such/base/' . str_repeat('x', 300);
        $candidate = CachePoolFactory::temporaryDirectory($unusableBase, $tmp);

        foreach ([0777, 0770, 0757] as $mode) {
            mkdir($candidate, 0700);
            chmod($candidate, $mode);
            $this->assertNull(CachePoolFactory::directory([], $unusableBase, $tmp), sprintf('mode %o is refused', $mode));
            rmdir($candidate);
        }
    }

    public function testAPrivateTemporaryDirectoryOwnedByTheUserIsUsed(): void
    {
        $tmp = $this->tempDir();
        $unusableBase = $tmp . '/no/such/base/' . str_repeat('x', 300);
        $candidate = CachePoolFactory::temporaryDirectory($unusableBase, $tmp);
        mkdir($candidate, 0700);

        $this->assertSame($candidate, CachePoolFactory::directory([], $unusableBase, $tmp));
    }

    public function testADirectoryOwnedByAnotherUserIsRefused(): void
    {
        $dir = $this->tempDir() . '/mine';
        mkdir($dir, 0700);
        $owner = (int) fileowner($dir);

        $this->assertTrue(CacheDirResolver::usableAndPrivate($dir, static fn (): ?int => $owner), 'the owner may use it');
        $this->assertFalse(CacheDirResolver::usableAndPrivate($dir, static fn (): ?int => $owner + 1), 'someone else may not');

        $tmp = $this->tempDir();
        $base = $tmp . '/no/such/base/' . str_repeat('x', 300);
        $candidate = CachePoolFactory::temporaryDirectory($base, $tmp);
        mkdir($candidate, 0700);
        $this->assertSame($candidate, CachePoolFactory::directory([], $base, $tmp, static fn (): ?int => (int) fileowner($candidate)));
        $this->assertNull(CachePoolFactory::directory([], $base, $tmp, static fn (): ?int => (int) fileowner($candidate) + 1));
    }

    /**
     * Without a way to learn the current user (no posix extension) the pool's temporary directory is refused:
     * mode alone proves nothing about who owns it. The kernel's own cache fallback stays lenient, so a site
     * without ext-posix still boots.
     */
    public function testWithoutAWayToLearnTheOwnerTheTemporaryDirectoryIsRefusedButTheKernelStaysLenient(): void
    {
        $tmp = $this->tempDir();
        $base = $tmp . '/no/such/base/' . str_repeat('x', 300);
        $unknown = static fn (): ?int => null;

        $this->assertNull(CachePoolFactory::directory([], $base, $tmp, $unknown));
        $this->assertInstanceOf(\Symfony\Component\Cache\Adapter\NullAdapter::class, CachePoolFactory::create([], $base, 'example.com', $tmp, $unknown));

        $dir = $tmp . '/lenient';
        mkdir($dir, 0700);
        $this->assertFalse(CacheDirResolver::usableAndPrivate($dir, $unknown, true));
        $this->assertTrue(CacheDirResolver::usableAndPrivate($dir, $unknown), 'the kernel does not require an owner it cannot learn');
    }

    public function testWithNothingUsableThePoolIsANullPool(): void
    {
        $pool = CachePoolFactory::create(['cachepath' => '/proc/nonexistent/x'], '/proc/nonexistent/y', 'example.com', '/proc/nonexistent/z');

        $this->assertInstanceOf(\Symfony\Component\Cache\Adapter\NullAdapter::class, $pool);
    }
}
