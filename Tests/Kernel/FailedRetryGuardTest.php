<?php

declare(strict_types=1);

namespace Tests\Kernel;

use Doctrine\DBAL\Connection;
use Idno\Core\Idno;
use Idno\Queue\QueuedEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * `messenger:failed:retry` runs events again, so it is guarded as the worker is: with no id it would loop
 * over the failure transport until nothing is available (and take back an event it put back after a
 * pause), and without the site's address it would sign what it sends with a guessed http url. Run as
 * subprocesses against the rig's site.
 */
final class FailedRetryGuardTest extends TestCase
{
    private function connection(): Connection
    {
        return Idno::container()->get('database_connection');
    }

    protected function setUp(): void
    {
        $this->connection()->executeStatement("delete from idno_messages where queue_name = 'failed'");
    }

    protected function tearDown(): void
    {
        $this->connection()->executeStatement("delete from idno_messages where queue_name = 'failed'");
    }

    /** A failed event that fails again when retried: it runs as a user that does not exist. */
    private function failedEvent(): int
    {
        $encoded = (new PhpSerializer())->encode(new Envelope(new QueuedEvent('idno', 'queue/test/retry', [], 'https://example.com/user/nobody-' . bin2hex(random_bytes(4)))));
        $this->connection()->executeStatement(
            "insert into idno_messages (body, headers, queue_name, created_at, available_at, delivered_at) values (?, ?, 'failed', '2026-01-01 00:00:00', '2026-01-01 00:00:00', null)",
            [$encoded['body'], json_encode($encoded['headers'] ?? []) ?: '{}'],
        );

        return (int) $this->connection()->lastInsertId();
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function console(array $arguments, array $env = []): array
    {
        $root = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, '-d', 'error_reporting=22527', '-d', 'display_errors=stderr', $root . '/bin/console', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $env + ['APP_ENV' => 'test', 'APP_DEBUG' => '1', 'IDNO_DOMAIN' => '127.0.0.1', 'IDNO_PORT' => '8089', 'PATH' => (string) getenv('PATH')],
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    private function rows(): int
    {
        return (int) $this->connection()->fetchOne("select count(*) from idno_messages where queue_name = 'failed'");
    }

    public function testWithNoIdItIsRefusedAndNothingIsTaken(): void
    {
        $id = $this->failedEvent();

        [$exit, $stdout, $stderr] = $this->console(['messenger:failed:retry', '--force']);

        $this->assertSame(1, $exit, $stdout . $stderr);
        $this->assertStringContainsString('messenger:failed:show', $stderr);
        $this->assertStringContainsString('id', $stderr);
        $this->assertSame(1, $this->rows());
        $this->assertSame(1, (int) $this->connection()->fetchOne('select count(*) from idno_messages where id = ? and delivered_at is null', [$id]), 'nothing was taken');
    }

    public function testWithAnIdItRunsThatMessageOnceAndAnEventThatFailsAgainIsKeptOnceUnderANewId(): void
    {
        $id = $this->failedEvent();

        [$exit, $stdout, $stderr] = $this->console(['messenger:failed:retry', (string) $id, '--force']);

        $this->assertSame(0, $exit, $stdout . $stderr);
        $this->assertSame(1, $this->rows(), 'exactly one row afterwards: the original is gone, the retried copy is kept');
        $remaining = (int) $this->connection()->fetchOne("select id from idno_messages where queue_name = 'failed'");
        $this->assertNotSame($id, $remaining, 'under a new id');
        $this->assertStringContainsString('Invalid user', (string) $this->connection()->fetchOne('select body from idno_messages where id = ?', [$remaining]), 'with the error of the retry');
    }

    public function testWithoutAnExplicitUrlItIsRefusedWithTheWorkersMessageAndNothingIsTaken(): void
    {
        $id = $this->failedEvent();

        [$exit, $stdout, $stderr] = $this->console(['messenger:failed:retry', (string) $id, '--force'], ['IDNO_URL' => 'example.com/']);

        $this->assertSame(1, $exit, $stdout . $stderr);
        $this->assertStringContainsString('url = "https://example.com/"', $stderr);
        $this->assertStringContainsString('IDNO_URL=https://example.com/', $stderr);
        $this->assertSame(1, (int) $this->connection()->fetchOne('select count(*) from idno_messages where id = ? and delivered_at is null', [$id]), 'nothing was taken');
    }
}
