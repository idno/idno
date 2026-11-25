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

    public function status(): int|false
    {
        return http_response_code();
    }

    public function setStatus(int $code): void
    {
        if (!headers_sent()) {
            http_response_code($code);
        }
    }

    public function unsetStatus(): void
    {
        if (headers_sent()) {
            return;
        }
        if (in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            header('HTTP/1.1 0');
        } else {
            http_response_code(200);
        }
    }

    public function defaultContentType(): ?string
    {
        $type = (string) ini_get('default_mimetype');
        if ($type === '') {
            return null;
        }
        $charset = (string) ini_get('default_charset');

        return $charset !== '' && str_starts_with($type, 'text/') ? $type . '; charset=' . $charset : $type;
    }
}
