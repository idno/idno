<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\WebServer;

/**
 * The web servers tests start (Tests\Support\WebServer): each has a session directory of its own and the temporary directory of the
 * test's process, and stop() ends the master and every worker, also when the master has died.
 */
final class WebServerTest extends TestCase
{
    private string $directory = '';

    /** @var list<WebServer> */
    private array $servers = [];

    protected function setUp(): void
    {
        $this->directory = (string) realpath(sys_get_temp_dir()) . '/idno-webserver-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory . '/router.php', <<<'PHP'
            <?php
            if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
            header('Content-Type: application/json');
            echo json_encode(['save_path' => ini_get('session.save_path'), 'tmp' => sys_get_temp_dir(), 'tmpdir' => getenv('TMPDIR'), 'gc' => ini_get('error_reporting')]);
            PHP);
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    private function start(array $ini = [], int $workers = 3): WebServer
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $port = (int) substr((string) stream_socket_get_name($socket, false), (int) strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        $server = WebServer::launch($this->directory . '/router.php', $port, ['PATH' => (string) getenv('PATH')], $ini, $this->directory, $this->directory . '/server.log', $this->directory . '/sessions', $workers);
        $this->servers[] = $server;
        $this->ports[spl_object_id($server)] = $port;

        return $server;
    }

    /** @var array<int, int> */
    private array $ports = [];

    /**
     * @return array<string, mixed>
     */
    private function ask(WebServer $server): array
    {
        $body = (string) file_get_contents('http://127.0.0.1:' . $this->ports[spl_object_id($server)] . '/');
        $this->assertJson($body);

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function alive(int $pid): bool
    {
        return trim((string) shell_exec('ps -p ' . $pid . ' -o stat= 2>/dev/null')) !== '' && !str_starts_with(trim((string) shell_exec('ps -p ' . $pid . ' -o stat= 2>/dev/null')), 'Z');
    }

    public function testAServerKeepsItsSessionsInADirectoryOfItsOwn(): void
    {
        $answer = $this->ask($this->start());

        $this->assertSame($this->directory . '/sessions', $answer['save_path']);
        $this->assertDirectoryExists($this->directory . '/sessions');
    }

    public function testASessionDirectoryTheCallerNamesWins(): void
    {
        $answer = $this->ask($this->start(['session.save_path' => $this->directory]));

        $this->assertSame($this->directory, $answer['save_path']);
    }

    public function testAServerHasTheTemporaryDirectoryOfTheProcessThatStartedIt(): void
    {
        $answer = $this->ask($this->start());

        $this->assertSame(sys_get_temp_dir(), $answer['tmp']);
        $this->assertSame(sys_get_temp_dir(), $answer['tmpdir']);
    }

    public function testStopEndsTheMasterAndEveryWorker(): void
    {
        $server = $this->start();
        $workers = $server->workers();
        $this->assertCount(3, $workers, 'the workers were recorded when the server listened');
        $this->assertTrue($server->isRunning($server->pid()));

        $server->stop();

        foreach ([$server->pid(), ...$workers] as $pid) {
            $this->assertFalse(self::alive($pid), 'process ' . $pid . ' ended');
        }
        $server->stop();
    }

    public function testStopEndsTheWorkersOfAMasterThatHasAlreadyDied(): void
    {
        $server = $this->start();
        $workers = $server->workers();
        $this->assertCount(3, $workers);

        exec('kill -KILL ' . $server->pid());
        $deadline = microtime(true) + 5;
        while ($server->isRunning($server->pid()) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        $this->assertFalse($server->isRunning($server->pid()), 'the master is dead');
        $this->assertTrue(array_reduce($workers, fn (bool $any, int $pid): bool => $any || self::alive($pid), false), 'and its workers keep listening');

        $server->stop();

        foreach ($workers as $pid) {
            $this->assertFalse(self::alive($pid), 'worker ' . $pid . ' ended');
        }
    }
}
