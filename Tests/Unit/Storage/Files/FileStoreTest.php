<?php

declare(strict_types=1);

namespace Tests\Unit\Storage\Files;

use Idno\Storage\Files\FileStore;
use Idno\Storage\Files\FileStoreException;
use Idno\Storage\Files\StoredFile;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\TestCase;

final class FileStoreTest extends TestCase
{
    private const LEGACY_ID = '0123456789abcdef0123456789abcdef';

    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if ($this->tmp !== null && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->tmp);
        }
    }

    private function memory(): Filesystem
    {
        return new Filesystem(new InMemoryFilesystemAdapter());
    }

    /** @return resource */
    private function stream(string $bytes)
    {
        $s = fopen('php://memory', 'w+');
        fwrite($s, $bytes);
        rewind($s);

        return $s;
    }

    public function testAStoredFileIsFoundWithItsBytesMetadataAndSize(): void
    {
        $store = new FileStore($this->memory(), 'example.com');
        $meta  = ['filename' => 'a.txt', 'mime_type' => 'text/plain'];
        $id    = $store->store($this->stream('hello'), $meta);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
        $file = $store->find($id);
        $this->assertInstanceOf(StoredFile::class, $file);
        $this->assertSame($id, $file->id);
        $this->assertSame($meta, $file->metadata);
        $this->assertSame(5, $file->size);
        $this->assertSame('hello', stream_get_contents($store->readStream($id)));
        $this->assertTrue($store->has($id));
    }

    public function testTheLayoutIsTodays(): void
    {
        $fs    = $this->memory();
        $store = new FileStore($fs, 'example.com');
        $meta  = ['filename' => 'a.txt'];
        $id    = 'abcd' . str_repeat('e', 28);
        $store->storeAs($id, $this->stream('x'), $meta);

        $base = 'example.com/a/b/c/d/' . $id;
        $this->assertTrue($fs->fileExists($base . '.file'));
        $this->assertSame('x', $fs->read($base . '.file'));
        $this->assertSame(json_encode($meta), $fs->read($base . '.data'));
        $this->assertSame($base . '.file', $store->path($id));
    }

    public function testPathRejectsAnInvalidId(): void
    {
        foreach (['../x', self::LEGACY_ID . "\n"] as $bad) {
            try {
                (new FileStore($this->memory(), 'd'))->path($bad);
                $this->fail('path accepted ' . $bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAFailedDataReadIsNotEmptyMetadata(): void
    {
        $inner = $this->memory();
        $store = new FileStore($inner, 'd');
        $store->storeAs(self::LEGACY_ID, $this->stream('bytes'), ['filename' => 'a.txt']);

        $failing = $this->createMock(FilesystemOperator::class);
        $failing->method('fileExists')->willReturnCallback(fn (string $p) => $inner->fileExists($p));
        $failing->method('fileSize')->willReturnCallback(fn (string $p) => $inner->fileSize($p));
        $failing->method('read')->willThrowException(UnableToReadFile::fromLocation('x'));
        $failing->expects($this->never())->method('write');
        $failing->expects($this->never())->method('writeStream');
        $flaky = new FileStore($failing, 'd');

        try {
            $flaky->find(self::LEGACY_ID);
            $this->fail('find swallowed the failure');
        } catch (FileStoreException $e) {
            $this->assertInstanceOf(UnableToReadFile::class, $e->getPrevious());
        }
        try {
            $flaky->updateMetadata(self::LEGACY_ID, ['b' => 1]);
            $this->fail('updateMetadata swallowed the failure');
        } catch (FileStoreException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(['filename' => 'a.txt'], $store->find(self::LEGACY_ID)->metadata);
    }

    public function testAFileWrittenByOneXIsFound(): void
    {
        $fs   = $this->memory();
        $base = 'example.com/0/1/2/3/' . self::LEGACY_ID;
        $fs->write($base . '.file', 'legacy bytes');
        $fs->write($base . '.data', json_encode(['filename' => 'a.txt', 'mime_type' => 'text/plain']));

        $file = (new FileStore($fs, 'example.com'))->find(self::LEGACY_ID);
        $this->assertNotNull($file);
        $this->assertSame(['filename' => 'a.txt', 'mime_type' => 'text/plain'], $file->metadata);
        $this->assertSame(12, $file->size);
    }

    public function testAMissingOrBrokenDataFileIsEmptyMetadata(): void
    {
        $fs   = $this->memory();
        $base = 'd/0/1/2/3/' . self::LEGACY_ID;
        $fs->write($base . '.file', 'abc');
        $store = new FileStore($fs, 'd');
        $this->assertSame([], $store->find(self::LEGACY_ID)->metadata);

        $fs->write($base . '.data', '{not json');
        $this->assertSame([], $store->find(self::LEGACY_ID)->metadata);
        $this->assertSame(3, $store->find(self::LEGACY_ID)->size);
    }

    public function testA24DigitIdIsStoredAndFound(): void
    {
        $store = new FileStore($this->memory(), 'd');
        $id    = '5f1e2d3c4b5a69788796a5b4';
        $store->storeAs($id, $this->stream('grid'), ['a' => 1]);
        $this->assertSame(4, $store->find($id)->size);
        $this->assertSame('d/5/f/1/e/' . $id . '.file', $store->path($id));
    }

    public function testAnIdThatIsNotHexIsNeverAPath(): void
    {
        $fs = $this->createMock(FilesystemOperator::class);
        foreach (['fileExists', 'read', 'readStream', 'fileSize', 'write', 'writeStream', 'delete'] as $m) {
            $fs->expects($this->never())->method($m);
        }
        $store = new FileStore($fs, 'd');
        foreach (['../../etc/passwd', 'abc', str_repeat('g', 32), '', self::LEGACY_ID . '0', self::LEGACY_ID . "\n", '5f1e2d3c4b5a69788796a5b4' . "\n"] as $bad) {
            $this->assertNull($store->find($bad));
            $this->assertFalse($store->has($bad));
            $this->assertFalse($store->updateMetadata($bad, ['a' => 1]));
            $store->delete($bad);
            try {
                $store->storeAs($bad, $this->stream('x'), []);
                $this->fail('storeAs accepted ' . $bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
            try {
                $store->readStream($bad);
                $this->fail('readStream accepted ' . $bad);
            } catch (FileStoreException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testStoreAsOverwrites(): void
    {
        $store = new FileStore($this->memory(), 'd');
        $store->storeAs(self::LEGACY_ID, $this->stream('one'), ['v' => 1]);
        $store->storeAs(self::LEGACY_ID, $this->stream('two!'), ['v' => 2]);
        $file = $store->find(self::LEGACY_ID);
        $this->assertSame(4, $file->size);
        $this->assertSame(['v' => 2], $file->metadata);
    }

    public function testUpdateMetadataMergesAndKeepsTheBytes(): void
    {
        $store = new FileStore($this->memory(), 'd');
        $store->storeAs(self::LEGACY_ID, $this->stream('bytes'), ['a' => 1, 'b' => 2]);
        $this->assertTrue($store->updateMetadata(self::LEGACY_ID, ['b' => 3, 'c' => 4]));
        $file = $store->find(self::LEGACY_ID);
        $this->assertSame(['a' => 1, 'b' => 3, 'c' => 4], $file->metadata);
        $this->assertSame('bytes', stream_get_contents($store->readStream(self::LEGACY_ID)));
    }

    public function testUpdateMetadataOfAMissingFileIsFalse(): void
    {
        $this->assertFalse((new FileStore($this->memory(), 'd'))->updateMetadata(self::LEGACY_ID, ['a' => 1]));
    }

    public function testAnUnchangedMergeWritesNothing(): void
    {
        $inner = $this->memory();
        $store = new FileStore($inner, 'd');
        $store->storeAs(self::LEGACY_ID, $this->stream('b'), ['a' => 1]);

        $spy = $this->createMock(FilesystemOperator::class);
        $spy->method('fileExists')->willReturnCallback(fn (string $p) => $inner->fileExists($p));
        $spy->method('read')->willReturnCallback(fn (string $p) => $inner->read($p));
        $spy->expects($this->never())->method('write');
        $spy->expects($this->never())->method('writeStream');

        $this->assertTrue((new FileStore($spy, 'd'))->updateMetadata(self::LEGACY_ID, ['a' => 1]));
    }

    public function testDeleteRemovesBothFiles(): void
    {
        $fs    = $this->memory();
        $store = new FileStore($fs, 'd');
        $store->storeAs(self::LEGACY_ID, $this->stream('b'), ['a' => 1]);
        $store->delete(self::LEGACY_ID);

        $this->assertFalse($store->has(self::LEGACY_ID));
        $this->assertNull($store->find(self::LEGACY_ID));
        $this->assertFalse($fs->fileExists('d/0/1/2/3/' . self::LEGACY_ID . '.data'));
        $store->delete(self::LEGACY_ID); // no file: no error
    }

    public function testReadStreamOfAMissingFileThrows(): void
    {
        $this->expectException(FileStoreException::class);
        (new FileStore($this->memory(), 'd'))->readStream(self::LEGACY_ID);
    }

    public function testTheDirectoryClosureIsAskedAtEachCall(): void
    {
        $fs   = $this->memory();
        $dir  = 'one';
        $asks = 0;
        $store = new FileStore($fs, function () use (&$dir, &$asks): string {
            $asks++;

            return $dir;
        });
        $store->storeAs(self::LEGACY_ID, $this->stream('b'), []);
        $this->assertTrue($fs->fileExists('one/0/1/2/3/' . self::LEGACY_ID . '.file'));
        $dir = 'two';
        $this->assertFalse($store->has(self::LEGACY_ID));
        $this->assertGreaterThanOrEqual(2, $asks);
    }

    public function testStoreStreamsWithoutBufferingTheFile(): void
    {
        $this->tmp = sys_get_temp_dir() . '/idno-filestore-' . bin2hex(random_bytes(6));
        mkdir($this->tmp);
        $source = $this->tmp . '/source.bin';
        $out    = fopen($source, 'w');
        $chunk  = str_repeat('x', 1024 * 1024);
        for ($i = 0; $i < 16; $i++) {
            fwrite($out, $chunk);
        }
        fclose($out);
        unset($chunk);

        $store  = new FileStore(new Filesystem(new LocalFilesystemAdapter($this->tmp . '/uploads')), 'example.com');
        $in     = fopen($source, 'r');
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $id     = $store->store($in, ['filename' => 'big']);
        $after  = memory_get_peak_usage();
        fclose($in);

        $this->assertLessThan(4 * 1024 * 1024, $after - $before);
        $this->assertSame(16 * 1024 * 1024, $store->find($id)->size);
    }
}
