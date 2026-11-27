<?php

declare(strict_types=1);

namespace Tests\Storage;

use Doctrine\DBAL\Connection;
use Idno\Storage\Criteria;
use Idno\Storage\Platform\SqliteDialect;
use Idno\Storage\RecordStore;
use PHPUnit\Framework\TestCase;

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
        PrivateDatabase::sqliteFile($this->file)->close();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private static function store(Connection $connection): RecordStore
    {
        return PrivateDatabase::recordStore($connection);
    }

    private function connect(): Connection
    {
        return PrivateDatabase::sqliteFile($this->file, migrate: false);
    }

    public function testAReaderSeesTheLastCommitWhileAWriteIsInProgress(): void
    {
        $writer = $this->connect();
        $reader = $this->connect();
        $this->assertSame('wal', (string) $writer->fetchOne('pragma journal_mode'));
        $id = self::store($writer)->save('entities', ['_id' => 'a', 'title' => 'first']);

        $writer->beginTransaction();
        $writer->executeStatement("update idno_entities set data = '{\"_id\":\"a\",\"title\":\"second\"}' where id = 'a'");

        $started = microtime(true);
        $this->assertSame('first', self::store($reader)->findById('entities', 'a')['title'] ?? null);
        $this->assertLessThan(1.0, microtime(true) - $started, 'the reader did not wait for the writer');

        $writer->commit();
        $this->assertSame('second', self::store($reader)->findById('entities', 'a')['title'] ?? null);
        $this->assertSame('a', $id);
    }

    /**
     * Catches an invitation reported as used because the database was busy: a claim that cannot get the write lock in time is thrown, not answered
     * false (which means somebody else has the record).
     */
    public function testAClaimThatCannotGetTheWriteLockIsThrownAndNotTakenForALostClaim(): void
    {
        $store = self::store($this->connect());
        $store->save('entities', ['_id' => 'c', 'title' => 'claimed']);
        $claimer = $this->connect();
        $claimer->executeStatement('PRAGMA busy_timeout = 100');
        $holder = $this->connect();
        $holder->executeStatement('BEGIN IMMEDIATE');
        try {
            self::store($claimer)->claim('entities', 'c');
            $this->fail('a busy database is thrown');
        } catch (\Doctrine\DBAL\Exception\RetryableException) {
            $this->addToAssertionCount(1);
        } finally {
            $holder->executeStatement('COMMIT');
        }
        $this->assertTrue(self::store($claimer)->claim('entities', 'c'), 'free again: the claim is the first, and wins');
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
        $id = self::store($this->connect())->save('entities', ['_id' => 'b', 'title' => 'waited']);
        $waited = microtime(true) - $started;

        fclose($pipes[1]);
        proc_close($process);
        $this->assertSame('b', $id, 'the save waited for the lock and succeeded');
        $this->assertGreaterThan(0.5, $waited);
        $this->assertLessThan(SqliteDialect::BUSY_TIMEOUT_MILLISECONDS / 1000, $waited);
    }

    public function testConcurrentWritersDoNotFail(): void
    {
        // Two processes (as two web requests would be) each save 200 records to the same file at
        // the same time: every save waits for the other's write lock and succeeds.
        $go = $this->dir . '/go';
        $processes = [];
        foreach (['first', 'second'] as $prefix) {
            $process = proc_open([PHP_BINARY, '-d', 'error_reporting=22527', __DIR__ . '/sqlite_writer.php', $this->file, $prefix, '200', $go], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            $processes[$prefix] = [$process, $pipes];
        }
        touch($go);

        foreach ($processes as $prefix => [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), 'writer ' . $prefix . ' failed: ' . $output);
        }

        $store = self::store($this->connect());
        $this->assertSame(400, $store->count('entities', Criteria::all()));
        $this->assertSame('record 200', $store->findById('entities', 'first-200')['title'] ?? null);
        $this->assertSame('record 200', $store->findById('entities', 'second-200')['title'] ?? null);
    }

    public function testAWriteThatCannotGetTheLockWithinTheBusyTimeoutIsNotStored(): void
    {
        $holder = $this->connect();
        $holder->executeStatement('begin immediate');
        $store = self::store($this->connect());

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
