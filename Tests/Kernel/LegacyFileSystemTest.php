<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Idno\Core\Idno;
use Idno\Legacy\FlysystemFile;
use Idno\Legacy\FlysystemFileSystem;
use Idno\Pages\File\View;
use Idno\Storage\Files\FilesystemFactory;
use Idno\Storage\Files\FileStore;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToWriteFile;
use Psr\Log\AbstractLogger;
use Tests\IdnoTestCase;

/**
 * The legacy filesystem service on an SQL site, Idno\Legacy\FlysystemFileSystem: the legacy
 * Idno\Files\FileSystem API served by Idno\Storage\Files\FileStore. It runs on an upload directory of
 * the test's own, never the rig's.
 */
final class LegacyFileSystemTest extends IdnoTestCase
{
    private string $uploads = '';

    private ?string $originalUploadPath = null;

    /** @var list<array{level: mixed, message: string}> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = sys_get_temp_dir() . '/idno-legacy-filesystem-' . bin2hex(random_bytes(6));
        mkdir($this->uploads, 0777, true);
        $this->originalUploadPath = Idno::site()->config()->uploadpath;
        $this->logged             = [];
    }

    protected function tearDown(): void
    {
        Idno::site()->config()->uploadpath = $this->originalUploadPath;
        $this->removeTree($this->uploads);
        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }

    /**
     * A FlysystemFileSystem on a FileStore over the test's upload directory, in the site's base directory
     * (as the container's service has) unless one is given.
     */
    private function filesystem(?\Closure $directory = null): FlysystemFileSystem
    {
        $directory ??= static fn (): string => Idno::site()->config()->getFileBaseDirName();
        $logger     = new class ($this->logged) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string}> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logged[] = ['level' => $level, 'message' => (string) $message];
            }
        };
        $store = new FileStore(FilesystemFactory::fromConfig(['uploadpath' => $this->uploads . '/']), $directory);

        return new FlysystemFileSystem(static fn (): FileStore => $store, static fn () => $logger, $this->uploads . '/');
    }

    // ------------------------------------------------ the service

    public function testTheSiteFilesystemIsServedByTheFileStore(): void
    {
        $this->assertInstanceOf(FlysystemFileSystem::class, Idno::site()->filesystem());
        $this->assertInstanceOf(FileStore::class, Idno::container()->get(FileStore::class));
        $this->assertInstanceOf(FilesystemOperator::class, Idno::container()->get(FilesystemOperator::class));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string, 2: bool}> the `filesystem` setting, the upload path, whether there is a filesystem
     */
    public static function filesystemSettings(): iterable
    {
        yield 'local with an upload path' => ['local', '/uploads/', true];
        yield 'no setting with an upload path' => [null, '/uploads/', true];
        yield 'a class that does not exist' => ['NoSuchFileSystem', '/uploads/', true];
        yield 's3 with no setting' => [null, 's3://bucket/up/', true];
        yield 'local with no upload path' => ['local', '', false];
        yield 'no setting and no upload path' => [null, '', false];
    }

    /**
     * @dataProvider filesystemSettings
     */
    public function testTheFilesystemServiceFollowsTheSettings(?string $setting, string $uploadPath, bool $expected): void
    {
        $config   = Idno::site()->config();
        $original = [$config->filesystem, $config->uploadpath];
        try {
            $config->filesystem = $setting;
            $config->uploadpath = $uploadPath;
            $factory            = new \Idno\Legacy\LegacyServiceFactory(
                null,
                null,
                null,
                static fn () => new \Psr\Log\NullLogger(),
                static fn () => throw new \LogicException('the store is built at first use')
            );

            $filesystem = $factory->filesystem();
        } finally {
            [$config->filesystem, $config->uploadpath] = $original;
        }

        if ($expected) {
            $this->assertInstanceOf(FlysystemFileSystem::class, $filesystem);
        } else {
            $this->assertNull($filesystem);
        }
    }

    // ------------------------------------------------ the layout of every site's uploads

    public function testAFileStoredByAOneXSiteIsFound(): void
    {
        $id        = '0123456789abcdef0123456789abcdef';
        $directory = $this->uploads . '/' . Idno::site()->config()->getFileBaseDirName() . '/0/1/2/3';
        mkdir($directory, 0777, true);
        file_put_contents($directory . '/' . $id . '.file', 'stored by a 1.x site');
        $metadata = ['filename' => 'one-x.txt', 'mime_type' => 'text/plain'];
        file_put_contents($directory . '/' . $id . '.data', json_encode($metadata));

        $found = $this->filesystem()->findOne($id);

        $this->assertInstanceOf(FlysystemFile::class, $found);
        $this->assertSame('stored by a 1.x site', $found->getBytes());
        $this->assertSame($metadata + ['_id' => $id, 'length' => 20], $found->file);
    }

    public function testAFileIsStoredWhereAOneXSiteWouldLookForIt(): void
    {
        $metadata = ['filename' => 'new.txt', 'mime_type' => 'text/plain'];
        $id       = $this->filesystem()->storeContent('stored by this site', $metadata);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $id);
        $directory = $this->uploads . '/' . Idno::site()->config()->getFileBaseDirName() . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3];
        $this->assertSame('stored by this site', file_get_contents($directory . '/' . $id . '.file'));
        $this->assertSame(json_encode($metadata), file_get_contents($directory . '/' . $id . '.data'));
    }

    // ------------------------------------------------ finding

    public function testFindOneAcceptsAnIdOrAnIdArray(): void
    {
        $filesystem = $this->filesystem();
        $id         = $filesystem->storeContent('either way', ['filename' => 'a.txt']);

        $byId    = $filesystem->findOne($id);
        $byArray = $filesystem->findOne(['_id' => $id]);

        $this->assertInstanceOf(FlysystemFile::class, $byId);
        $this->assertInstanceOf(FlysystemFile::class, $byArray);
        $this->assertSame($id, $byId->_id);
        $this->assertSame($id, $byArray->_id);
    }

    public function testFindOneOfAnUnknownIdIsFalse(): void
    {
        $filesystem = $this->filesystem();

        $this->assertFalse($filesystem->findOne(str_repeat('a', 32)));
        $this->assertFalse($filesystem->findOne(['_id' => str_repeat('a', 32)]));
        $this->assertFalse($filesystem->findOne('not an id'));
        $this->assertFalse($filesystem->findOne([]));
        $this->assertFalse($filesystem->findOne(''));
    }

    public function testTheFileArrayIsTodays(): void
    {
        $filesystem = $this->filesystem();
        $metadata   = ['filename' => 'today.txt', 'mime_type' => 'text/plain', 'attached_entity' => 'https://example.com/x'];
        $id         = $filesystem->storeContent('twelve bytes', $metadata);

        $file = $filesystem->findOne($id);

        $this->assertSame($metadata + ['_id' => $id, 'length' => 12], $file->file);
        $this->assertSame($metadata, $file->metadata);
        $this->assertSame($id, $file->_id);
        $this->assertSame(12, $file->getSize());
        $this->assertSame('today.txt', $file->getFilename());
    }

    public function testTheInternalFilenameIsTheAbsolutePathOnALocalUploadDirectory(): void
    {
        $filesystem = $this->filesystem();
        $id         = $filesystem->storeContent('where am I', ['filename' => 'where.txt']);

        $file = $filesystem->findOne($id);

        $expected = $this->uploads . '/' . Idno::site()->config()->getFileBaseDirName() . '/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id . '.file';
        $this->assertSame($expected, $file->internal_filename);
        $this->assertFileExists($file->internal_filename);
    }

    public function testTheInternalFilenameIsEmptyOffTheLocalAdapter(): void
    {
        $store = new FileStore(FilesystemFactory::fromConfig(['uploadpath' => $this->uploads . '/']), 'example.test');
        $fs    = new FlysystemFileSystem(static fn (): FileStore => $store, static fn () => new \Psr\Log\NullLogger(), '');
        $id    = $fs->storeContent('no local path', ['filename' => 'x.txt']);

        $this->assertSame('', $fs->findOne($id)->internal_filename);
    }

    // ------------------------------------------------ the file's methods

    public function testAFilesStreamBytesAndCopy(): void
    {
        $filesystem = $this->filesystem();
        $source     = $this->uploads . '/source.bin';
        file_put_contents($source, 'from a path');
        $id   = $filesystem->storeFile($source, ['filename' => 'source.bin']);
        $file = $filesystem->findOne($id);

        $stream = $file->getResource();
        $this->assertIsResource($stream);
        $this->assertSame('from a path', stream_get_contents($stream));
        fclose($stream);

        $copy = $this->uploads . '/copy.bin';
        $this->assertTrue($file->write($copy));
        $this->assertSame('from a path', file_get_contents($copy));
        $this->assertFalse($file->write($this->uploads . '/missing-directory/copy.bin'));
    }

    public function testPassThroughBytesWritesTheFile(): void
    {
        $filesystem = $this->filesystem();
        $id         = $filesystem->storeContent('passed through', ['filename' => 'p.txt']);
        $file       = $filesystem->findOne($id);

        // PHPUnit's own output buffer is level 1 when a test runs; passThroughBytes() ends one level, as
        // it always has, so give it a buffer of its own to end and capture its output in another.
        $level = ob_get_level();
        ob_start();
        ob_start();
        $file->passThroughBytes();
        $this->assertSame($level + 1, ob_get_level());
        $output = ob_get_clean();

        $this->assertSame('passed through', $output);
    }

    public function testDeleteRemovesTheDataFileToo(): void
    {
        $filesystem = $this->filesystem();
        $id         = $filesystem->storeContent('to be deleted', ['filename' => 'd.txt']);
        $file       = $filesystem->findOne($id);
        $data       = substr($file->internal_filename, 0, -strlen('.file')) . '.data';
        $this->assertFileExists($file->internal_filename);
        $this->assertFileExists($data);

        $file->delete();

        $this->assertFileDoesNotExist($file->internal_filename);
        $this->assertFileDoesNotExist($data);
        $this->assertFalse($filesystem->findOne($id));
    }

    public function testUpdateMetadataMergesAndReportsMissingFiles(): void
    {
        $filesystem = $this->filesystem();
        $id         = $filesystem->storeContent('meta', ['filename' => 'm.txt', 'mime_type' => 'text/plain']);

        $this->assertTrue($filesystem->updateMetadata($id, ['attached_entity' => 'https://example.com/y']));

        $this->assertSame(
            ['filename' => 'm.txt', 'mime_type' => 'text/plain', 'attached_entity' => 'https://example.com/y'],
            $filesystem->findOne($id)->metadata
        );
        $this->assertFalse($filesystem->updateMetadata(str_repeat('b', 32), ['a' => 'b']));
        $this->assertFalse($filesystem->updateMetadata('not an id', ['a' => 'b']));
    }

    // ------------------------------------------------ storing

    public function testNewDirectoriesAndFilesKeepTodaysPermissions(): void
    {
        $previous = umask(022);
        try {
            $filesystem = $this->filesystem();
            $id         = $filesystem->storeContent('permissions', ['filename' => 'perm.txt']);
        } finally {
            umask($previous);
        }
        $file = $filesystem->findOne($id);
        $base = $this->uploads . '/' . Idno::site()->config()->getFileBaseDirName();

        $directory = dirname($file->internal_filename);
        while (strlen($directory) > strlen($this->uploads)) {
            $this->assertSame('0755', substr(sprintf('%o', fileperms($directory)), -4), $directory);
            $directory = dirname($directory);
        }
        $this->assertSame('0644', substr(sprintf('%o', fileperms($file->internal_filename)), -4));
        $this->assertSame('0644', substr(sprintf('%o', fileperms(substr($file->internal_filename, 0, -5) . '.data')), -4));
        $this->assertDirectoryExists($base);
    }

    public function testAFilePathHostThatDiffersFromTheHostStores(): void
    {
        $filesystem = $this->filesystem(static fn (): string => 'files.example');
        $id         = $filesystem->storeContent('under another name', ['filename' => 'h.txt']);

        $this->assertNotFalse($id);
        $this->assertFileExists($this->uploads . '/files.example/' . $id[0] . '/' . $id[1] . '/' . $id[2] . '/' . $id[3] . '/' . $id . '.file');
        $this->assertSame('under another name', $filesystem->findOne($id)->getBytes());
    }

    public function testStoringNothingIsFalseWithoutMessages(): void
    {
        $filesystem = $this->filesystem();
        $_SESSION['messages'] = [];

        $this->assertFalse($filesystem->storeContent('', ['filename' => 'empty.txt']));
        $this->assertFalse($filesystem->storeFile($this->uploads . '/does-not-exist', ['filename' => 'x']));
        $this->assertSame([], $_SESSION['messages']);
    }

    public function testAFailedStoreIsLoggedAndMessagedAndFalse(): void
    {
        $operator = $this->createMock(FilesystemOperator::class);
        $operator->method('writeStream')->willThrowException(UnableToWriteFile::atLocation('x'));
        $store      = new FileStore($operator, 'example.test');
        $logger     = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->errors[] = $level . ': ' . $message;
            }
        };
        $filesystem = new FlysystemFileSystem(static fn (): FileStore => $store, static fn () => $logger, '');
        $_SESSION['messages'] = [];

        $this->assertFalse($filesystem->storeContent('cannot be written', ['filename' => 'f.txt']));

        $this->assertSame(['error: Exception while uploading file'], $logger->errors);
        $messages = array_column($_SESSION['messages'], 'message');
        $this->assertContains(Idno::site()->language()->_('Something went wrong saving your file.'), $messages);
        $_SESSION['messages'] = [];

        $source = $this->uploads . '/source.txt';
        file_put_contents($source, 'content');
        $this->assertFalse($filesystem->storeFile($source, ['filename' => 'f.txt']));
        $this->assertContains(Idno::site()->language()->_('Something went wrong saving your file.'), array_column($_SESSION['messages'], 'message'));
        $_SESSION['messages'] = [];
    }

    /**
     * A filesystem whose store is built when first used, as the container's is, and whose logger records
     * what it is told.
     *
     * @param \Closure(): FileStore $store
     */
    private function filesystemOver(\Closure $store, ?object &$logger = null): FlysystemFileSystem
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->errors[] = $level . ': ' . $message;
            }
        };
        $recorder = $logger;

        return new FlysystemFileSystem($store, static fn () => $recorder, '');
    }

    public function testAnUploadRootThatCannotBeCreatedIsAFailureAndNeverAnException(): void
    {
        // A path under a regular file: the directory cannot exist and cannot be made.
        $file = $this->uploads . '/a-regular-file';
        file_put_contents($file, 'x');
        $root       = $file . '/uploads/';
        $filesystem = $this->filesystemOver(static fn (): FileStore => new FileStore(FilesystemFactory::fromConfig(['uploadpath' => $root]), 'example.test'), $logger);
        $_SESSION['messages'] = [];

        $this->assertFalse($filesystem->findOne(str_repeat('a', 32)));
        $this->assertFalse($filesystem->updateMetadata(str_repeat('a', 32), ['x' => 'y']));
        $this->assertSame([], $_SESSION['messages'], 'looking for a file says nothing to the visitor');

        $this->assertFalse($filesystem->storeContent('some bytes', ['filename' => 'f.txt']));
        $this->assertContains(Idno::site()->language()->_('Something went wrong saving your file.'), array_column($_SESSION['messages'], 'message'));
        $_SESSION['messages'] = [];

        $source = $this->uploads . '/source.txt';
        file_put_contents($source, 'content');
        $this->assertFalse($filesystem->storeFile($source, ['filename' => 'f.txt']));
        $this->assertContains(Idno::site()->language()->_('Something went wrong saving your file.'), array_column($_SESSION['messages'], 'message'));
        $_SESSION['messages'] = [];

        $this->assertNotSame([], $logger->errors);
        foreach ($logger->errors as $line) {
            $this->assertStringNotContainsString($file, $line, 'a server path is not part of the fixed message');
        }
        $this->assertFileDoesNotExist($root);
    }

    public function testAStoreThatCannotBeBuiltIsAFailureAndNeverAnException(): void
    {
        $filesystem = $this->filesystemOver(static function (): FileStore {
            throw new \LogicException('No upload path is configured.');
        }, $logger);
        $_SESSION['messages'] = [];

        $this->assertFalse($filesystem->findOne(str_repeat('b', 32)));
        $this->assertFalse($filesystem->updateMetadata(str_repeat('b', 32), []));
        $this->assertFalse($filesystem->storeContent('some bytes', ['filename' => 'f.txt']));
        $this->assertContains(Idno::site()->language()->_('Something went wrong saving your file.'), array_column($_SESSION['messages'], 'message'));
        $_SESSION['messages'] = [];
        $this->assertNotSame([], $logger->errors);
    }

    // ------------------------------------------------ the file page and the caches

    public function testARangeIsServedFromAStreamThatCannotSeek(): void
    {
        $bytes = '';
        for ($i = 0; $i < 2000; $i++) {
            $bytes .= chr(65 + $i % 26);
        }
        NonSeekableStream::$contents = $bytes;
        stream_wrapper_register('idno-test-noseek', NonSeekableStream::class);
        try {
            $stream = fopen('idno-test-noseek://file', 'r');
            $this->assertIsResource($stream);
            $this->assertSame(-1, @fseek($stream, 100), 'the wrapper really cannot seek');
            fclose($stream);

            $stream = fopen('idno-test-noseek://file', 'r');
            View::seekTo($stream, 100);
            $range = fread($stream, 100);
            fclose($stream);
        } finally {
            stream_wrapper_unregister('idno-test-noseek');
        }

        $this->assertSame(substr($bytes, 100, 100), $range);
    }

    public function testASeekableStreamIsPositionedBySeekTo(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, '0123456789');
        View::seekTo($stream, 4);

        $this->assertSame('456', fread($stream, 3));
        fclose($stream);
    }

    public function testARangeReadHoldsExactlyTheBytesOfTheRange(): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, '0123456789');
        rewind($stream);
        View::seekTo($stream, 2);

        // Both positions of a range are inclusive: bytes 2 to 5 are four bytes, the number the response's
        // Content-Length announces. The file page used to read $end bytes (five here) and rely on the
        // client cutting the body, and announced one byte too few.
        $this->assertSame('2345', $this->streamed($stream, 2, 5));
        fclose($stream);
    }

    /** The bytes View::streamRange() writes for a range, collected. */
    private function streamed($stream, int $start, int $end): string
    {
        $out = '';
        View::streamRange($stream, $start, $end, function (string $chunk) use (&$out): void {
            $out .= $chunk;
        });

        return $out;
    }

    public function testTheWholeFileIsWrittenInPiecesAndStopsOnAnEmptyRead(): void
    {
        $data = str_repeat('abcdefgh', 3000); // more than one 8 KiB piece
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $data);
        rewind($stream);
        $out = '';
        $this->assertSame(strlen($data), View::streamAll($stream, function (string $chunk) use (&$out): void {
            $out .= $chunk;
        }));
        $this->assertSame($data, $out);
        fclose($stream);

        // A stream that never reaches its end but answers every read with nothing (a failed read) must not spin.
        stream_wrapper_register('idnostall', StalledStream::class);
        try {
            $stalled = fopen('idnostall://x', 'rb');
            $started = microtime(true);
            $this->assertSame(0, View::streamAll($stalled, function (string $chunk): void {
                $this->fail('nothing was read');
            }));
            $this->assertLessThan(5.0, microtime(true) - $started);
            fclose($stalled);
        } finally {
            stream_wrapper_unregister('idnostall');
        }
    }

    public function testARangeReadStopsWhenTheStreamEndsEarly(): void
    {
        // A stream shorter than the stored size says: the read must end, not wait for bytes that never come.
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, '0123456789');
        rewind($stream);
        View::seekTo($stream, 4);

        $started = microtime(true);
        $this->assertSame('456789', $this->streamed($stream, 4, 2000));
        $this->assertLessThan(5.0, microtime(true) - $started);
        fclose($stream);
    }

    public function testAnS3UploadPathNeverBecomesALocalDirectory(): void
    {
        $this->assertNotContains('s3', stream_get_wrappers(), 'no stream wrapper for s3:// is registered');
        $config = Idno::site()->config();
        $config->uploadpath = 's3://b/up/';
        $tmp                = rtrim($config->getTempDir(), '/\\');

        // The cache pool: its directory is never the s3:// path, and none called "s3:" is made.
        $cwd = getcwd();
        $cache = Idno::site()->cache();
        $cache->store('idno-legacy-filesystem-test', 'value');
        $this->assertSame('value', $cache->load('idno-legacy-filesystem-test'));
        $cache->delete('idno-legacy-filesystem-test');
        $this->assertDirectoryDoesNotExist($cwd . '/s3:');
        $this->assertDirectoryDoesNotExist($cwd . '/s3:/b/up');
        $this->assertStringNotContainsString('s3:', \Idno\Cache\CachePoolFactory::directory(['uploadpath' => 's3://b/up/'], $tmp . '/pool-base'));

        // The HTMLPurifier cache directory.
        $purifier = new \Idno\Core\Purifier();
        $this->assertStringStartsWith($tmp, rtrim($this->purifierSerializerPath($purifier), '/\\'));

        // The Twig cache.
        $template = new \Idno\Core\HybridTwigTemplate();
        $twig     = (new \ReflectionProperty(\Idno\Core\HybridTwigTemplate::class, 'twig'))->getValue($template);
        $this->assertStringStartsWith($tmp, $twig->getCache());
        $this->assertStringNotContainsString('s3:', $twig->getCache());
    }

    private function purifierSerializerPath(\Idno\Core\Purifier $purifier): string
    {
        $purifier->init();
        $config = (new \ReflectionProperty(\Idno\Core\Purifier::class, 'purifier'))->getValue($purifier)->config;

        return (string) $config->get('Cache.SerializerPath');
    }
}

/**
 * A stream wrapper over a string whose streams cannot seek, as a stream read over HTTP cannot.
 */
final class NonSeekableStream
{
    public static string $contents = '';

    /** @var resource|null */
    public $context;

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $chunk = substr(self::$contents, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$contents);
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        return false;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}

/** A stream whose end is never reached and whose reads return nothing. */
final class StalledStream
{
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array|false
    {
        return [];
    }
}
