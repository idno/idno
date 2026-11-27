<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * Test-environment only: a session save handler that keeps each session in a file of its own directory and records every call made
 * to it in `calls.log` there (`<call>:<session id>` a line), for the tests of what a handler a plugin installs sees and does.
 */
final class RecordingSessionHandler implements \SessionHandlerInterface
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('The handler directory could not be made.');
        }
    }

    public function open(string $path, string $name): bool
    {
        $this->record('open', $name);

        return true;
    }

    public function close(): bool
    {
        $this->record('close', '');

        return true;
    }

    public function read(string $id): string|false
    {
        $this->record('read', $id);
        $file = $this->file($id);

        return is_file($file) ? (string) file_get_contents($file) : '';
    }

    public function write(string $id, string $data): bool
    {
        $this->record('write', $id);

        return file_put_contents($this->file($id), $data) !== false;
    }

    public function destroy(string $id): bool
    {
        $this->record('destroy', $id);
        if (is_file($this->file($id))) {
            unlink($this->file($id));
        }

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    /** @return list<string> the calls made so far */
    public function calls(): array
    {
        $log = $this->directory . '/calls.log';

        return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
    }

    private function file(string $id): string
    {
        return $this->directory . '/sess_' . preg_replace('/[^A-Za-z0-9,-]/', '', $id);
    }

    private function record(string $call, string $subject): void
    {
        file_put_contents($this->directory . '/calls.log', $call . ':' . $subject . "\n", FILE_APPEND | LOCK_EX);
    }
}
