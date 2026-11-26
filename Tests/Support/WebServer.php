<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A `php -S` web server a test started, with the processes it forks (PHP_CLI_SERVER_WORKERS): the one place tests start and
 * stop one.
 *
 * What it gives every server, so that no test server shares state with another process of the machine:
 *  - a session directory of its own (`session.save_path`: PHP's default is a shared system directory, which holds every test
 *    session of every checkout, and PHP's garbage collection of it, run by one request in a thousand, stats every file in it);
 *  - the temporary directory of the PHPUnit process (`TMPDIR`), so that what the server writes under it (a test's mail log, a
 *    sink) is where the test reads it;
 *  - the PIDs of its workers, recorded once it listens, so that stop() ends them all, also when the master process has died
 *    and they have been handed to init. Only a recorded PID is ever signalled, and only while its command line is still this
 *    server's (`-S 127.0.0.1:<port>`): a reused PID is left alone.
 */
final class WebServer
{
    /** Seconds stop() waits for a process to end after SIGTERM before it sends SIGKILL. */
    private const STOP_SECONDS = 5;

    /**
     * @param resource  $process
     * @param list<int> $workers the PIDs of the workers, recorded when the server listened
     */
    private function __construct(private $process, private readonly int $master, private readonly int $port, private array $workers)
    {
    }

    /**
     * Starts `php -S 127.0.0.1:<port> <router>` and waits until it listens.
     *
     * @param array<string, string> $environment  the server's environment (nothing else is inherited); TMPDIR is added
     * @param array<string, string> $ini          PHP settings (`-d name=value`); `session.save_path` is $sessionDirectory unless named here
     * @param string                $sessionDirectory created if it is not there
     * @param int                   $workers          PHP_CLI_SERVER_WORKERS (one is a server with no workers)
     *
     * @throws \RuntimeException when the server does not listen in time (it is stopped first)
     */
    public static function launch(string $router, int $port, array $environment, array $ini, string $cwd, string $log, string $sessionDirectory, int $workers = 4, float $startSeconds = 20.0): self
    {
        if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
            throw new \RuntimeException('The session directory could not be made.');
        }
        $ini += ['error_reporting' => '22527', 'session.save_path' => $sessionDirectory];
        $command = [PHP_BINARY];
        foreach ($ini as $name => $value) {
            array_push($command, '-d', $name . '=' . $value);
        }
        array_push($command, '-S', '127.0.0.1:' . $port, $router);
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $cwd,
            $environment + ['TMPDIR' => sys_get_temp_dir()] + ($workers > 1 ? ['PHP_CLI_SERVER_WORKERS' => (string) $workers] : []),
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('The web server could not be started.');
        }
        $server = new self($process, (int) proc_get_status($process)['pid'], $port, []);
        $deadline = microtime(true) + $startSeconds;
        while (microtime(true) < $deadline) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);
                $server->recordWorkers($workers > 1 ? $workers : 0);

                return $server;
            }
            usleep(50_000);
        }
        $server->stop();

        throw new \RuntimeException('The web server did not start.');
    }

    /**
     * The `-d` arguments that give a command-line process (bin/console, a script that boots the site) a session directory of its own,
     * made if it is not there: the legacy application starts a session in every process, and PHP's default directory is shared.
     *
     * @return list<string>
     */
    public static function sessionArguments(string $directory): array
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('The session directory could not be made.');
        }

        return ['-d', 'session.save_path=' . $directory];
    }

    /**
     * Removes a directory and what is in it (the session directory of a test's server, at its end); nothing when it is not there.
     */
    public static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
    }

    /**
     * The master process's PID.
     */
    public function pid(): int
    {
        return $this->master;
    }

    /**
     * @return list<int> the workers recorded when the server listened
     */
    public function workers(): array
    {
        return $this->workers;
    }

    /**
     * Ends the master and every worker recorded (and any child the master has now): SIGTERM, a bounded wait, then SIGKILL.
     * Safe to call twice.
     */
    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        $master = $this->master;
        $pids = $this->workers;
        if (proc_get_status($this->process)['running']) {
            $pids = array_values(array_unique(array_merge($pids, self::children($master))));
        }
        $this->workers = $pids;
        foreach (array_merge([$master], $pids) as $pid) {
            $this->signal($pid, 'TERM');
        }
        $deadline = microtime(true) + self::STOP_SECONDS;
        while (microtime(true) < $deadline && $this->anyAlive($master, $pids)) {
            usleep(50_000);
        }
        foreach (array_merge([$master], $pids) as $pid) {
            $this->signal($pid, 'KILL');
        }
        $deadline = microtime(true) + self::STOP_SECONDS;
        while (microtime(true) < $deadline && $this->anyAlive($master, $pids)) {
            usleep(20_000);
        }
        proc_close($this->process);
        $this->process = null;
    }

    /**
     * Whether a recorded PID is still this server's live process (the master or a worker).
     */
    public function isRunning(int $pid): bool
    {
        if ($pid === $this->master && is_resource($this->process)) {
            return (bool) proc_get_status($this->process)['running'];
        }

        return $this->isOurs($pid);
    }

    /**
     * @param list<int> $workers
     */
    private function anyAlive(int $master, array $workers): bool
    {
        if (proc_get_status($this->process)['running']) {
            return true;
        }
        foreach ($workers as $pid) {
            if ($this->isOurs($pid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Records the workers' PIDs: the master's children, once there are as many as were asked for (or after two seconds).
     */
    private function recordWorkers(int $expected): void
    {
        $deadline = microtime(true) + 2;
        do {
            $children = self::children($this->master);
            if (count($children) >= $expected) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        $this->workers = $children;
    }

    /**
     * @return list<int>
     */
    private static function children(int $pid): array
    {
        $output = [];
        exec('pgrep -P ' . $pid . ' 2>/dev/null', $output);

        return array_values(array_map('intval', array_filter($output, 'ctype_digit')));
    }

    /**
     * Whether the PID is a live process whose command line is this server's (a zombie shows no such line).
     */
    private function isOurs(int $pid): bool
    {
        $command = [];
        exec('ps -p ' . $pid . ' -o command= 2>/dev/null', $command);

        return str_contains(implode(' ', $command), '-S 127.0.0.1:' . $this->port . ' ');
    }

    private function signal(int $pid, string $signal): void
    {
        if ($pid > 0 && ($pid === $this->master || $this->isOurs($pid))) {
            exec('kill -' . $signal . ' ' . $pid . ' 2>/dev/null');
        }
    }
}
