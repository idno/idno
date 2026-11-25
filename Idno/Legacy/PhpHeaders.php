<?php

declare(strict_types=1);

namespace Idno\Legacy;

/**
 * PHP's own header list (`headers_list()`, `header_remove()`, `header()`), where the SAPI keeps one: nothing under the command line,
 * and nothing once the output has begun.
 */
final class PhpHeaders implements HeaderQueue
{
    public function listed(): bool
    {
        return !in_array(PHP_SAPI, ['cli', 'phpdbg'], true) && !headers_sent();
    }

    public function queued(): array
    {
        return $this->listed() ? array_values(headers_list()) : [];
    }

    public function remove(string $name): void
    {
        if ($this->listed()) {
            header_remove($name);
        }
    }

    public function send(string $line, bool $replace): void
    {
        if ($this->listed()) {
            header($line, $replace);
        }
    }
}
