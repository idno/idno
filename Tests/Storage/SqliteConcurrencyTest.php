<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Connection;
use Idno\Storage\Criteria;
use Idno\Storage\DbalRecordStore;
use Idno\Storage\FixedRecordDefaults;
use Idno\Storage\Platform\SqliteDialect;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Storage\SqliteTestDatabase;

/**
 * Two connections to one SQLite database file (in a temporary directory): with write-ahead
 * logging a reader is not blocked by a writer, and a writer waits up to the busy timeout for
 * another's write lock instead of failing at once. Needs no server, but a database file and up to
 * five seconds, so it is not in the unit tier.
 */
final class SqliteConcurrencyTest extends TestCase
{
    private string $dir = '';

    private string $file = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/idno-sqlite-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->file = $this->dir . '/site.sqlite';
        SqliteTestDatabase::withSchema($this->file)->close();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private static function store(Connection $connection): DbalRecordStore
    {
        return new DbalRecordStore($connection, new SqliteDialect(), new FixedRecordDefaults(), static fn (string $s): string => $s, true, false);
    }

    public function testAReaderSeesTheLastCommitWhileAWriteIsInProgress(): void
    {
        $writer = SqliteTestDatabase::connection($this->file);
        $reader = SqliteTestDatabase::connection($this->file);
        $this->assertSame('wal', (string) $writer->fetchOne('pragma journal_mode'));
        $id = self::store($writer)->save('entities', ['_id' => 'a', 'title' => 'first']);

        $writer->beginTransaction();
        $writer->executeStatement("update entities set contents = '{\"_id\":\"a\",\"title\":\"second\"}' where _id = 'a'");

        $started = microtime(true);
        $this->assertSame('first', self::store($reader)->findById('entities', 'a')['title'] ?? null);
        $this->assertLessThan(1.0, microtime(true) - $started, 'the reader did not wait for the writer');

        $writer->commit();
        $this->assertSame('second', self::store($reader)->findById('entities', 'a')['title'] ?? null);
        $this->assertSame('a', $id);
    }

    public function testAWriteWaitsForAnotherConnectionsWriteLock(): void
    {
        // Another process holds the write lock for a second, then commits.
        $script = $this->dir . '/hold.php';
        file_put_contents($script, '<?php $p = new PDO("sqlite:" . $argv[1]); $p->exec("PRAGMA busy_timeout = 5000"); $p->exec("BEGIN IMMEDIATE"); echo "locked\n"; usleep(1000000); $p->exec("COMMIT");');
        $process = proc_open([PHP_BINARY, $script, $this->file], [1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $this->assertSame("locked\n", fgets($pipes[1]));

        $started = microtime(true);
        $id = self::store(SqliteTestDatabase::connection($this->file))->save('entities', ['_id' => 'b', 'title' => 'waited']);
        $waited = microtime(true) - $started;

        fclose($pipes[1]);
        proc_close($process);
        $this->assertSame('b', $id, 'the save waited for the lock and succeeded');
        $this->assertGreaterThan(0.5, $waited);
        $this->assertLessThan(SqliteDialect::BUSY_TIMEOUT_MILLISECONDS / 1000, $waited);
    }

    public function testAWriteThatCannotGetTheLockWithinTheBusyTimeoutIsNotStored(): void
    {
        $holder = SqliteTestDatabase::connection($this->file);
        $holder->executeStatement('begin immediate');
        $store = self::store(SqliteTestDatabase::connection($this->file));

        $started = microtime(true);
        $result = $store->save('entities', ['_id' => 'c', 'title' => 'locked out']);
        $waited = microtime(true) - $started;
        $holder->executeStatement('rollback');

        // "database is locked" is a refusal, as a lock wait timeout is on MySQL: false, nothing stored.
        $this->assertFalse($result);
        $this->assertGreaterThanOrEqual(SqliteDialect::BUSY_TIMEOUT_MILLISECONDS / 1000 - 0.2, $waited);
        $this->assertNull($store->findById('entities', 'c'));
        $this->assertSame(0, $store->count('entities', Criteria::all()));
    }
}
