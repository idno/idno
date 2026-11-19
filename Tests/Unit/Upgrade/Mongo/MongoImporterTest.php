<?php

declare(strict_types=1);

namespace Tests\Unit\Upgrade\Mongo;

use Doctrine\DBAL\Connection;
use Tests\Import\Contract\MongoImporterContract;
use Tests\Storage\PrivateDatabase;

/**
 * The importer's contract on an SQLite database file in a temporary directory of the test's own,
 * and the file larger than the memory limit (in a subprocess). Needs nothing but PHP.
 */
final class MongoImporterTest extends MongoImporterContract
{
    private string $directory = '';

    private int $databases = 0;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/idno-mongo-import-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::remove($this->directory);
    }

    protected function target(): Connection
    {
        return PrivateDatabase::sqliteFile($this->directory . '/target-' . ++$this->databases . '.sqlite', migrate: false);
    }

    protected function dropTarget(Connection $connection): void
    {
        $connection->close();
    }

    protected function equatedUuids(): array
    {
        // NOCASE: ASCII case only.
        return [['http://example.com/view/Abc', 'http://example.com/view/abc']];
    }

    protected function tooDeep(): int
    {
        // SQLite's JSON functions accept 1000 levels, beyond what json_encode() writes (512): such a
        // document has no JSON form at all.
        return 600;
    }

    public function testAFileLargerThanTheMemoryLimitIsImported(): void
    {
        $megabytes = 64;
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=32M', '-d', 'error_reporting=22527', '-d', 'display_errors=stderr', __DIR__ . '/import_large_file.php', $this->directory, (string) $megabytes],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $stdout . $stderr);

        $result = json_decode($stdout, true);
        $this->assertIsArray($result, $stdout . $stderr);
        $size = $megabytes * 1024 * 1024;
        $this->assertSame(['source' => 1, 'copied' => 1, 'skipped' => 0, 'bytes' => $size], $result['files']);
        $this->assertSame($size, filesize($result['path']));
        $this->assertSame($result['md5'], md5_file($result['path']));
        $this->assertLessThan(32 * 1024 * 1024, $result['peak']);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
